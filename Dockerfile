FROM php:8.2-apache
RUN apt-get update && apt-get install -y libzip-dev zip unzip git && docker-php-ext-install pdo pdo_mysql mysqli && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html
EXPOSE 80
CMD ["apache2-foreground"]
