FROM php:8.2-apache

# Включаем модуль mod_rewrite для работы .htaccess
RUN a2enmod rewrite

# Полностью переопределяем дефолтный vhost Apache: разрешение на доступ
# прописано ВНУТРИ <VirtualHost>, поэтому гарантированно применяется к
# этому сайту (без риска, что его перекроет другой <Directory>-блок
# откуда-то из apache2.conf — как оказалось, отдельный conf-файл
# через a2enconf это не исправлял).
RUN { \
        echo '<VirtualHost *:80>'; \
        echo '    DocumentRoot /var/www/html'; \
        echo '    <Directory /var/www/html>'; \
        echo '        Options Indexes FollowSymLinks'; \
        echo '        AllowOverride All'; \
        echo '        Require all granted'; \
        echo '    </Directory>'; \
        echo '    ErrorLog ${APACHE_LOG_DIR}/error.log'; \
        echo '    CustomLog ${APACHE_LOG_DIR}/access.log combined'; \
        echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/000-default.conf

# Копируем все файлы проекта в веб-директорию Apache
COPY . /var/www/html/

# Создаем папки uploads и data, если их нет (Git не хранит пустые директории,
# поэтому после COPY их может не быть в образе), и даем права на запись
RUN mkdir -p /var/www/html/uploads /var/www/html/data \
    && chmod -R 777 /var/www/html/uploads /var/www/html/data

# Указываем порт, который слушает Render
EXPOSE 80
