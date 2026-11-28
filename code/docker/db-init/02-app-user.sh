#!/bin/sh
# Runs once, when the MySQL container starts with an empty data directory,
# after 01-schema.sql (code/database/Deploy-InfraLogging.mysql.sql) has
# created the database.
#
# Creates the accounts the two API stages connect as, with least privilege.
# Neither can DELETE: run logs are append-only.
#   Stage 2 (Laravel): SELECT, INSERT, UPDATE, as in code/stage2/run.sh.
#   Stage 1 (raw PHP): SELECT and EXECUTE, as in code/database/setup-database.sh;
#                      it writes only through the stored procedures.
# '%' because the apps connect from other containers. The passwords come from
# .env through docker-compose.yml.
set -e

mysql --protocol=socket -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE USER IF NOT EXISTS '$APP_DB_USER'@'%' IDENTIFIED BY '$APP_DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE ON \`InfrastructureLogging\`.* TO '$APP_DB_USER'@'%';

CREATE USER IF NOT EXISTS '$STAGE1_DB_USER'@'%' IDENTIFIED BY '$STAGE1_DB_PASSWORD';
GRANT SELECT, EXECUTE ON \`InfrastructureLogging\`.* TO '$STAGE1_DB_USER'@'%';
SQL
