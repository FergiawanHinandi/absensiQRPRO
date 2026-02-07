#!/bin/bash
##############################################################################
# GCP Startup Script for Stateless Laravel App Server
# This script bootstraps a new instance for auto-scaling
##############################################################################

set -e

# Variables from Terraform
APP_NAME="${app_name}"
ENVIRONMENT="${environment}"
PROJECT_ID="${project_id}"

# Logging
exec > >(tee /var/log/startup-script.log | logger -t startup-script -s 2>/dev/console) 2>&1
echo "Starting bootstrap at $(date)"

##############################################################################
# Install Dependencies
##############################################################################
apt-get update
apt-get install -y \
    nginx \
    php8.2-fpm \
    php8.2-cli \
    php8.2-pgsql \
    php8.2-redis \
    php8.2-curl \
    php8.2-gd \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-zip \
    jq

##############################################################################
# Fetch Secrets from Secret Manager (Stateless)
##############################################################################
echo "Fetching secrets from Secret Manager..."

# Install gcloud if not present
if ! command -v gcloud &> /dev/null; then
    curl https://sdk.cloud.google.com | bash -s -- --disable-prompts
    source /root/google-cloud-sdk/path.bash.inc
fi

# Get secrets and write to .env
SECRETS=$(gcloud secrets list --filter="labels.app=$APP_NAME AND labels.environment=$ENVIRONMENT" --format="value(name)")

for secret_name in $SECRETS; do
    # Get secret value
    secret_value=$(gcloud secrets versions access latest --secret="$secret_name")
    # Convert secret name to env var (e.g., absensi-api-production-db-host -> DB_HOST)
    env_key=$(echo "$secret_name" | sed "s/$APP_NAME-$ENVIRONMENT-//" | tr '[:lower:]-' '[:upper:]_')
    echo "$env_key=$secret_value" >> /var/www/html/.env
done

# Add instance metadata
INSTANCE_NAME=$(curl -s "http://metadata.google.internal/computeMetadata/v1/instance/name" -H "Metadata-Flavor: Google")
ZONE=$(curl -s "http://metadata.google.internal/computeMetadata/v1/instance/zone" -H "Metadata-Flavor: Google" | cut -d'/' -f4)

cat >> /var/www/html/.env << EOF
INSTANCE_NAME=$INSTANCE_NAME
ZONE=$ZONE
LOG_CHANNEL=stderr
SESSION_DRIVER=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
EOF

##############################################################################
# Deploy Application (Pull from GCS)
##############################################################################
echo "Deploying application..."

gsutil -m rsync -r -d gs://absensi-deployments/$ENVIRONMENT/latest /var/www/html \
    -x ".env"

##############################################################################
# Configure PHP-FPM
##############################################################################
cat > /etc/php/8.2/fpm/pool.d/www.conf << 'EOF'
[www]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 20
pm.max_requests = 500
pm.status_path = /fpm-status
ping.path = /fpm-ping
EOF

##############################################################################
# Configure Nginx
##############################################################################
cat > /etc/nginx/sites-available/default << 'EOF'
server {
    listen 9000;
    server_name _;
    root /var/www/html/public;
    index index.php;

    # Health check endpoints
    location /health {
        access_log off;
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /health/load-balancer {
        access_log off;
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Main application
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\. {
        deny all;
    }
}
EOF

##############################################################################
# Set Permissions
##############################################################################
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html/storage
chmod -R 755 /var/www/html/bootstrap/cache

##############################################################################
# Laravel Optimization
##############################################################################
cd /var/www/html

php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations with distributed lock (using Firestore)
LOCK_DOC="projects/$PROJECT_ID/databases/(default)/documents/deployment-locks/$APP_NAME-migration"

# Try to acquire lock
if curl -s -X PATCH \
    -H "Authorization: Bearer $(gcloud auth print-access-token)" \
    -H "Content-Type: application/json" \
    -d '{"fields":{"instance":{"stringValue":"'$INSTANCE_NAME'"},"timestamp":{"timestampValue":"'$(date -u +%Y-%m-%dT%H:%M:%SZ)'"}}}' \
    "https://firestore.googleapis.com/v1/$LOCK_DOC?currentDocument.exists=false" > /dev/null 2>&1; then
    echo "Running migrations..."
    php artisan migrate --force
    # Release lock after 5 minutes via TTL
fi

##############################################################################
# Start Services
##############################################################################
systemctl restart php8.2-fpm
systemctl restart nginx

##############################################################################
# Signal Health
##############################################################################
echo "Bootstrap complete at $(date)"
sleep 5

curl -f http://localhost:9000/health || exit 1
echo "Instance ready for traffic"
