#!/usr/bin/env bash
#{
#  for ((i = 1; i <= 100; i++)); do
#    chmod -R 666 /var/tmp/php-fpm
#    chmod 755 /var/tmp/php-fpm
#    if [ -e /var/tmp/php-fpm/php-fpm.sock ]; then
#      if [ -r /var/tmp/php-fpm/php-fpm.sock ]; then
#        sleep 1
#        continue
#      fi
#    fi
#    echo "sleep 1"
#    sleep 1
#  done
#} &
nginx -g "daemon off;"
