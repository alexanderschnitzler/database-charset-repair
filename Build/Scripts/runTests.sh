#!/usr/bin/env bash

#
# Test runner based on docker or podman, trimmed from TYPO3 core's Build/Scripts/runTests.sh.
# The functional tests need a real MariaDB or MySQL, so every run starts a throw-away database
# container next to a TYPO3 core-testing PHP container on a private network. With -k the
# database container survives the run and is reused by the next one, which makes iterating on
# a single test fast and leaves the database around for inspection.
#

if [ "${CI}" != "true" ]; then
    trap 'echo "runTests.sh SIGINT signal emitted";cleanUp;exit 2' SIGINT
fi

printSummary() {
    cleanUp
    echo "" >&2
    echo "###########################################################################" >&2
    echo "Result of ${TEST_SUITE}" >&2
    echo "Container runtime: ${CONTAINER_BIN}" >&2
    echo "PHP: ${PHP_VERSION}" >&2
    if [[ ${TEST_SUITE} = "functional" ]]; then
        echo "DBMS: ${DBMS}  version ${DBMS_VERSION}  driver ${DATABASE_DRIVER}" >&2
    fi
    if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
        echo "SUCCESS" >&2
    else
        echo "FAILURE" >&2
    fi
    echo "###########################################################################" >&2
    echo "" >&2
    exit ${SUITE_EXIT_CODE}
}

# Poll a real login over TCP: the images first run an init server that only answers on the unix
# socket, and MySQL 8 opens port 3306 a moment before it accepts logins.
waitForDatabase() {
    local COUNT=0
    local PING="ping --protocol=tcp -h127.0.0.1 -uroot -pfuncp --silent"
    until ${CONTAINER_BIN} exec ${DB_CONTAINER} sh -c "mysqladmin ${PING} || mariadb-admin ${PING}" >/dev/null 2>&1; do
        if [ "${COUNT}" -gt 60 ]; then
            echo "Database container ${DB_CONTAINER} did not come up. Aborting." >&2
            cleanUp
            exit 1
        fi
        sleep 1
        COUNT=$((COUNT + 1))
    done
}

cleanUp() {
    ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}')
    for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
        if [ ${KEEP_DB} -eq 1 ] && [ "${ATTACHED_CONTAINER}" = "${DB_CONTAINER}" ]; then
            continue
        fi
        ${CONTAINER_BIN} kill ${ATTACHED_CONTAINER} >/dev/null
    done
    if [ ${KEEP_DB} -eq 0 ]; then
        ${CONTAINER_BIN} network rm ${NETWORK} >/dev/null 2>&1
    fi
}

# Kept database containers (-k) and their network; used by -s clean.
stopKeptDatabases() {
    for KEPT in $(${CONTAINER_BIN} ps --filter name=^db-keep- --format='{{.Names}}'); do
        echo "Stopping ${KEPT}"
        ${CONTAINER_BIN} kill ${KEPT} >/dev/null
    done
    ${CONTAINER_BIN} network rm ${KEEP_NETWORK} >/dev/null 2>&1
}

handleDbmsOptions() {
    [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
    if [ "${DATABASE_DRIVER}" != "mysqli" ] && [ "${DATABASE_DRIVER}" != "pdo_mysql" ]; then
        echo "Invalid combination -d ${DBMS} -a ${DATABASE_DRIVER}" >&2
        exit 1
    fi
    case ${DBMS} in
        mariadb)
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="10.4"
            if ! [[ ${DBMS_VERSION} =~ ^(10.4|10.5|10.6|10.11|11.4|11.8)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                exit 1
            fi
            IMAGE_DB="docker.io/mariadb:${DBMS_VERSION}"
            ;;
        mysql)
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="8.0"
            if ! [[ ${DBMS_VERSION} =~ ^(8.0|8.4|9)$ ]]; then
                echo "Invalid combination -d ${DBMS} -i ${DBMS_VERSION}" >&2
                exit 1
            fi
            IMAGE_DB="docker.io/mysql:${DBMS_VERSION}"
            ;;
        *)
            echo "Invalid -d option argument ${DBMS}" >&2
            exit 1
            ;;
    esac
}

getPhpImageVersion() {
    case ${1} in
        8.2) echo -n "1.15" ;;
        8.3) echo -n "1.16" ;;
        8.4) echo -n "1.8" ;;
        8.5) echo -n "1.8" ;;
    esac
}

cd "$(dirname "$0")"/../../ || exit 1
ROOT="${PWD}"

# Option defaults.
TEST_SUITE="functional"
DBMS="mariadb"
DBMS_VERSION=""
DATABASE_DRIVER=""
PHP_VERSION="8.2"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
EXTRA_TEST_OPTIONS=""
CGLCHECK_DRY_RUN=""
CONTAINER_BIN=""
CI_PARAMS="${CI_PARAMS:-}"
CONTAINER_INTERACTIVE="-it --init"
# No TTY, e.g. a pipe or a scheduler: docker refuses -t, so run without it.
[ -t 0 ] || CONTAINER_INTERACTIVE="-i --init"
HOST_UID=$(id -u)
USERSET=""
SUFFIX=$(echo $RANDOM)
NETWORK="database-charset-repair-${SUFFIX}"
KEEP_DB=0
KEEP_NETWORK="database-charset-repair-keep"
DB_CONTAINER="db-${SUFFIX}"
CONTAINER_HOST="host.docker.internal"

HELP=$(cat <<EOF
Test runner for schnitzler/database-charset-repair, based on docker or podman.

Usage: $0 [options] [--] [phpunit args]

Options:
    -s <...>
        Specifies the test suite to run
            - functional (default): functional tests against a real database
            - unit: unit tests, no database
            - composerInstall: "composer install" inside the PHP container
            - composerUpdate: "composer update" inside the PHP container
            - phpstan: phpstan analysis
            - cgl: php-cs-fixer, fixes files; use -n for a dry run
            - lint: php linting
            - clean: remove test instances, caches, vendor/ and stop kept databases (-k)

    -b <docker|podman>
        Container environment: podman if installed, docker otherwise.

    -d <mariadb|mysql>
        Only with -s functional. Default: mariadb

    -i <version>
        Only with -s functional. Database version:
            - mariadb: 10.4 (default), 10.5, 10.6, 10.11, 11.4, 11.8
            - mysql: 8.0 (default), 8.4, 9

    -a <mysqli|pdo_mysql>
        Only with -s functional. Database driver, default mysqli.

    -p <8.2|8.3|8.4|8.5>
        PHP minor version, default 8.2

    -e "<phpunit options>"
        Only with -s functional or -s unit. Extra options for phpunit, e.g. -e "--filter repair".

    -k
        Only with -s functional. Keep the database container running after the run and reuse
        it next time, one per engine version (db-keep-<dbms>-<version>). Turns a 15 second
        run into a 2 second one and leaves the data for inspection, e.g.
        docker exec -it db-keep-mariadb-10.4 mariadb -uroot -pfuncp
        -s clean stops all kept databases.

    -x
        Only with -s functional. Send xdebug to the host IDE (default port 9003, see -y).

    -y <port>
        Xdebug port on the host, default 9003.

    -n
        Only with -s cgl. Dry run.

    -u
        Pull the newest core-testing PHP images and remove dangling ones.

    -h
        Show this help.

Examples:
    # Functional tests on MariaDB 10.4 with PHP 8.2 (the floor TYPO3 13 supports)
    Build/Scripts/runTests.sh

    # Functional tests on MySQL 8.4 with PHP 8.5
    Build/Scripts/runTests.sh -d mysql -i 8.4 -p 8.5

    # One test, over and over, against a database that stays up between runs
    Build/Scripts/runTests.sh -k -e "--filter repairFixes"

    # The whole engine matrix
    for db in "mariadb 10.4" "mariadb 10.6" "mariadb 10.11" "mariadb 11.4" "mariadb 11.8" "mysql 8.0" "mysql 8.4" "mysql 9"; do
        Build/Scripts/runTests.sh -d \${db% *} -i \${db#* } || break
    done
EOF
)

while getopts ":s:b:d:i:a:p:e:kxy:nuh" OPT; do
    case ${OPT} in
        s) TEST_SUITE=${OPTARG} ;;
        b)
            if ! [[ ${OPTARG} =~ ^(docker|podman)$ ]]; then
                echo "Invalid -b option argument ${OPTARG}" >&2
                exit 1
            fi
            CONTAINER_BIN=${OPTARG}
            ;;
        d) DBMS=${OPTARG} ;;
        i) DBMS_VERSION=${OPTARG} ;;
        a) DATABASE_DRIVER=${OPTARG} ;;
        p)
            PHP_VERSION=${OPTARG}
            if ! [[ ${PHP_VERSION} =~ ^(8.2|8.3|8.4|8.5)$ ]]; then
                echo "Invalid -p option argument ${OPTARG}" >&2
                exit 1
            fi
            ;;
        e) EXTRA_TEST_OPTIONS=${OPTARG} ;;
        k) KEEP_DB=1 ;;
        x) PHP_XDEBUG_ON=1 ;;
        y) PHP_XDEBUG_PORT=${OPTARG} ;;
        n) CGLCHECK_DRY_RUN="--dry-run" ;;
        u) TEST_SUITE=update ;;
        h)
            echo "${HELP}"
            exit 0
            ;;
        \?)
            echo "Invalid option -${OPTARG}" >&2
            echo >&2
            echo "${HELP}" >&2
            exit 1
            ;;
        :)
            echo "Option -${OPTARG} requires an argument" >&2
            exit 1
            ;;
    esac
done
shift $((OPTIND - 1))

if [[ -z "${CONTAINER_BIN}" ]]; then
    if type podman >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    else
        CONTAINER_BIN="docker"
    fi
fi
if ! type ${CONTAINER_BIN} >/dev/null 2>&1; then
    echo "Container environment \"${CONTAINER_BIN}\" not found. Install it or pick one with -b." >&2
    exit 1
fi
if [ "${CI}" = "true" ]; then
    CONTAINER_INTERACTIVE=""
fi
if [ $(uname) != "Darwin" ] && [ ${CONTAINER_BIN} = "docker" ]; then
    # Run as the host user so files written into the bind mount belong to us.
    USERSET="--user ${HOST_UID}"
fi

IMAGE_PHP="ghcr.io/typo3/core-testing-$(echo "php${PHP_VERSION}" | sed -e 's/\.//'):$(getPhpImageVersion ${PHP_VERSION})"

if [ ${KEEP_DB} -eq 1 ]; then
    NETWORK="${KEEP_NETWORK}"
    ${CONTAINER_BIN} network inspect ${NETWORK} >/dev/null 2>&1 || ${CONTAINER_BIN} network create ${NETWORK} >/dev/null
else
    ${CONTAINER_BIN} network create ${NETWORK} >/dev/null
fi

if [ ${CONTAINER_BIN} = "docker" ]; then
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host ${CONTAINER_HOST}:host-gateway ${USERSET} -v ${ROOT}:${ROOT} -w ${ROOT} -e HOME=${ROOT}/var/transient -e COMPOSER_CACHE_DIR=${ROOT}/var/cache/composer"
else
    CONTAINER_HOST="host.containers.internal"
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${ROOT}:${ROOT} -w ${ROOT} -e HOME=${ROOT}/var/transient -e COMPOSER_CACHE_DIR=${ROOT}/var/cache/composer"
fi

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=${CONTAINER_HOST}"
fi

mkdir -p "${ROOT}/var/transient" "${ROOT}/var/cache/composer"

SUITE_EXIT_CODE=0
case ${TEST_SUITE} in
    functional)
        handleDbmsOptions
        if [ ${KEEP_DB} -eq 1 ]; then
            DB_CONTAINER="db-keep-${DBMS}-${DBMS_VERSION}"
        fi
        if [ -n "$(${CONTAINER_BIN} ps -q --filter name=^${DB_CONTAINER}$)" ]; then
            echo "Reusing ${DB_CONTAINER}"
        else
            echo "Starting ${IMAGE_DB} as ${DB_CONTAINER}"
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name ${DB_CONTAINER} --network ${NETWORK} -d \
                -e MYSQL_ROOT_PASSWORD=funcp -e MARIADB_ROOT_PASSWORD=funcp \
                --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_DB} >/dev/null
            waitForDatabase
        fi
        CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseHost=${DB_CONTAINER} -e typo3DatabasePort=3306 -e typo3DatabaseUsername=root -e typo3DatabasePassword=funcp -e typo3DatabaseName=func_test"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} \
            ${IMAGE_PHP} vendor/bin/phpunit -c phpunit.functional.xml ${EXTRA_TEST_OPTIONS} "$@"
        SUITE_EXIT_CODE=$?
        ;;
    unit)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" \
            ${IMAGE_PHP} vendor/bin/phpunit -c phpunit.unit.xml ${EXTRA_TEST_OPTIONS} "$@"
        SUITE_EXIT_CODE=$?
        ;;
    composerInstall)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-install-${SUFFIX} ${IMAGE_PHP} composer install --no-progress --no-interaction "$@"
        SUITE_EXIT_CODE=$?
        ;;
    composerUpdate)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-update-${SUFFIX} ${IMAGE_PHP} composer update --no-progress --no-interaction "$@"
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-${SUFFIX} ${IMAGE_PHP} php -dxdebug.mode=off vendor/bin/phpstan analyse --no-progress "$@"
        SUITE_EXIT_CODE=$?
        ;;
    cgl)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-${SUFFIX} ${IMAGE_PHP} php -dxdebug.mode=off vendor/bin/php-cs-fixer fix -v ${CGLCHECK_DRY_RUN} "$@"
        SUITE_EXIT_CODE=$?
        ;;
    lint)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-${SUFFIX} ${IMAGE_PHP} php -dxdebug.mode=off vendor/bin/phplint "$@"
        SUITE_EXIT_CODE=$?
        ;;
    clean)
        rm -rf "${ROOT}/public" "${ROOT}/var" "${ROOT}/vendor"
        stopKeptDatabases
        ;;
    update)
        ${CONTAINER_BIN} images "ghcr.io/typo3/core-testing-*" --format "{{.Repository}}:{{.Tag}}" | xargs -I {} ${CONTAINER_BIN} pull {}
        ${CONTAINER_BIN} images "ghcr.io/typo3/core-testing-*" --filter "dangling=true" --format "{{.ID}}" | xargs -I {} ${CONTAINER_BIN} rmi {}
        ;;
    *)
        echo "Invalid -s option argument ${TEST_SUITE}" >&2
        echo >&2
        echo "${HELP}" >&2
        SUITE_EXIT_CODE=1
        ;;
esac

printSummary
