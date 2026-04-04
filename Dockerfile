FROM dunglas/frankenphp:php8.3-bookworm

# Install system dependencies
RUN apt-get update && apt-get install -y git unzip zip curl && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN install-php-extensions ctype curl dom fileinfo filter hash mbstring openssl pcre pdo session tokenizer xml pdo_mysql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Install PHP dependencies
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-scripts --no-interaction

# Install and build frontend
COPY package.json package-lock.json* ./
RUN npm install
COPY . .
RUN npm run build

# Set permissions
RUN mkdir -p storage/framework/{sessions,views,cache,testing} storage/logs bootstrap/cache \
    && chmod -R a+rw storage bootstrap/cache

EXPOSE 8080

CMD php artisan config:cache \
    && php artisan route:cache \
    && php artisan event:cache \
    && php artisan migrate --force \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
