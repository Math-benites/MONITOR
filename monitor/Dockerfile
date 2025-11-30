FROM php:8.2-apache

# Instalar curl e extensões necessárias
RUN apt-get update && apt-get install -y \
    unzip \
    curl \
    libcurl4-openssl-dev \
    && docker-php-ext-install curl

# Habilitar mod_rewrite
RUN a2enmod rewrite

WORKDIR /var/www/html
