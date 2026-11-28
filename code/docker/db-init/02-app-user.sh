#!/bin/sh
# Runs once, when the MySQL container starts with an empty data directory,
# after 01-schema.sql (code/database/Deploy-InfraLogging.mysql.sql) has
# created the database.
#
# Creates the account Laravel connects as, with the same least-privilege
# grants as code/stage2/run.sh: SELECT, INSERT, and UPDATE only (no DELETE:
# run logs are append-only). '%' because the app connects from another
# container. The password comes from .env through docker-compose.yml.
set -e

mysql --protocol=socket -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS '$APP_DB_USER'@'%' IDENTIFIED BY '$APP_DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE ON \`InfrastructureLogging\`.* TO '$APP_DB_USER'@'%';
SQL
