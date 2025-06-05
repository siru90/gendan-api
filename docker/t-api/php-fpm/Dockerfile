FROM rockylinux:8

COPY php-8.2.8.tar.bz2 /usr/src/php-8.2.8.tar.bz2

RUN dnf update -y && dnf install -y wget bzip2 gcc gcc-c++ libxml2-devel sqlite-devel dnf-utils epel-release procps \
    openssl-devel libcurl-devel gmp-devel findutils make autoconf libpng-devel libjpeg-devel libXpm-devel \
    freetype-devel git zip libwebp-devel libzip-devel \
    && dnf install --enablerepo=powertools -y ImageMagick-devel hiredis-devel re2c oniguruma-devel supervisor \
    libmcrypt-devel libc-client-devel python39 crontabs libsodium-devel openssh-server passwd net-tools \
    && dnf clean all
RUN cd /usr/src && tar jxf php-8.2.8.tar.bz2
RUN ln -s /usr/lib64/libc-client.so /usr/lib/libc-client.so
RUN cd /usr/src/php-8.2.8 && ./configure --prefix=/usr/local --with-config-file-path=/usr/local/etc/php \
    --with-mysqli --with-pdo-mysql --enable-mbstring --enable-fpm --enable-gd --with-curl \
    --with-openssl --with-freetype --with-zlib --with-jpeg --with-xpm --with-webp --enable-pcntl \
    --with-gmp --with-zip --enable-bcmath --enable-sockets --with-pear \
    --with-imap --with-kerberos --with-imap-ssl --with-sodium --enable-zts \
    && cd /usr/src/php-8.2.8 && make && make install \
    && cp /usr/local/etc/php-fpm.conf.default /usr/local/etc/php-fpm.conf \
    && groupadd www-data && useradd -m www-data -g www-data \
    && rm -rf /usr/src/php-8.2.8
COPY ./phpiredis/ /usr/src/phpiredis
RUN cd /usr/src/phpiredis \
    && phpize && ./configure --enable-phpiredis \
    && make && make install \
    && rm -rf /usr/src/phpiredis
# pecl download solr mongodb imagick redis mailparse xdebug
RUN pecl channel-update pecl.php.net \
    && pecl install solr mongodb imagick redis mailparse parallel \
    && pecl clear-cache
RUN pecl install xdebug && pecl clear-cache

RUN echo "" >> ~/.bashrc \
    && echo "export PROMPT_COMMAND=\"history -a; history -r; \$PROMPT_COMMAND\"" >> ~/.bashrc \
    && echo "alias ll=\"ls -al\"" >> ~/.bashrc
COPY ./swoole/ /usr/src/swoole
RUN cd /usr/src/swoole && \
    phpize && \
    ./configure && \
    make && make install

RUN echo root:123456 | chpasswd && dnf clean all \
    && ssh-keygen -N "" -t rsa -f /etc/ssh/ssh_host_rsa_key \
    && ssh-keygen -N "" -t ecdsa -f /etc/ssh/ssh_host_ecdsa_key \
    && ssh-keygen -N "" -t dsa -f /etc/ssh/ssh_host_ed25519_key
RUN ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime
RUN echo 'Asia/Shanghai' >/etc/timezone
RUN dnf config-manager --enable powertools && \
    dnf install -y --nogpgcheck https://mirrors.rpmfusion.org/free/el/rpmfusion-free-release-$(rpm -E %rhel).noarch.rpm \
    https://mirrors.rpmfusion.org/nonfree/el/rpmfusion-nonfree-release-$(rpm -E %rhel).noarch.rpm && \
    dnf install -y ffmpeg
WORKDIR /var/www/php
EXPOSE 22
EXPOSE 9502
EXPOSE 39001

ENTRYPOINT ["/bin/bash", "/var/www/php/docker/t-api/php-fpm/start.sh"]
