# PHP + Apache for developing/testing the framework. PHP_VERSION: 8.5 (= hosting since 2026-11-20), older ones only when needed
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-apache

# Debian 11 security archive for old PHP images is gone → drop that source (dev only)
RUN if [ -f /etc/apt/sources.list ]; then sed -i '/security/d' /etc/apt/sources.list; fi

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mysqli intl zip gd opcache

RUN a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf '<Directory /var/www/html>\n    FallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-enabled/front-controller.conf \
    && printf 'display_errors=Off\nlog_errors=On\nerror_reporting=E_ALL\ndate.timezone=Europe/Bratislava\n' > /usr/local/etc/php/conf.d/dev.ini
