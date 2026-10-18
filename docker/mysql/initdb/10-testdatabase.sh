# Lager testdatabasen <DB_DATABASE>_test, som ./dev test kjører mot (RefreshDatabase tømmer den), og
# gir appens MySQL-bruker tilgang. Kjøres av mysql-bildet første gang datavolumet lages.
# Fila er ikke kjørbar med vilje: da leses den med «.», og docker_process_sql finnes.
# shellcheck shell=bash
# shellcheck disable=SC2154 # MYSQL_DATABASE og MYSQL_USER kommer fra mysql-bildet
docker_process_sql --database=mysql <<-EOSQL
	CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
	GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_test\`.* TO '${MYSQL_USER}'@'%';
	FLUSH PRIVILEGES;
EOSQL
