FROM php:8.5-cli-bookworm

RUN apt-get update && apt-get install -y git

RUN curl -s https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer

COPY . /app
WORKDIR /app

RUN composer install

CMD [ "php", "index.php" ]