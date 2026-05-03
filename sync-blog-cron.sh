#!/bin/bash
export FB_PAGE_TOKEN="EAASAF50WwJQBQ738kDQMU8GLy39gr3i9gCY1SwbG6rFyJy33fmQM6ZB9XxFgvkLeiSK1X6NuJZAEBDKKUu2tPklop1lJqeQM2ywH1gmFWvuNMjLhNBiehRwyLBGYZBpfBiCDnWOaz6EuW9jPw84iXFbFHtGVZAZBeXaoLbIagcxsZBR5ZApbb0b99STyIHlhaPX77IInDoR3oNHn5q0mjhC"
export FB_PAGE_ID="102425844783696"
export FB_TOKEN_TYPE="page"
docker exec -e FB_PAGE_TOKEN="$FB_PAGE_TOKEN" -e FB_PAGE_ID="$FB_PAGE_ID" -e FB_TOKEN_TYPE="$FB_TOKEN_TYPE" yada-translations-web-1 php /var/www/html/api/sync-blog.php >> /var/log/blog-sync.log 2>&1
