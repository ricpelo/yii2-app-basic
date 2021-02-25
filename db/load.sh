#!/bin/sh

BASE_DIR=$(dirname "$(readlink -f "$0")")
if [ "$1" != "test" ]; then
    psql -h localhost -U proyecto -d proyecto < $BASE_DIR/proyecto.sql
    if [ -f "$BASE_DIR/proyecto_test.sql" ]; then
        psql -h localhost -U proyecto -d proyecto < $BASE_DIR/proyecto_test.sql
    fi
    echo "DROP TABLE IF EXISTS migration CASCADE;" | psql -h localhost -U proyecto -d proyecto
fi
psql -h localhost -U proyecto -d proyecto_test < $BASE_DIR/proyecto.sql
echo "DROP TABLE IF EXISTS migration CASCADE;" | psql -h localhost -U proyecto -d proyecto_test
