#!/bin/bash
##############################################################################
# AWS EC2 User Data Script for Stateless Laravel App Server
# This script bootstraps a new instance for auto-scaling
##############################################################################

set -e

# Variables from Terraform
APP_NAME="${app_name}"
ENVIRONMENT="${environment}"
SSM_PREFIX="${ssm_prefix}"

# Logging
exec > >(tee /var/log/user-data.log|logger -t user-data -s 2>/dev/console) 2>&1
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
    aws-cli \
    jq

##############################################################################
# Fetch Configuration from SSM Parameter Store (Stateless)
##############################################################################
echo "Fetching configuration from SSM..."

# Fetch all parameters for this environment
aws ssm get-parameters-by-path \
    --path "$SSM_PREFIX" \
    --with-decryption \
    --recursive \
    --query "Parameters[*].[Name,Value]" \
    --output text | while read -r name value; do
    # Convert SSM path to env var name
    # e.g., /production/absensi-api/DB_HOST -> DB_HOST
    key=$(echo "$name" | sed "s|$SSM_PREFIX/||")
    echo "$key=$value" >> /var/www/html/.env
done

# Add instance metadata
INSTANCE_ID=$(curl -s http://169.254.169.254/latest/meta-data/instance-id)
AVAILABILITY_ZONE=$(curl -s http://169.254.169.254/latest/meta-data/placement/availability-zone)

cat >> /var/www/html/.env << EOF
INSTANCE_ID=$INSTANCE_ID
AVAILABILITY_ZONE=$AVAILABILITY_ZONE
LOG_CHANNEL=stderr
SESSION_DRIVER=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
EOF

##############################################################################
# Deploy Application (Pull from S3 or ECR)
##############################################################################
echo "Deploying application..."

# Option 1: Pull from S3
aws s3 sync s3://absensi-deployments/$ENVIRONMENT/latest /var/www/html \
    --exclude ".env" \
    --delete

# Option 2: Pull from CodeDeploy artifact (if using CodeDeploy)
# The deployment is handled by CodeDeploy agent

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

    # Deny hidden files
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
# Laravel Optimization (Stateless)
##############################################################################
cd /var/www/html

# Clear and cache (stateless - no file cache, use Redis)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run migrations (only if leader instance)
# Use DynamoDB lock to ensure only one instance runs migrations
if aws dynamodb put-item \
    --table-name "absensi-deployment-lock" \
    --item '{"LockKey": {"S": "migration-lock"}, "InstanceId": {"S": "'$INSTANCE_ID'"}, "TTL": {"N": "'$(($(date +%s) + 300))'"}}' \
    --condition-expression "attribute_not_exists(LockKey)" 2>/dev/null; then
    echo "Running migrations..."
    php artisan migrate --force
fi

##############################################################################
# Start Services
##############################################################################
systemctl restart php8.2-fpm
systemctl restart nginx

##############################################################################
# Signal Health to Load Balancer
##############################################################################
echo "Bootstrap complete at $(date)"

# Wait for services to be ready
sleep 5

# Test health endpoint
curl -f http://localhost:9000/health || exit 1

echo "Instance ready for traffic"
