# Production Deployment Guide

**Complete guide for deploying TimeTracker to production environments with high availability and security**

---

## Table of Contents

1. [Pre-deployment Checklist](#pre-deployment-checklist)
2. [Docker Production Setup](#docker-production-setup)
3. [Kubernetes Deployment](#kubernetes-deployment)
4. [Traditional Server Deployment](#traditional-server-deployment)
5. [Database Setup & Migration](#database-setup--migration)
6. [SSL/TLS Configuration](#ssltls-configuration)
7. [Monitoring & Logging](#monitoring--logging)
8. [Backup & Recovery](#backup--recovery)
9. [Security Hardening](#security-hardening)
10. [Performance Optimization](#performance-optimization)
11. [Maintenance Procedures](#maintenance-procedures)

---

## Pre-deployment Checklist

### Infrastructure Requirements

| Component | Minimum | Recommended | Scaling |
|-----------|---------|-------------|---------|
| **CPU** | 2 cores | 4+ cores | Horizontal scaling |
| **Memory** | 4GB | 8GB+ | Add instances |
| **Storage** | 20GB SSD | 100GB+ SSD | Network attached |
| **Network** | 100Mbps | 1Gbps | Load balancer |
| **Database** | MySQL 8.0+ | MariaDB 10.6+ | Cluster |

### Security Prerequisites

```bash
# Generate secure keys before deployment
openssl rand -base64 32 > app_secret.key
openssl rand -base64 32 > encryption.key

# Generate JWT key pair
openssl genrsa -out jwt_private.pem 4096
openssl rsa -in jwt_private.pem -pubout -out jwt_public.pem

# Generate JIRA OAuth keys (if needed)
openssl genrsa -out jira_private.pem 1024
openssl req -newkey rsa:1024 -x509 -key jira_private.pem -out jira_public.cer -days 365
```

### Pre-flight Validation

```bash
# Validate configuration
php bin/console app:deployment:validate

# Check dependencies
composer validate --strict
npm audit --audit-level moderate

# Test database connection
php bin/console doctrine:schema:validate --env=prod

# Security scan
composer audit
npm audit
```

---

## Docker Production Setup

### Production Docker Compose

```yaml
# docker-compose.prod.yml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
      target: production
    image: timetracker:latest
    restart: always
    environment:
      APP_ENV: prod
      APP_DEBUG: 0
      DATABASE_URL: mysql://timetracker:${DB_PASSWORD}@db:3306/timetracker
      REDIS_URL: redis://redis:6379/0
      TRUSTED_PROXY_LIST: '["10.0.0.0/8","172.16.0.0/12","192.168.0.0/16"]'
    volumes:
      - app_data:/var/www/html/var
      - app_logs:/var/www/html/var/log
      - ./config/secrets:/var/www/html/config/secrets:ro
    depends_on:
      - db
      - redis
    networks:
      - app_network
    deploy:
      replicas: 2
      update_config:
        parallelism: 1
        delay: 30s
        order: start-first
      restart_policy:
        condition: any
        delay: 5s
        max_attempts: 3
        window: 60s
      resources:
        limits:
          memory: 1G
        reservations:
          memory: 512M

  nginx:
    image: nginx:alpine
    restart: always
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/nginx/prod.conf:/etc/nginx/nginx.conf:ro
      - ./docker/nginx/ssl:/etc/nginx/ssl:ro
      - app_data:/var/www/html/var:ro
    depends_on:
      - app
    networks:
      - app_network
    deploy:
      replicas: 2
      resources:
        limits:
          memory: 256M

  db:
    image: mariadb:10.6
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: timetracker
      MYSQL_USER: timetracker
      MYSQL_PASSWORD: ${DB_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
      - ./docker/mysql/prod.cnf:/etc/mysql/conf.d/prod.cnf:ro
      - ./sql:/docker-entrypoint-initdb.d:ro
    networks:
      - app_network
    deploy:
      replicas: 1
      resources:
        limits:
          memory: 2G
        reservations:
          memory: 1G

  redis:
    image: redis:alpine
    restart: always
    command: redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru
    volumes:
      - redis_data:/data
    networks:
      - app_network
    deploy:
      resources:
        limits:
          memory: 512M

  # Monitoring and maintenance
  monitoring:
    image: prom/prometheus:latest
    restart: always
    ports:
      - "9090:9090"
    volumes:
      - ./docker/prometheus:/etc/prometheus:ro
      - prometheus_data:/prometheus
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.path=/prometheus'
      - '--web.console.libraries=/etc/prometheus/console_libraries'
      - '--web.console.templates=/etc/prometheus/consoles'
    networks:
      - monitoring

volumes:
  app_data:
    driver: local
  app_logs:
    driver: local
  db_data:
    driver: local
  redis_data:
    driver: local
  prometheus_data:
    driver: local

networks:
  app_network:
    driver: bridge
    ipam:
      config:
        - subnet: 172.20.0.0/16
  monitoring:
    driver: bridge
```

### Production Dockerfile

```dockerfile
# Dockerfile - Production stage
FROM php:8.4-fpm-alpine AS production

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    icu-dev \
    libpng-dev \
    libxml2-dev \
    openldap-dev \
    zip \
    unzip \
    curl \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install \
        opcache \
        pdo_mysql \
        ldap \
        intl \
        gd \
    && pecl install apcu \
    && docker-php-ext-enable apcu

# PHP Configuration for production
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/
COPY docker/php/apcu.ini /usr/local/etc/php/conf.d/

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Create application user
RUN adduser -D -s /bin/sh www-data

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY --chown=www-data:www-data . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Install Node.js dependencies and build assets
RUN apk add --no-cache nodejs npm \
    && npm ci --only=production \
    && npm run build \
    && rm -rf node_modules

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/var

# Supervisor configuration
COPY docker/supervisor/prod.conf /etc/supervisor/conf.d/supervisord.conf

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Expose port
EXPOSE 9000

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
```

### Environment Configuration

```env
# .env.prod
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=your-production-secret-key
DATABASE_URL=mysql://timetracker:${DB_PASSWORD}@db:3306/timetracker?charset=utf8mb4
REDIS_URL=redis://redis:6379/0
MAILER_DSN=smtp://smtp.company.com:587?encryption=tls&auth_mode=login&username=timetracker@company.com&password=${SMTP_PASSWORD}

# Security
TRUSTED_PROXY_LIST=["10.0.0.0/8","172.16.0.0/12","192.168.0.0/16"]
CORS_ALLOW_ORIGIN=https://timetracker.company.com
SESSION_COOKIE_SECURE=1
SESSION_COOKIE_SAMESITE=strict

# Performance
OPCACHE_VALIDATE_TIMESTAMPS=0
APCU_ENABLED=1
CACHE_ADAPTER=redis
SESSION_HANDLER_ID=redis

# Monitoring
SENTRY_DSN=https://your-sentry-dsn
PROMETHEUS_METRICS_ENABLED=1
```

### Deployment Script

```bash
#!/bin/bash
# deploy.sh - Production deployment script

set -e

# Configuration
DEPLOY_ENV=${1:-prod}
IMAGE_TAG=${2:-latest}
COMPOSE_FILE="docker-compose.${DEPLOY_ENV}.yml"

echo "🚀 Starting deployment to ${DEPLOY_ENV} environment"

# Pre-deployment checks
echo "📋 Running pre-deployment checks..."
docker --version

# Build and test image
echo "🏗️ Building production image..."
TAG=${IMAGE_TAG} docker bake app

# Run security scan
echo "🔒 Running security scan..."
docker run --rm -v $(pwd):/app securecodewarrior/docker-security-scan:latest /app

# Database backup
echo "💾 Creating database backup..."
docker-compose -f ${COMPOSE_FILE} exec -T db mysqldump -u root -p${DB_ROOT_PASSWORD} timetracker > backup_$(date +%Y%m%d_%H%M%S).sql

# Deploy with rolling update
echo "📦 Deploying application..."
docker-compose -f ${COMPOSE_FILE} up -d --no-deps app

# Wait for health check
echo "🏥 Waiting for health check..."
for i in {1..30}; do
    if curl -f http://localhost/health &>/dev/null; then
        echo "✅ Application is healthy"
        break
    fi
    echo "⏳ Waiting for application to start... (${i}/30)"
    sleep 10
done

# Update reverse proxy
echo "🔄 Updating load balancer..."
docker-compose -f ${COMPOSE_FILE} up -d --no-deps nginx

# Clear application cache
echo "🗑️ Clearing application cache..."
docker-compose -f ${COMPOSE_FILE} exec -T app php bin/console cache:clear --env=prod

# Run database migrations
echo "🗃️ Running database migrations..."
docker-compose -f ${COMPOSE_FILE} exec -T app php bin/console doctrine:migrations:migrate --no-interaction

# Verify deployment
echo "✅ Verifying deployment..."
curl -f https://timetracker.company.com/health || (echo "❌ Health check failed" && exit 1)

# Cleanup old images
echo "🧹 Cleaning up old images..."
docker image prune -f

echo "🎉 Deployment completed successfully!"
```

---

## Kubernetes Deployment

### Namespace and ConfigMap

```yaml
# k8s/namespace.yml
apiVersion: v1
kind: Namespace
metadata:
  name: timetracker
  labels:
    name: timetracker

---
# k8s/configmap.yml
apiVersion: v1
kind: ConfigMap
metadata:
  name: timetracker-config
  namespace: timetracker
data:
  APP_ENV: "prod"
  APP_DEBUG: "0"
  REDIS_URL: "redis://redis-service:6379/0"
  CACHE_ADAPTER: "redis"
  SESSION_HANDLER_ID: "redis"
  TRUSTED_PROXY_LIST: '["10.0.0.0/8","172.16.0.0/12","192.168.0.0/16"]'
```

### Secrets Management

```yaml
# k8s/secrets.yml
apiVersion: v1
kind: Secret
metadata:
  name: timetracker-secrets
  namespace: timetracker
type: Opaque
stringData:
  APP_SECRET: "your-secret-key"
  DATABASE_URL: "mysql://timetracker:password@mysql-service:3306/timetracker"
  JWT_PRIVATE_KEY: |
    -----BEGIN RSA PRIVATE KEY-----
    [Your JWT private key content]
    -----END RSA PRIVATE KEY-----
  JWT_PUBLIC_KEY: |
    -----BEGIN PUBLIC KEY-----
    [Your JWT public key content]  
    -----END PUBLIC KEY-----
```

### Application Deployment

```yaml
# k8s/deployment.yml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: timetracker-app
  namespace: timetracker
  labels:
    app: timetracker
    tier: app
spec:
  replicas: 3
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxUnavailable: 1
      maxSurge: 1
  selector:
    matchLabels:
      app: timetracker
      tier: app
  template:
    metadata:
      labels:
        app: timetracker
        tier: app
    spec:
      containers:
      - name: timetracker
        image: timetracker:latest
        imagePullPolicy: Always
        ports:
        - containerPort: 9000
          name: php-fpm
        envFrom:
        - configMapRef:
            name: timetracker-config
        - secretRef:
            name: timetracker-secrets
        livenessProbe:
          httpGet:
            path: /health
            port: 8080
          initialDelaySeconds: 60
          periodSeconds: 30
          timeoutSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready
            port: 8080
          initialDelaySeconds: 30
          periodSeconds: 10
          timeoutSeconds: 5
        resources:
          requests:
            memory: "512Mi"
            cpu: "250m"
          limits:
            memory: "1Gi" 
            cpu: "500m"
        volumeMounts:
        - name: app-storage
          mountPath: /var/www/html/var
        - name: secrets-volume
          mountPath: /var/www/html/config/secrets
          readOnly: true
      volumes:
      - name: app-storage
        persistentVolumeClaim:
          claimName: timetracker-storage
      - name: secrets-volume
        secret:
          secretName: timetracker-secrets

---
# k8s/service.yml  
apiVersion: v1
kind: Service
metadata:
  name: timetracker-service
  namespace: timetracker
spec:
  selector:
    app: timetracker
    tier: app
  ports:
  - port: 80
    targetPort: 9000
    protocol: TCP
  type: ClusterIP
```

### Database Deployment

```yaml
# k8s/mysql.yml
apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: mysql
  namespace: timetracker
spec:
  serviceName: mysql-service
  replicas: 1
  selector:
    matchLabels:
      app: mysql
  template:
    metadata:
      labels:
        app: mysql
    spec:
      containers:
      - name: mysql
        image: mariadb:10.6
        env:
        - name: MYSQL_ROOT_PASSWORD
          valueFrom:
            secretKeyRef:
              name: mysql-secrets
              key: root-password
        - name: MYSQL_DATABASE
          value: timetracker
        - name: MYSQL_USER
          value: timetracker
        - name: MYSQL_PASSWORD
          valueFrom:
            secretKeyRef:
              name: mysql-secrets
              key: user-password
        ports:
        - containerPort: 3306
          name: mysql
        volumeMounts:
        - name: mysql-storage
          mountPath: /var/lib/mysql
        - name: mysql-config
          mountPath: /etc/mysql/conf.d
        livenessProbe:
          exec:
            command:
            - mysqladmin
            - ping
            - -h
            - localhost
          initialDelaySeconds: 30
          periodSeconds: 10
          timeoutSeconds: 5
        readinessProbe:
          exec:
            command:
            - mysql
            - -h
            - localhost
            - -u
            - root
            - -p${MYSQL_ROOT_PASSWORD}
            - -e
            - "SELECT 1"
          initialDelaySeconds: 10
          periodSeconds: 5
          timeoutSeconds: 3
        resources:
          requests:
            memory: "1Gi"
            cpu: "500m"
          limits:
            memory: "2Gi"
            cpu: "1000m"
      volumes:
      - name: mysql-config
        configMap:
          name: mysql-config
  volumeClaimTemplates:
  - metadata:
      name: mysql-storage
    spec:
      accessModes: ["ReadWriteOnce"]
      resources:
        requests:
          storage: 20Gi

---
apiVersion: v1
kind: Service
metadata:
  name: mysql-service
  namespace: timetracker
spec:
  selector:
    app: mysql
  ports:
  - port: 3306
    targetPort: 3306
  clusterIP: None
```

### Ingress Configuration

```yaml
# k8s/ingress.yml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: timetracker-ingress
  namespace: timetracker
  annotations:
    kubernetes.io/ingress.class: nginx
    cert-manager.io/cluster-issuer: letsencrypt-prod
    nginx.ingress.kubernetes.io/ssl-redirect: "true"
    nginx.ingress.kubernetes.io/proxy-body-size: "50m"
    nginx.ingress.kubernetes.io/rate-limit: "100"
    nginx.ingress.kubernetes.io/rate-limit-window: "1m"
spec:
  tls:
  - hosts:
    - timetracker.company.com
    secretName: timetracker-tls
  rules:
  - host: timetracker.company.com
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: timetracker-service
            port:
              number: 80
```

### Horizontal Pod Autoscaler

```yaml
# k8s/hpa.yml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: timetracker-hpa
  namespace: timetracker
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: timetracker-app
  minReplicas: 3
  maxReplicas: 10
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
  behavior:
    scaleUp:
      stabilizationWindowSeconds: 120
      policies:
      - type: Percent
        value: 50
        periodSeconds: 60
    scaleDown:
      stabilizationWindowSeconds: 300
      policies:
      - type: Percent
        value: 25
        periodSeconds: 120
```

---

## Traditional Server Deployment

### Server Setup (Ubuntu 22.04)

```bash
#!/bin/bash
# server-setup.sh - Production server setup

# Update system
apt update && apt upgrade -y

# Install PHP 8.4 and extensions
apt install -y software-properties-common
add-apt-repository ppa:ondrej/php -y
apt update

apt install -y \
    php8.4-fpm \
    php8.4-cli \
    php8.4-mysql \
    php8.4-ldap \
    php8.4-intl \
    php8.4-gd \
    php8.4-xml \
    php8.4-curl \
    php8.4-zip \
    php8.4-mbstring \
    php8.4-opcache \
    php8.4-apcu

# Install web server and database
apt install -y \
    nginx \
    mariadb-server \
    redis-server \
    supervisor \
    certbot \
    python3-certbot-nginx

# Install Node.js 18
curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
apt install -y nodejs

# Install Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Create application user
useradd -m -s /bin/bash timetracker
usermod -aG www-data timetracker

# Setup application directory
mkdir -p /var/www/timetracker
chown timetracker:www-data /var/www/timetracker
```

### Nginx Configuration

```nginx
# /etc/nginx/sites-available/timetracker
server {
    listen 80;
    server_name timetracker.company.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name timetracker.company.com;
    
    root /var/www/timetracker/public;
    index index.php;

    # SSL Configuration
    ssl_certificate /etc/letsencrypt/live/timetracker.company.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/timetracker.company.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # Security Headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Rate Limiting
    limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
    limit_req_zone $binary_remote_addr zone=api:10m rate=100r/m;

    # Gzip Compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_comp_level 6;
    gzip_types
        text/plain
        text/css
        text/xml
        text/javascript
        application/json
        application/javascript
        application/xml+rss
        application/atom+xml
        image/svg+xml;

    # Main application
    location / {
        try_files $uri /index.php$is_args$args;
    }

    # API endpoints with rate limiting
    location ^~ /api/auth/login {
        limit_req zone=login burst=3 nodelay;
        try_files $uri /index.php$is_args$args;
    }

    location ^~ /api/ {
        limit_req zone=api burst=20 nodelay;
        try_files $uri /index.php$is_args$args;
    }

    # PHP-FPM
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        
        fastcgi_buffer_size 16k;
        fastcgi_buffers 4 16k;
        fastcgi_connect_timeout 300s;
        fastcgi_send_timeout 300s;
        fastcgi_read_timeout 300s;
        
        internal;
    }

    # Static assets with long-term caching
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Health check endpoint
    location /health {
        access_log off;
        return 200 "healthy\n";
        add_header Content-Type text/plain;
    }

    # Deny access to sensitive files
    location ~ ^/(\.env|composer\.(json|lock)|package\.json|\.git) {
        deny all;
        return 404;
    }

    # Logging
    access_log /var/log/nginx/timetracker_access.log;
    error_log /var/log/nginx/timetracker_error.log;
}
```

### PHP-FPM Configuration

```ini
; /etc/php/8.4/fpm/pool.d/timetracker.conf
[timetracker]
user = timetracker
group = www-data

listen = /var/run/php/php8.4-fpm-timetracker.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Process management
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 15
pm.max_requests = 1000

; Performance tuning
request_terminate_timeout = 300s
request_slowlog_timeout = 10s
slowlog = /var/log/php/timetracker-slow.log

; PHP configuration
php_admin_value[memory_limit] = 512M
php_admin_value[max_execution_time] = 300
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 50M

; OPcache settings
php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 256
php_admin_value[opcache.interned_strings_buffer] = 16
php_admin_value[opcache.max_accelerated_files] = 10000
php_admin_value[opcache.validate_timestamps] = 0
php_admin_value[opcache.revalidate_freq] = 0

; APCu settings  
php_admin_value[apc.enabled] = 1
php_admin_value[apc.shm_size] = 128M

; Security
php_admin_value[expose_php] = off
php_admin_value[display_errors] = off
php_admin_value[log_errors] = on
php_admin_value[error_log] = /var/log/php/timetracker-error.log
```

### Systemd Services

```ini
# /etc/systemd/system/timetracker-worker.service
[Unit]
Description=TimeTracker Background Worker
After=network.target mysql.service redis.service

[Service]
Type=simple
User=timetracker
Group=www-data
WorkingDirectory=/var/www/timetracker
ExecStart=/usr/bin/php /var/www/timetracker/bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target

---

# /etc/systemd/system/timetracker-scheduler.service
[Unit]
Description=TimeTracker Scheduler
After=network.target mysql.service

[Service]
Type=simple
User=timetracker
Group=www-data
WorkingDirectory=/var/www/timetracker
ExecStart=/usr/bin/php /var/www/timetracker/bin/console app:scheduler:run
Restart=always
RestartSec=60

[Install]
WantedBy=multi-user.target
```

### Supervisor Configuration

```ini
# /etc/supervisor/conf.d/timetracker.conf
[program:timetracker-worker]
command=/usr/bin/php /var/www/timetracker/bin/console messenger:consume async --time-limit=3600
directory=/var/www/timetracker
user=timetracker
numprocs=4
autostart=true
autorestart=true
startsecs=10
startretries=3
stdout_logfile=/var/log/supervisor/timetracker-worker.log
stderr_logfile=/var/log/supervisor/timetracker-worker-error.log

[program:timetracker-scheduler]
command=/usr/bin/php /var/www/timetracker/bin/console app:scheduler:run
directory=/var/www/timetracker
user=timetracker
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/timetracker-scheduler.log
stderr_logfile=/var/log/supervisor/timetracker-scheduler-error.log
```

---

## Database Setup & Migration

### Database Initialization

```bash
#!/bin/bash
# db-setup.sh - Database initialization script

# Configure MariaDB
mysql_secure_installation

# Create database and user
mysql -u root -p << EOF
CREATE DATABASE timetracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'timetracker'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON timetracker.* TO 'timetracker'@'localhost';
GRANT PROCESS ON *.* TO 'timetracker'@'localhost';
FLUSH PRIVILEGES;
EOF

# Import initial schema
mysql -u timetracker -p timetracker < sql/full.sql

# Run migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Create initial admin user
php bin/console app:user:create admin admin@company.com --role=ROLE_PL
```

### Migration Strategy

```bash
#!/bin/bash
# migrate.sh - Safe migration script

# Create backup
BACKUP_FILE="backup_$(date +%Y%m%d_%H%M%S).sql"
mysqldump -u timetracker -p timetracker > $BACKUP_FILE
echo "Backup created: $BACKUP_FILE"

# Check migration status
php bin/console doctrine:migrations:status

# Dry run migrations
php bin/console doctrine:migrations:migrate --dry-run

# Execute migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Verify schema
php bin/console doctrine:schema:validate

# Update search indexes if needed
php bin/console app:search:reindex

echo "Migration completed successfully"
```

### Database Optimization

```sql
-- /etc/mysql/mariadb.conf.d/99-timetracker.cnf
[mysqld]
# Basic settings
default-storage-engine = InnoDB
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Memory settings
innodb_buffer_pool_size = 2G
innodb_log_buffer_size = 64M
innodb_log_file_size = 512M

# Performance settings
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT
innodb_io_capacity = 1000
innodb_read_io_threads = 8
innodb_write_io_threads = 8

# Connection settings
max_connections = 200
max_connect_errors = 10000
connect_timeout = 60
interactive_timeout = 600
wait_timeout = 600

# Query cache (MySQL 5.7 only)
query_cache_size = 0
query_cache_type = 0

# Logging
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
log_queries_not_using_indexes = 1

# Binary logging
log-bin = mysql-bin
binlog_format = ROW
expire_logs_days = 7
max_binlog_size = 100M

# TimeTracker specific indexes
# These will be created by migrations but listed here for reference:
# CREATE INDEX idx_entries_user_date ON entries (user_id, day);
# CREATE INDEX idx_entries_project_date ON entries (project_id, day);
# CREATE INDEX idx_entries_ticket ON entries (ticket);
```

---

## SSL/TLS Configuration

### Let's Encrypt Setup

```bash
#!/bin/bash
# ssl-setup.sh - SSL certificate setup

# Install Certbot
apt install certbot python3-certbot-nginx -y

# Obtain certificate
certbot --nginx -d timetracker.company.com --email admin@company.com --agree-tos --no-eff-email

# Test renewal
certbot renew --dry-run

# Setup auto-renewal
echo "0 12 * * * /usr/bin/certbot renew --quiet" >> /var/spool/cron/crontabs/root
```

### Custom SSL Certificate

```bash
# For internal CA or purchased certificates

# Install certificate files
cp timetracker.company.com.crt /etc/ssl/certs/
cp timetracker.company.com.key /etc/ssl/private/
cp ca-bundle.crt /etc/ssl/certs/

# Set proper permissions
chmod 644 /etc/ssl/certs/timetracker.company.com.crt
chmod 600 /etc/ssl/private/timetracker.company.com.key
chown root:root /etc/ssl/certs/timetracker.company.com.crt
chown root:root /etc/ssl/private/timetracker.company.com.key
```

### SSL/TLS Best Practices

```nginx
# Enhanced SSL configuration for Nginx
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
ssl_prefer_server_ciphers off;
ssl_session_cache shared:SSL:10m;
ssl_session_timeout 1d;
ssl_session_tickets off;

# OCSP Stapling
ssl_stapling on;
ssl_stapling_verify on;
ssl_trusted_certificate /etc/ssl/certs/ca-bundle.crt;
resolver 8.8.8.8 8.8.4.4 valid=300s;
resolver_timeout 5s;

# HSTS
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
```

---

## Monitoring & Logging

### Prometheus Configuration

```yaml
# docker/prometheus/prometheus.yml
global:
  scrape_interval: 15s
  evaluation_interval: 15s

rule_files:
  - "rules/*.yml"

scrape_configs:
  - job_name: 'timetracker'
    static_configs:
      - targets: ['app:8080']
    scrape_interval: 30s
    metrics_path: '/metrics'
    bearer_token: 'monitoring-secret-token'

  - job_name: 'mysql'
    static_configs:
      - targets: ['mysql-exporter:9104']

  - job_name: 'nginx'
    static_configs:
      - targets: ['nginx-exporter:9113']

  - job_name: 'redis'
    static_configs:
      - targets: ['redis-exporter:9121']

alerting:
  alertmanagers:
    - static_configs:
        - targets:
          - alertmanager:9093
```

### Application Metrics

```php
// src/Metrics/ApplicationMetrics.php
namespace App\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Histogram;
use Prometheus\Gauge;

class ApplicationMetrics
{
    private Counter $requestCounter;
    private Histogram $requestDuration;
    private Counter $databaseQueries;
    private Gauge $activeUsers;
    private Counter $ldapAuthAttempts;
    private Counter $jiraApiCalls;

    public function __construct(CollectorRegistry $registry)
    {
        $this->requestCounter = $registry->getOrRegisterCounter(
            'timetracker',
            'http_requests_total',
            'Total HTTP requests',
            ['method', 'endpoint', 'status_code']
        );

        $this->requestDuration = $registry->getOrRegisterHistogram(
            'timetracker',
            'http_request_duration_seconds',
            'HTTP request duration',
            ['method', 'endpoint'],
            [0.1, 0.25, 0.5, 1, 2.5, 5, 10]
        );

        $this->databaseQueries = $registry->getOrRegisterCounter(
            'timetracker',
            'database_queries_total',
            'Total database queries',
            ['type']
        );

        $this->activeUsers = $registry->getOrRegisterGauge(
            'timetracker',
            'active_users',
            'Currently active users'
        );

        $this->ldapAuthAttempts = $registry->getOrRegisterCounter(
            'timetracker',
            'ldap_auth_attempts_total',
            'LDAP authentication attempts',
            ['result']
        );

        $this->jiraApiCalls = $registry->getOrRegisterCounter(
            'timetracker',
            'jira_api_calls_total',
            'JIRA API calls',
            ['operation', 'status']
        );
    }

    public function incrementRequests(string $method, string $endpoint, int $statusCode): void
    {
        $this->requestCounter->inc([$method, $endpoint, (string)$statusCode]);
    }

    public function observeRequestDuration(string $method, string $endpoint, float $duration): void
    {
        $this->requestDuration->observe($duration, [$method, $endpoint]);
    }
    
    public function incrementDatabaseQueries(string $type = 'select'): void
    {
        $this->databaseQueries->inc([$type]);
    }
}
```

### Grafana Dashboard

```json
{
  "dashboard": {
    "title": "TimeTracker Application Dashboard",
    "panels": [
      {
        "title": "Request Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(timetracker_http_requests_total[5m])",
            "legendFormat": "{{method}} {{endpoint}}"
          }
        ]
      },
      {
        "title": "Response Time",
        "type": "graph", 
        "targets": [
          {
            "expr": "histogram_quantile(0.95, rate(timetracker_http_request_duration_seconds_bucket[5m]))",
            "legendFormat": "95th percentile"
          },
          {
            "expr": "histogram_quantile(0.50, rate(timetracker_http_request_duration_seconds_bucket[5m]))",
            "legendFormat": "Median"
          }
        ]
      },
      {
        "title": "Database Queries",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(timetracker_database_queries_total[5m])",
            "legendFormat": "{{type}}"
          }
        ]
      },
      {
        "title": "Active Users",
        "type": "singlestat",
        "targets": [
          {
            "expr": "timetracker_active_users"
          }
        ]
      }
    ]
  }
}
```

### Log Aggregation

```yaml
# docker/filebeat/filebeat.yml
filebeat.inputs:
- type: log
  enabled: true
  paths:
    - /var/log/timetracker/*.log
  fields:
    service: timetracker
    environment: production
  json.keys_under_root: true
  json.add_error_key: true

- type: log
  enabled: true
  paths:
    - /var/log/nginx/timetracker_*.log
  fields:
    service: nginx
    environment: production

output.elasticsearch:
  hosts: ["elasticsearch:9200"]
  index: "timetracker-logs-%{+yyyy.MM.dd}"

setup.template.name: "timetracker"
setup.template.pattern: "timetracker-*"
setup.kibana.host: "kibana:5601"
```

---

## Backup & Recovery

### Automated Backup Script

```bash
#!/bin/bash
# backup.sh - Automated backup script

set -e

# Configuration
BACKUP_DIR="/opt/backups/timetracker"
DB_NAME="timetracker"
DB_USER="timetracker"
DB_PASSWORD="${DB_PASSWORD:-$(cat /var/www/timetracker/.env | grep DATABASE_URL | cut -d: -f3 | cut -d@ -f1)}"
APP_DIR="/var/www/timetracker"
RETENTION_DAYS=30

# Create backup directory
mkdir -p "${BACKUP_DIR}/$(date +%Y%m%d)"
DAILY_BACKUP_DIR="${BACKUP_DIR}/$(date +%Y%m%d)"

# Database backup
echo "Creating database backup..."
mysqldump -u "${DB_USER}" -p"${DB_PASSWORD}" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    "${DB_NAME}" | gzip > "${DAILY_BACKUP_DIR}/database_$(date +%H%M%S).sql.gz"

# Application files backup
echo "Creating application files backup..."
tar -czf "${DAILY_BACKUP_DIR}/app_files_$(date +%H%M%S).tar.gz" \
    -C "${APP_DIR}" \
    --exclude='var/cache' \
    --exclude='var/log' \
    --exclude='node_modules' \
    --exclude='vendor' \
    .env.local \
    config/ \
    public/uploads/ \
    var/

# User uploads backup
echo "Creating uploads backup..."
if [ -d "${APP_DIR}/public/uploads" ]; then
    tar -czf "${DAILY_BACKUP_DIR}/uploads_$(date +%H%M%S).tar.gz" \
        -C "${APP_DIR}/public" uploads/
fi

# Encryption keys backup
echo "Creating keys backup..."
if [ -d "${APP_DIR}/config/secrets" ]; then
    tar -czf "${DAILY_BACKUP_DIR}/secrets_$(date +%H%M%S).tar.gz" \
        -C "${APP_DIR}/config" secrets/
fi

# Remote backup (optional)
if [ -n "${REMOTE_BACKUP_HOST}" ]; then
    echo "Syncing to remote backup..."
    rsync -avz --delete \
        "${BACKUP_DIR}/" \
        "${REMOTE_BACKUP_USER}@${REMOTE_BACKUP_HOST}:${REMOTE_BACKUP_PATH}/"
fi

# S3 backup (optional)
if [ -n "${AWS_S3_BUCKET}" ]; then
    echo "Uploading to S3..."
    aws s3 sync "${DAILY_BACKUP_DIR}" \
        "s3://${AWS_S3_BUCKET}/timetracker-backups/$(date +%Y%m%d)/"
fi

# Cleanup old backups
echo "Cleaning up old backups..."
find "${BACKUP_DIR}" -type d -name "????????" -mtime +${RETENTION_DAYS} -exec rm -rf {} \;

# Backup verification
echo "Verifying backup integrity..."
for file in "${DAILY_BACKUP_DIR}"/*.gz; do
    if ! gzip -t "${file}"; then
        echo "ERROR: Backup verification failed for ${file}"
        exit 1
    fi
done

echo "Backup completed successfully: ${DAILY_BACKUP_DIR}"

# Send notification
if [ -n "${BACKUP_NOTIFICATION_EMAIL}" ]; then
    echo "Backup completed at $(date)" | \
    mail -s "TimeTracker Backup Success" "${BACKUP_NOTIFICATION_EMAIL}"
fi
```

### Recovery Procedures

```bash
#!/bin/bash
# restore.sh - Database and application restore

set -e

BACKUP_DATE="$1"
BACKUP_DIR="/opt/backups/timetracker/${BACKUP_DATE}"

if [ -z "$BACKUP_DATE" ] || [ ! -d "$BACKUP_DIR" ]; then
    echo "Usage: $0 YYYYMMDD"
    echo "Available backups:"
    ls -1 /opt/backups/timetracker/
    exit 1
fi

echo "🔄 Starting restore from ${BACKUP_DATE}"

# Stop services
echo "⏹️ Stopping services..."
systemctl stop nginx php8.4-fpm timetracker-worker

# Database restore
echo "📦 Restoring database..."
DB_BACKUP=$(ls -1t "${BACKUP_DIR}"/database_*.sql.gz | head -1)
if [ -f "$DB_BACKUP" ]; then
    mysql -u root -p -e "DROP DATABASE IF EXISTS timetracker_restore;"
    mysql -u root -p -e "CREATE DATABASE timetracker_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    gunzip -c "$DB_BACKUP" | mysql -u root -p timetracker_restore
    
    # Backup current database
    mysqldump -u timetracker -p timetracker > "/tmp/timetracker_backup_$(date +%Y%m%d_%H%M%S).sql"
    
    # Replace current database
    mysql -u root -p -e "DROP DATABASE timetracker;"
    mysql -u root -p -e "CREATE DATABASE timetracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -u root -p -e "GRANT ALL PRIVILEGES ON timetracker.* TO 'timetracker'@'localhost';"
    gunzip -c "$DB_BACKUP" | mysql -u root -p timetracker
    
    echo "✅ Database restored successfully"
else
    echo "❌ No database backup found"
    exit 1
fi

# Application files restore
echo "📁 Restoring application files..."
APP_BACKUP=$(ls -1t "${BACKUP_DIR}"/app_files_*.tar.gz | head -1)
if [ -f "$APP_BACKUP" ]; then
    # Backup current files
    tar -czf "/tmp/timetracker_current_$(date +%Y%m%d_%H%M%S).tar.gz" -C /var/www/timetracker .
    
    # Restore files
    tar -xzf "$APP_BACKUP" -C /var/www/timetracker/
    chown -R timetracker:www-data /var/www/timetracker/
    chmod -R 755 /var/www/timetracker/
    
    echo "✅ Application files restored"
else
    echo "❌ No application backup found"
fi

# Uploads restore
UPLOADS_BACKUP=$(ls -1t "${BACKUP_DIR}"/uploads_*.tar.gz 2>/dev/null | head -1 || echo "")
if [ -f "$UPLOADS_BACKUP" ]; then
    tar -xzf "$UPLOADS_BACKUP" -C /var/www/timetracker/public/
    chown -R timetracker:www-data /var/www/timetracker/public/uploads/
    echo "✅ Uploads restored"
fi

# Clear cache
echo "🧹 Clearing cache..."
cd /var/www/timetracker
sudo -u timetracker php bin/console cache:clear --env=prod

# Start services
echo "▶️ Starting services..."
systemctl start php8.4-fpm nginx timetracker-worker

# Verify restore
echo "✅ Verifying restore..."
if curl -f http://localhost/health &>/dev/null; then
    echo "🎉 Restore completed successfully!"
else
    echo "❌ Health check failed - please investigate"
    exit 1
fi
```

### Disaster Recovery Plan

```markdown
# Disaster Recovery Checklist

## Immediate Response (0-15 minutes)
1. ✅ Assess scope of outage
2. ✅ Notify stakeholders
3. ✅ Activate incident response team
4. ✅ Switch to maintenance page if needed

## Service Recovery (15-60 minutes)
1. ✅ Identify root cause
2. ✅ Deploy to backup infrastructure if needed
3. ✅ Restore from latest backup
4. ✅ Verify data integrity
5. ✅ Test critical functionality

## Full Recovery (1-4 hours)
1. ✅ Complete system verification
2. ✅ Performance testing
3. ✅ Switch DNS back to primary
4. ✅ Remove maintenance page
5. ✅ Post-incident communication

## Recovery Targets
- **RTO** (Recovery Time Objective): 4 hours
- **RPO** (Recovery Point Objective): 1 hour
- **Data Loss**: Maximum 1 hour of recent data
```

---

## Security Hardening

### Server Hardening

```bash
#!/bin/bash
# security-hardening.sh - Server security hardening

# Update system packages
apt update && apt upgrade -y

# Configure firewall
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# Disable unnecessary services
systemctl disable bluetooth
systemctl disable cups
systemctl disable avahi-daemon

# Configure SSH security
sed -i 's/#PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config
echo "AllowUsers timetracker" >> /etc/ssh/sshd_config
systemctl restart sshd

# Install and configure fail2ban
apt install fail2ban -y
cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local

cat > /etc/fail2ban/jail.d/timetracker.conf << EOF
[timetracker-auth]
enabled = true
port = http,https
filter = timetracker-auth
logpath = /var/log/nginx/timetracker_access.log
maxretry = 5
bantime = 3600
findtime = 600

[sshd]
enabled = true
port = ssh
logpath = %(sshd_log)s
maxretry = 3
bantime = 3600
EOF

# Create fail2ban filter for TimeTracker
cat > /etc/fail2ban/filter.d/timetracker-auth.conf << EOF
[Definition]
failregex = ^<HOST>.*POST.*/api/auth/login.*401
ignoreregex =
EOF

systemctl enable fail2ban
systemctl start fail2ban

# Configure automatic security updates
apt install unattended-upgrades -y
dpkg-reconfigure -plow unattended-upgrades

# Set up intrusion detection
apt install aide -y
aideinit
mv /var/lib/aide/aide.db.new /var/lib/aide/aide.db

# Configure log rotation
cat > /etc/logrotate.d/timetracker << EOF
/var/log/timetracker/*.log {
    daily
    missingok
    rotate 52
    compress
    delaycompress
    notifempty
    create 644 timetracker www-data
    postrotate
        systemctl reload php8.4-fpm
    endscript
}
EOF

echo "✅ Security hardening completed"
```

### Application Security

```yaml
# config/packages/security.yaml
security:
    password_hashers:
        App\Entity\User:
            algorithm: bcrypt
            cost: 12
    
    providers:
        ldap_provider:
            ldap:
                service: ldap.service
                
    firewalls:
        main:
            pattern: ^/
            provider: ldap_provider
            custom_authenticator: App\Security\LdapAuthenticator
            logout:
                path: app_logout
            remember_me:
                secret: '%kernel.secret%'
                lifetime: 604800
                path: /
                secure: true
                httponly: true
                samesite: strict
    
    access_control:
        - { path: ^/api/auth, roles: PUBLIC_ACCESS }
        - { path: ^/api, roles: ROLE_USER }
        - { path: ^/admin, roles: ROLE_PL }
        - { path: ^/controlling, roles: ROLE_CTL }
        
    role_hierarchy:
        ROLE_CTL: ROLE_DEV
        ROLE_PL: [ROLE_CTL, ROLE_DEV]
```

### Security Monitoring

```bash
#!/bin/bash
# security-monitoring.sh - Security monitoring setup

# Install OSSEC HIDS
wget https://github.com/ossec/ossec-hids/archive/3.7.0.tar.gz
tar -xzf 3.7.0.tar.gz
cd ossec-hids-3.7.0
./install.sh

# Configure OSSEC for TimeTracker
cat > /var/ossec/etc/local_rules.xml << EOF
<group name="timetracker,">
  <rule id="100001" level="5">
    <if_sid>31101</if_sid>
    <match>POST /api/auth/login</match>
    <description>TimeTracker login attempt</description>
  </rule>
  
  <rule id="100002" level="10">
    <if_sid>100001</if_sid>
    <match>401</match>
    <description>TimeTracker failed login attempt</description>
  </rule>
  
  <rule id="100003" level="12">
    <if_sid>100002</if_sid>
    <frequency>5</frequency>
    <timeframe>300</timeframe>
    <description>Multiple TimeTracker failed login attempts</description>
  </rule>
</group>
EOF

# Install ClamAV antivirus
apt install clamav clamav-daemon -y
freshclam
systemctl enable clamav-daemon
systemctl start clamav-daemon

# Setup regular security scans
cat > /etc/cron.daily/security-scan << 'EOF'
#!/bin/bash
# Daily security scan

# File integrity check
aide --check

# Antivirus scan
clamscan -r /var/www/timetracker --log=/var/log/clamav/scan.log

# Check for rootkits
chkrootkit

# Security updates check
unattended-upgrade --dry-run

# Generate security report
{
    echo "=== Daily Security Report $(date) ==="
    echo "File integrity: $(aide --check 2>&1 | grep -c "found differences")"
    echo "Virus scan: $(grep -c "FOUND" /var/log/clamav/scan.log || echo "0")"
    echo "Failed logins: $(grep "authentication failure" /var/log/auth.log | wc -l)"
    echo "Firewall blocks: $(ufw status | grep -c "DENY")"
} | mail -s "TimeTracker Security Report" security@company.com
EOF

chmod +x /etc/cron.daily/security-scan

echo "✅ Security monitoring configured"
```

---

## Performance Optimization

### PHP Optimization

```ini
# /etc/php/8.4/fpm/conf.d/99-performance.ini
; Memory settings
memory_limit = 512M
max_execution_time = 300

; File upload settings
upload_max_filesize = 10M
post_max_size = 50M
max_file_uploads = 20

; Session settings
session.gc_maxlifetime = 3600
session.gc_probability = 1
session.gc_divisor = 1000

; OPcache optimization
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.max_wasted_percentage = 10
opcache.use_cwd = 1
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
opcache.save_comments = 0
opcache.enable_file_override = 1
opcache.optimization_level = 0x7FFFBFFF
opcache.file_cache = /var/tmp/opcache
opcache.file_cache_only = 0

; APCu settings
apc.enabled = 1
apc.shm_size = 256M
apc.ttl = 7200
apc.user_ttl = 7200
apc.gc_ttl = 3600
apc.entries_hint = 4096
apc.slam_defense = 1
```

### Database Performance Tuning

```sql
-- Performance analysis queries
-- Run these to identify bottlenecks

-- Slow query analysis
SELECT query_time, lock_time, rows_examined, rows_sent, sql_text 
FROM mysql.slow_log 
ORDER BY query_time DESC 
LIMIT 10;

-- Index usage analysis
SELECT 
    table_name,
    index_name,
    cardinality,
    nullable,
    index_type
FROM information_schema.statistics 
WHERE table_schema = 'timetracker'
ORDER BY cardinality DESC;

-- Buffer pool efficiency
SHOW ENGINE INNODB STATUS\G

-- Query cache statistics (MySQL 5.7)
SHOW STATUS LIKE 'Qcache%';
```

### Application Caching Strategy

```yaml
# config/packages/cache.yaml
framework:
    cache:
        app: cache.adapter.redis
        system: cache.adapter.redis
        default_redis_provider: '%env(REDIS_URL)%'
        
        pools:
            # User session cache (fast access)
            cache.sessions:
                adapter: cache.adapter.apcu
                default_lifetime: 1800
                
            # User profile cache
            cache.user_profiles:
                adapter: cache.adapter.redis
                default_lifetime: 3600
                
            # LDAP query cache
            cache.ldap_queries:
                adapter: cache.adapter.apcu
                default_lifetime: 300
                
            # Project/customer data cache
            cache.project_data:
                adapter: cache.adapter.redis
                default_lifetime: 7200
                
            # Report cache (expensive queries)
            cache.reports:
                adapter: cache.adapter.redis
                default_lifetime: 3600
                
            # JIRA metadata cache
            cache.jira_metadata:
                adapter: cache.adapter.redis
                default_lifetime: 1800
```

### CDN Configuration

```nginx
# CDN/Edge cache headers
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
    # Long-term caching for static assets
    expires 1y;
    add_header Cache-Control "public, immutable";
    add_header X-Content-Type-Options nosniff;
    
    # Enable compression
    gzip_static on;
    brotli_static on;
}

location ~* \.(json|xml)$ {
    # Short-term caching for API responses
    expires 1h;
    add_header Cache-Control "public, must-revalidate";
}

location /api/reports/ {
    # Medium-term caching for reports
    expires 30m;
    add_header Cache-Control "public, must-revalidate";
}
```

---

## Maintenance Procedures

### Routine Maintenance Tasks

```bash
#!/bin/bash
# maintenance.sh - Weekly maintenance tasks

# Log cleanup
find /var/log -name "*.log" -type f -mtime +30 -delete
find /var/log -name "*.log.*.gz" -type f -mtime +90 -delete

# Clear old cache files
find /var/www/timetracker/var/cache -name "*" -type f -mtime +7 -delete

# Database optimization
mysql -u timetracker -p timetracker -e "OPTIMIZE TABLE entries, users, projects, customers;"

# Update search indexes
php bin/console app:search:reindex

# Clear old sessions
php bin/console app:sessions:cleanup

# Generate performance report
php bin/console app:performance:report > /var/log/timetracker/performance_$(date +%Y%m%d).log

# Update dependencies (security updates only)
composer update --with-dependencies --dry-run | grep -i security
```

### Health Checks

```bash
#!/bin/bash
# health-check.sh - Application health monitoring

set -e

# Check web server response
if ! curl -f -s http://localhost/health > /dev/null; then
    echo "ERROR: Web server health check failed"
    exit 1
fi

# Check database connectivity
if ! php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; then
    echo "ERROR: Database connectivity check failed"
    exit 1
fi

# Check LDAP connectivity
if ! php bin/console app:ldap:test > /dev/null 2>&1; then
    echo "WARNING: LDAP connectivity check failed"
fi

# Check Redis connectivity
if ! redis-cli ping > /dev/null 2>&1; then
    echo "WARNING: Redis connectivity check failed"
fi

# Check disk space
DISK_USAGE=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -gt 90 ]; then
    echo "WARNING: Disk usage is ${DISK_USAGE}%"
fi

# Check memory usage
MEMORY_USAGE=$(free | grep Mem | awk '{printf "%.0f", $3/$2 * 100.0}')
if [ "$MEMORY_USAGE" -gt 90 ]; then
    echo "WARNING: Memory usage is ${MEMORY_USAGE}%"
fi

# Check SSL certificate expiry
SSL_DAYS=$(openssl x509 -noout -dates -in /etc/ssl/certs/timetracker.company.com.crt | grep notAfter | cut -d= -f2 | xargs -I {} date -d "{}" +%s)
CURRENT_DAYS=$(date +%s)
DAYS_UNTIL_EXPIRY=$(( (SSL_DAYS - CURRENT_DAYS) / 86400 ))

if [ "$DAYS_UNTIL_EXPIRY" -lt 30 ]; then
    echo "WARNING: SSL certificate expires in ${DAYS_UNTIL_EXPIRY} days"
fi

echo "Health check passed"
```

---

**🎉 Deployment Complete!**

Your TimeTracker application is now deployed and ready for production use with:

- ✅ High availability and load balancing
- ✅ SSL/TLS encryption and security hardening
- ✅ Comprehensive monitoring and logging
- ✅ Automated backups and disaster recovery
- ✅ Performance optimization and caching
- ✅ Security monitoring and intrusion detection

For ongoing support:
- 📊 Monitor dashboards at `/metrics` and Grafana
- 📧 Configure alerts for critical issues
- 🔄 Follow maintenance schedules
- 📚 Keep documentation updated

---

**Last Updated**: 2025-01-20  
**Deployment Version**: v4.1  
**Support**: Create a GitHub issue or contact DevOps team