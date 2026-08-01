#!/bin/bash
# =============================================================================
# Server Setup Script untuk AbsensiQRPro
# Usage: ./deploy/setup-server.sh [domain] [deploy_user]
# =============================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Check arguments
if [ -z "$1" ] || [ -z "$2" ]; then
    echo -e "${RED}[ERROR]${NC} Parameter tidak lengkap!"
    echo "Usage: ./deploy/setup-server.sh [domain] [deploy_user]"
    echo "Example: ./deploy/setup-server.sh absensiqr.example.com absensiqr"
    exit 1
fi

DOMAIN=$1
DEPLOY_USER=$2
APP_DIR="/var/www/absensiqrpro"
DB_NAME="absensiqrpro"
DB_USER="absensiqrpro_user"

echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  AbsensiQRPro Server Setup"
echo -e "${GREEN}[INFO]${NC}=========================================="
echo -e "${GREEN}[INFO]${NC}  Domain: $DOMAIN"
echo -e "${GREEN}[INFO]${NC}  User: $DEPLOY_USER"
echo

# Generate random password
DB_PASSWORD=$(openssl rand -base64 32 | tr -dc 'a-zA-Z0-9' | head -c 32)

# Step 1: Update system
echo -e "${GREEN}[STEP 1/12]${NC} Updating system..."
sudo apt update && sudo apt upgrade -y

# Step 2: Install required packages
echo -e "${GREEN}[STEP 2/12]${NC} Installing required packages..."
sudo apt install -y \
    nginx \
    postgresql \
    postgresql-contrib \
    redis-server \
    supervisor \
    certbot \
    python3-certbot-nginx \
    php8.2-fpm \
    php8.2-cli \
    php8.2-pgsql \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-curl \
    php8.2-zip \
    php8.2-gd \
    php8.2-bcmath \
    php8.2-intl \
    php8.2-redis \
    php8.2-opcache \
    git \
    curl \
    unzip \
    zip \
    mailutils

# Step 3: Configure PHP
echo -e "${GREEN}[STEP 3/12]${NC} Configuring PHP..."
sudo sed -i 's/upload_max_filesize = .*/upload_max_filesize = 10M/' /etc/php/8.2/fpm/php.ini
sudo sed -i 's/post_max_size = .*/post_max_size = 10M/' /etc/php/8.2/fpm/php.ini
sudo sed -i 's/max_execution_time = .*/max_execution_time = 60/' /etc/php/8.2/fpm/php.ini
sudo sed -i 's/memory_limit = .*/memory_limit = 256M/' /etc/php/8.2/fpm/php.ini

# Enable OPcache
cat << EOF | sudo tee /etc/php/8.2/fpm/conf.d/99-opcache.ini
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=0
opcache.validate_timestamps=0
opcache.save_comments=1
opcache.fast_shutdown=1
EOF

# Step 4: Configure PostgreSQL
echo -e "${GREEN}[STEP 4/12]${NC} Configuring PostgreSQL..."
sudo -u postgres psql -c "CREATE DATABASE $DB_NAME;"
sudo -u postgres psql -c "CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;"
sudo -u postgres psql -c "ALTER USER $DB_USER CREATEDB;"
sudo -u postgres psql -c "ALTER DATABASE $DB_NAME OWNER TO $DB_USER;"

# Step 5: Configure Redis
echo -e "${GREEN}[STEP 5/12]${NC} Configuring Redis..."
sudo sed -i 's/^# requirepass .*/requirepass $(openssl rand -base64 32)/' /etc/redis/redis.conf
sudo systemctl restart redis-server

# Step 6: Create application directory
echo -e "${GREEN}[STEP 6/12]${NC} Creating application directory..."
sudo mkdir -p $APP_DIR
sudo chown -R $DEPLOY_USER:$DEPLOY_USER $APP_DIR

# Step 7: Clone repository
echo -e "${GREEN}[STEP 7/12]${NC} Cloning repository..."
cd $APP_DIR
sudo -u $DEPLOY_USER git clone https://github.com/[YOUR_REPO]/absensiqrpro.git .

# Step 8: Setup environment
echo -e "${GREEN}[STEP 8/12]${NC} Setting up environment..."
sudo -u $DEPLOY_USER cp .env.production.template .env
sudo -u $DEPLOY_USER php artisan key:generate

# Update .env with generated values
sudo sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD/" .env
sudo sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
sudo sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env
sudo sed -i "s|APP_URL=.*|APP_URL=https://$DOMAIN|" .env
sudo sed -i "s|FRONTEND_URL=.*|FRONTEND_URL=https://$DOMAIN|" .env

# Step 9: Install dependencies
echo -e "${GREEN}[STEP 9/12]${NC} Installing dependencies..."
sudo -u $DEPLOY_USER composer install --no-dev --optimize-autoloader --no-interaction
sudo -u $DEPLOY_USER npm install --prefix frontend-web
sudo -u $DEPLOY_USER npm run build --prefix frontend-web

# Step 10: Run migrations and seeders
echo -e "${GREEN}[STEP 10/12]${NC} Running migrations..."
sudo -u $DEPLOY_USER php artisan migrate --force
sudo -u $DEPLOY_USER php artisan db:seed --class=DatabaseSeeder --force

# Step 11: Configure Nginx
echo -e "${GREEN}[STEP 11/12]${NC} Configuring Nginx..."
cat << EOF | sudo tee /etc/nginx/sites-available/$DOMAIN
server {
    listen 80;
    server_name $DOMAIN www.$DOMAIN;
    return 301 https://\$server_name\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name $DOMAIN www.$DOMAIN;

    root $APP_DIR/public;
    index index.php index.html;

    # SSL will be configured by certbot
    ssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;

    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

    # Gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    location /storage/ {
        alias $APP_DIR/storage/app/public/;
        try_files \$uri \$uri/ =404;
    }

    location /health {
        access_log off;
        add_header Content-Type text/plain;
        return 200 "OK\n";
    }

    location ~ /\. {
        deny all;
    }
}
EOF

sudo ln -sf /etc/nginx/sites-available/$DOMAIN /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart nginx

# Step 12: Configure Supervisor
echo -e "${GREEN}[STEP 12/12]${NC} Configuring Supervisor..."
cat << EOF | sudo tee /etc/supervisor/conf.d/absensiqrpro.conf
[program:absensiqrpro-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $APP_DIR/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=$DEPLOY_USER
numprocs=2
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/worker.log
stopwaitsecs=3600
EOF

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start absensiqrpro-worker:*

# Setup SSL
echo -e "${GREEN}[INFO]${NC} Setting up SSL..."
sudo certbot --nginx -d $DOMAIN -d www.$DOMAIN --non-interactive --agree-tos --email admin@$DOMAIN

# Setup cron
echo -e "${GREEN}[INFO]${NC} Setting up cron..."
(sudo -u $DEPLOY_USER crontab -l 2>/dev/null; echo "* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1") | sudo -u $DEPLOY_USER crontab -

# Cache configuration
echo -e "${GREEN}[INFO]${NC} Caching configuration..."
cd $APP_DIR
sudo -u $DEPLOY_USER php artisan config:cache
sudo -u $DEPLOY_USER php artisan route:cache
sudo -u $DEPLOY_USER php artisan view:cache
sudo -u $DEPLOY_USER php artisan optimize

# Final health check
echo -e "${GREEN}[INFO]${NC} Running final health check..."
sleep 5
if curl -s -f https://$DOMAIN/health > /dev/null; then
    echo -e "${GREEN}[INFO]${NC}=========================================="
    echo -e "${GREEN}[INFO]${NC}  ✅ Server setup completed!"
    echo -e "${GREEN}[INFO]${NC}=========================================="
    echo
    echo -e "${YELLOW}[IMPORTANT]${NC} Database password: $DB_PASSWORD"
    echo -e "${YELLOW}[IMPORTANT]${NC} Simpan password ini di tempat yang aman!"
    echo
    echo -e "${GREEN}[INFO]${NC} Application URL: https://$DOMAIN"
    echo -e "${GREEN}[INFO]${NC} Admin credentials: superadmin / password"
else
    echo -e "${RED}[ERROR]${NC} Health check failed! Please check the logs."
fi
