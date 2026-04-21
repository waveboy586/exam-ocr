#!/bin/bash
set -e

# Prepare runtime directories (idempotent)
mkdir -p \
    /var/www/html/web/teacher/uploads \
    /var/www/html/web/teacher/storage/logs \
    /var/www/html/web/teacher/storage/outputs \
    /var/www/html/web/teacher/storage/uploads \
    /var/www/html/web/teacher/auto_grade_jobs \
    /var/www/html/web/admin/uploads \
    /var/www/html/web/teacher/manage_uploads \
    /tmp/hf_cache

# Ownership — www-data runs both PHP and Python children
chown -R www-data:www-data \
    /var/www/html/web/teacher/uploads \
    /var/www/html/web/teacher/storage \
    /var/www/html/web/teacher/auto_grade_jobs \
    /var/www/html/web/admin/uploads \
    /tmp/hf_cache

# Pass env vars into Apache's runtime so PHP getenv() sees them
{
  echo "PassEnv DB_HOST"
  echo "PassEnv DB_NAME"
  echo "PassEnv DB_USER"
  echo "PassEnv DB_PASSWORD"
  echo "PassEnv DB_CHARSET"
  echo "PassEnv PYTHON_BIN"
  echo "PassEnv TYPHOON_API_KEY"
  echo "PassEnv APP_URL"
} > /etc/apache2/conf-available/env-passthrough.conf
a2enconf env-passthrough > /dev/null

exec "$@"
