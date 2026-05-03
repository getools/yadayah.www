#!/bin/bash
docker exec yada-www-web-1 php /var/www/html/api/sync-invite.php >> /var/log/invite-sync.log 2>&1
