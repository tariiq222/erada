# ══════════════════════════════════════════════════
# Stage 1: Frontend Build
# ══════════════════════════════════════════════════
FROM node:20-alpine AS frontend

WORKDIR /build

# تثبيت dependencies أولاً (cache optimization)
COPY package*.json ./
RUN npm ci

# نسخ ملفات البناء فقط
COPY resources/ resources/
COPY vite.config.js tsconfig.json tsconfig.node.json ./
COPY lang/ lang/
COPY public/ public/

# بناء الأصول
RUN npm run build:fast

# ══════════════════════════════════════════════════
# Stage 2: PHP Application
# ══════════════════════════════════════════════════
FROM php:8.4-fpm AS app

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    libpq-dev \
    zip \
    unzip \
    nginx \
    supervisor \
    ca-certificates \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure intl \
    && docker-php-ext-install pdo_mysql pdo_pgsql mbstring exif pcntl bcmath gd intl zip

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis
RUN pecl install pcov && docker-php-ext-enable pcov

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# نسخ ملفات المشروع
COPY . .

# إزالة أي .env وإنشاء نسخة من المثال
RUN rm -f .env && cp .env.example .env

# تثبيت PHP dependencies (production فقط)
RUN composer install --optimize-autoloader --no-dev --no-scripts \
    && composer run-script post-autoload-dump || true

# نسخ الأصول المبنية من مرحلة Frontend
COPY --from=frontend /build/public/build public/build/

# ضبط الصلاحيات
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

# إعداد Nginx
RUN echo 'server { \n\
    listen 80; \n\
    server_name _; \n\
    root /var/www/public; \n\
    index index.php; \n\
    location / { \n\
        try_files $uri $uri/ /index.php?$query_string; \n\
    } \n\
    location ~ \.php$ { \n\
        fastcgi_pass 127.0.0.1:9000; \n\
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; \n\
        include fastcgi_params; \n\
    } \n\
}' > /etc/nginx/sites-available/default

# إعداد Supervisor
RUN echo '[supervisord] \n\
nodaemon=true \n\
[program:php-fpm] \n\
command=/usr/local/sbin/php-fpm \n\
autostart=true \n\
autorestart=true \n\
[program:nginx] \n\
command=/usr/sbin/nginx -g "daemon off;" \n\
autostart=true \n\
autorestart=true \n\
[program:queue-worker] \n\
# Default queue worker — no `--queue=` flag because no production code uses
# ->onQueue(): see audit 2026-06-29 finding #5 ("KpiController::import
# silently 504s in production" was HYPOTHESIZED but unproven — grep
# confirmed no named queues in app/Modules). All queued jobs land on
# the default queue.
# numprocs=2 = two parallel workers; bump to 4 if KpiController::import
# or DataImportController::apply steady-state backlog grows.
command=/usr/local/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600 \n\
directory=/var/www \n\
autostart=true \n\
autorestart=true \n\
user=www-data \n\
numprocs=2 \n\
process_name=%(program_name)s_%(process_num)02d \n\
stopwaitsecs=3600 \n\
stdout_logfile=/dev/stdout \n\
stdout_logfile_maxbytes=0 \n\
stderr_logfile=/dev/stderr \n\
stderr_logfile_maxbytes=0 \n\
[program:scheduler] \n\
command=/usr/local/bin/php artisan schedule:work \n\
directory=/var/www \n\
autostart=true \n\
autorestart=true \n\
user=www-data \n\
stopwaitsecs=3600 \n\
stdout_logfile=/dev/stdout \n\
stdout_logfile_maxbytes=0 \n\
stderr_logfile=/dev/stderr \n\
stderr_logfile_maxbytes=0' > /etc/supervisor/conf.d/supervisord.conf

# سكريبت الانطلاق
COPY scripts/deploy.sh /deploy.sh
RUN chmod +x /deploy.sh

RUN echo '#!/bin/bash \n\
set -e \n\
echo "Syncing environment variables to .env..." \n\
env | grep -E "^(APP_|DB_|REDIS_|SESSION_|SANCTUM_|MAIL_|QUEUE_|CACHE_|BROADCAST_|FILESYSTEM_|LOG_|BCRYPT_)" | while IFS='=' read -r key value; do \n\
    # IFS='=' split breaks values containing '=' (e.g. base64 APP_KEY) — \n\
    # 'read' reassembles them; trim any embedded CR from the value. \n\
    value="${value//$'\r'/}" \n\
    if grep -q "^${key}=" .env 2>/dev/null; then \n\
        sed -i "s|^${key}=.*|${key}=${value}|" .env \n\
    else \n\
        echo "${key}=${value}" >> .env \n\
    fi \n\
done \n\
echo "Running deployment script..." \n\
/deploy.sh \n\
echo "Starting services..." \n\
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' > /start.sh \
    && chmod +x /start.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
    CMD curl -f http://localhost/api/health 2>/dev/null || exit 1

CMD ["/start.sh"]
