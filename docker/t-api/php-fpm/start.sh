#!/usr/bin/env bash
[ -e /var/www/php/storage ] && chown www-data:www-data -R /var/www/php/storage
cd /var/www/php && echo ...
if [ ! -f /var/www/php/composer ]; then
    echo "composer no found"
else
    if [ -f /usr/local/bin/composer ]; then
        echo "'/usr/local/bin/composer' File exists"
    else
        [ ! -e /usr/local/bin/composer ] && ln -s /var/www/php/composer /usr/local/bin/composer
    fi
fi
chmod +x /var/www/php/composer
cd /var/www/php && echo ...

echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * cd /var/www/php && php artisan schedule:run >> /dev/null 2>&1
0 * * * * cd /var/www/php && php artisan queue:restart" >/var/spool/cron/www-data
echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * cd /var/www/php && php artisan app:ok >> /dev/null 2>&1" >/var/spool/cron/root

[ -e /var/spool/cron/www-data ] && chown www-data:www-data /var/spool/cron/www-data && chmod 600 /var/spool/cron/www-data
[ -e /var/spool/cron/root ] && chown root:root /var/spool/cron/root && chmod 600 /var/spool/cron/root

if [ ! -e /var/www/php/public/storage ]; then
    php artisan storage:link
fi

/usr/bin/supervisord -n -c /etc/supervisord.conf
