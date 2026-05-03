#!/bin/bash
docker exec yada-www-web-1 php /var/www/html/api/process-email-queue.php 2>/dev/null
