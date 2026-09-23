FROM php:8.3-fpm-alpine

# Dependências do sistema
RUN apk add --no-cache \
    bash \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    postgresql-dev \
    oniguruma-dev \
    icu-dev \
    linux-headers \
    $PHPIZE_DEPS

# Extensões PHP necessárias
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        pcntl

# Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

# Permissões
RUN addgroup -g 1000 -S stretor && adduser -u 1000 -S stretor -G stretor
RUN chown -R stretor:stretor /var/www

# Copia apenas os manifests primeiro para aproveitar cache de camadas
COPY backend/composer.json backend/composer.lock* ./

# Desativa o bloqueio de advisories e instala as dependências.
# Se não houver composer.lock, gera um (update) para builds determinísticos.
RUN composer config --global audit.block false \
    && if [ -f composer.lock ]; then \
         composer install \
           --no-interaction \
           --no-scripts \
           --no-progress \
           --prefer-dist \
           --optimize-autoloader; \
       else \
         composer update \
           --no-interaction \
           --no-scripts \
           --no-progress \
           --prefer-dist \
           --optimize-autoloader; \
       fi \
    && chown -R stretor:stretor vendor

# su-exec para trocar de usuário no entrypoint
RUN apk add --no-cache su-exec

# Entrypoint de bootstrap
COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
