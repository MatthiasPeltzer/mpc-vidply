#!/usr/bin/env bash
#
# MPC extension test runner (Docker). Adapted from georgringer/news and TYPO3 core.
#
set -euo pipefail

if [ "${CI:-}" != "true" ]; then
    trap 'cleanUp; exit 2' SIGINT
fi

THIS_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null && pwd)"
# shellcheck source=runTests.config.sh
source "${THIS_SCRIPT_DIR}/runTests.config.sh"
cd "${THIS_SCRIPT_DIR}/../.." || exit 1
ROOT_DIR="${PWD}"

waitFor() {
    local HOST=${1}
    local PORT=${2}
    local TESTCOMMAND="
COUNT=0;
while ! nc -z ${HOST} ${PORT}; do
  if [ \"\${COUNT}\" -gt 30 ]; then
    echo \"Can not connect to ${HOST} port ${PORT}. Aborting.\";
    exit 1;
  fi;
  sleep 1;
  COUNT=\$((COUNT + 1));
done;
"
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name wait-for-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_ALPINE} /bin/sh -c "${TESTCOMMAND}"
    if [[ $? -gt 0 ]]; then
        kill -SIGINT -$$ 2>/dev/null || true
    fi
}

cleanUp() {
    ATTACHED_CONTAINERS=$(${CONTAINER_BIN} ps --filter network=${NETWORK} --format='{{.Names}}' 2>/dev/null || true)
    for ATTACHED_CONTAINER in ${ATTACHED_CONTAINERS}; do
        ${CONTAINER_BIN} kill "${ATTACHED_CONTAINER}" >/dev/null 2>&1 || true
    done
    ${CONTAINER_BIN} network rm "${NETWORK}" >/dev/null 2>&1 || true
}

handleDbmsOptions() {
    case ${DBMS} in
        mariadb)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="10.11"
            ;;
        mysql)
            [ -z "${DATABASE_DRIVER}" ] && DATABASE_DRIVER="mysqli"
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="8.0"
            ;;
        postgres)
            [ -z "${DBMS_VERSION}" ] && DBMS_VERSION="16"
            ;;
        sqlite)
            ;;
        *)
            echo "Invalid option -d ${DBMS}" >&2
            exit 1
            ;;
    esac
}

loadHelp() {
    read -r -d '' HELP <<'EOF' || true
MPC extension test runner (Docker)

Options:
  -s <suite>     Test suite (default: unit)
                 unit, functional, phpstan, cgl, lintPhp, lintYaml, lintTypoScript,
                 lintHtml, lintScss, unitJavascript, acceptance, composerUpdate, clean, update
  -d <db>        Database for functional|acceptance: sqlite|mariadb|mysql|postgres
  -p <version>   PHP version: 8.2|8.3|8.4|8.5 (default 8.2)
  -t <version>   TYPO3 major for composerUpdate: 13|14
  -b <bin>       Container binary: docker|podman
  -n             Dry-run for cgl
  -x             Enable Xdebug
  -u             Update core-testing container images
  -h             This help

Examples:
  Build/Scripts/runTests.sh -s unit
  Build/Scripts/runTests.sh -s functional -d postgres
  Build/Scripts/runTests.sh -s acceptance
  Build/Scripts/runTests.sh -s phpstan
EOF
}

if ! type "docker" >/dev/null 2>&1 && ! type "podman" >/dev/null 2>&1; then
    echo "This script requires docker or podman." >&2
    exit 1
fi

TEST_SUITE=""
DBMS="sqlite"
DBMS_VERSION=""
PHP_VERSION="8.2"
TYPO3_VERSION="14"
PHP_XDEBUG_ON=0
PHP_XDEBUG_PORT=9003
DRY_RUN=0
DATABASE_DRIVER=""
CONTAINER_BIN=""
CONTAINER_INTERACTIVE="-it --init"
HOST_UID=$(id -u)
HOST_PID=$(id -g)
USERSET=""
SUFFIX="${NETWORK_PREFIX}-${RANDOM}"
NETWORK="${NETWORK_PREFIX}-${SUFFIX}"
CI_PARAMS="${CI_PARAMS:-}"
CONTAINER_HOST="host.docker.internal"
IS_CI=0
SUITE_EXIT_CODE=0

OPTIND=1
INVALID_OPTIONS=()
while getopts "a:b:s:d:i:p:t:xy:nhu" OPT; do
    case ${OPT} in
        s) TEST_SUITE=${OPTARG} ;;
        a) DATABASE_DRIVER=${OPTARG} ;;
        b) CONTAINER_BIN=${OPTARG} ;;
        d) DBMS=${OPTARG} ;;
        i) DBMS_VERSION=${OPTARG} ;;
        p) PHP_VERSION=${OPTARG} ;;
        t) TYPO3_VERSION=${OPTARG} ;;
        x) PHP_XDEBUG_ON=1 ;;
        y) PHP_XDEBUG_PORT=${OPTARG} ;;
        n) DRY_RUN=1 ;;
        h) loadHelp; echo "${HELP}"; exit 0 ;;
        u) TEST_SUITE=update ;;
        \?) INVALID_OPTIONS+=("-${OPTARG}") ;;
        :) INVALID_OPTIONS+=("-${OPTARG}") ;;
    esac
done

if [ ${#INVALID_OPTIONS[@]} -ne 0 ]; then
    echo "Invalid option(s): ${INVALID_OPTIONS[*]}" >&2
    exit 1
fi

[ -z "${TEST_SUITE}" ] && TEST_SUITE="unit"
handleDbmsOptions

if [ "${CI:-}" == "true" ]; then
    IS_CI=1
    CONTAINER_INTERACTIVE=""
fi

if [[ -z "${CONTAINER_BIN}" ]]; then
    if [ "${CI:-}" == "true" ] && type "docker" >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    elif type "podman" >/dev/null 2>&1; then
        CONTAINER_BIN="podman"
    elif type "docker" >/dev/null 2>&1; then
        CONTAINER_BIN="docker"
    fi
fi

if [ "$(uname)" != "Darwin" ] && [ "${CONTAINER_BIN}" == "docker" ]; then
    USERSET="--user ${HOST_UID}"
fi

mkdir -p .cache "${ROOT_DIR}/${WEB_PUBLIC_DIR}/typo3temp/var/tests"
${CONTAINER_BIN} network create "${NETWORK}" >/dev/null

if [ "${CONTAINER_BIN}" == "docker" ]; then
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} --rm --network ${NETWORK} --add-host ${CONTAINER_HOST}:host-gateway ${USERSET} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
else
    CONTAINER_HOST="host.containers.internal"
    CONTAINER_COMMON_PARAMS="${CONTAINER_INTERACTIVE} ${CI_PARAMS} --rm --network ${NETWORK} -v ${ROOT_DIR}:${ROOT_DIR} -w ${ROOT_DIR}"
fi

if [ ${PHP_XDEBUG_ON} -eq 0 ]; then
    XDEBUG_MODE="-e XDEBUG_MODE=off"
    XDEBUG_CONFIG=" "
    PHP_FPM_OPTIONS="-d xdebug.mode=off"
else
    XDEBUG_MODE="-e XDEBUG_MODE=debug -e XDEBUG_TRIGGER=foo"
    XDEBUG_CONFIG="client_port=${PHP_XDEBUG_PORT} client_host=${CONTAINER_HOST}"
    PHP_FPM_OPTIONS="-d xdebug.mode=debug -d xdebug.start_with_request=yes -d xdebug.client_host=${CONTAINER_HOST} -d xdebug.client_port=${PHP_XDEBUG_PORT}"
fi

IMAGE_PHP="ghcr.io/typo3/core-testing-php${PHP_VERSION//./}:latest"
IMAGE_ALPINE="docker.io/alpine:3.20"
IMAGE_APACHE="ghcr.io/typo3/core-testing-apache24:latest"
IMAGE_NODEJS="ghcr.io/typo3/core-testing-nodejs24:latest"
IMAGE_MARIADB="docker.io/mariadb:${DBMS_VERSION}"
IMAGE_MYSQL="docker.io/mysql:${DBMS_VERSION}"
IMAGE_POSTGRES="docker.io/postgres:${DBMS_VERSION}-alpine"
IMAGE_CHROME="selenium/standalone-chrome:4.27.0-20241204"

shift $((OPTIND - 1))

runFunctional() {
    local COMMAND=(.Build/bin/phpunit -c "${FUNCTIONAL_PHPUNIT_CONFIG}" --exclude-group "not-${DBMS}" "$@")
    case ${DBMS} in
        mariadb)
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
            waitFor mariadb-func-${SUFFIX} 3306
            CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-func-${SUFFIX} -e typo3DatabasePassword=funcp"
            ;;
        mysql)
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mysql-func-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MYSQL} >/dev/null
            waitFor mysql-func-${SUFFIX} 3306
            CONTAINERPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mysql-func-${SUFFIX} -e typo3DatabasePassword=funcp"
            ;;
        postgres)
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-func-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=postgres -e POSTGRES_DB=bamboo --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
            waitFor postgres-func-${SUFFIX} 5432
            CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=postgres -e typo3DatabaseHost=postgres-func-${SUFFIX} -e typo3DatabasePassword=funcp"
            ;;
        sqlite)
            mkdir -p "${ROOT_DIR}/${SQLITE_TMPFS_DIR}"
            CONTAINERPARAMS="-e typo3DatabaseDriver=pdo_sqlite --tmpfs ${ROOT_DIR}/${SQLITE_TMPFS_DIR}:rw,noexec,nosuid"
            ;;
    esac
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name functional-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${CONTAINERPARAMS} ${IMAGE_PHP} "${COMMAND[@]}"
}

runAcceptance() {
    if [ "${ENABLE_ACCEPTANCE:-0}" != "1" ]; then
        echo "Acceptance tests are disabled for this package." >&2
        exit 1
    fi
    local WEB_ROOT="${ROOT_DIR}/${WEB_PUBLIC_DIR}"
    local ACCEPTANCE_ROOT="${WEB_ROOT}/typo3temp/var/tests/acceptance"
    mkdir -p "${ACCEPTANCE_ROOT}"
    # HTTP stack serves the acceptance instance; Codeception bootstrap needs the composer web dir (index.php).
    local ACCEPTANCE_HTTP_ENV="-e TYPO3_PATH_APP=${ACCEPTANCE_ROOT} -e TYPO3_PATH_ROOT=${ACCEPTANCE_ROOT} -e TYPO3_PATH_WEB=${ACCEPTANCE_ROOT}"
    local CODECEPT_ENV="-e TYPO3_PATH_APP=${WEB_ROOT} -e TYPO3_PATH_ROOT=${WEB_ROOT} -e TYPO3_PATH_WEB=${WEB_ROOT}"

    ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name chrome-ac-${SUFFIX} --network ${NETWORK} --network-alias chrome --shm-size=2gb -d ${IMAGE_CHROME} >/dev/null
    waitFor chrome-ac-${SUFFIX} 4444

    if [ "${CONTAINER_BIN}" == "docker" ]; then
        ${CONTAINER_BIN} run --rm -d --name ac-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm --add-host "${CONTAINER_HOST}:host-gateway" ${USERSET} \
            ${ACCEPTANCE_HTTP_ENV} -e PHPFPM_USER=${HOST_UID} -e PHPFPM_GROUP=${HOST_PID} -v ${ROOT_DIR}:${ROOT_DIR} ${IMAGE_PHP} php-fpm ${PHP_FPM_OPTIONS} >/dev/null
        ${CONTAINER_BIN} run --rm -d --name ac-web-${SUFFIX} --network ${NETWORK} --network-alias web --add-host "${CONTAINER_HOST}:host-gateway" \
            -e APACHE_RUN_USER="#${HOST_UID}" -e APACHE_RUN_GROUP="#${HOST_PID}" -e APACHE_RUN_SERVERNAME=web \
            -e APACHE_RUN_DOCROOT="${ACCEPTANCE_ROOT}" -e PHPFPM_HOST=phpfpm -e PHPFPM_PORT=9000 \
            -v ${ROOT_DIR}:${ROOT_DIR} ${IMAGE_APACHE} >/dev/null
    else
        ${CONTAINER_BIN} run -d --name ac-phpfpm-${SUFFIX} --network ${NETWORK} --network-alias phpfpm ${USERSET} \
            ${ACCEPTANCE_HTTP_ENV} -v ${ROOT_DIR}:${ROOT_DIR} ${IMAGE_PHP} php-fpm -R ${PHP_FPM_OPTIONS} >/dev/null
        ${CONTAINER_BIN} run --rm -d --name ac-web-${SUFFIX} --network ${NETWORK} --network-alias web \
            -e APACHE_RUN_USER="#${HOST_UID}" -e APACHE_RUN_GROUP="#${HOST_PID}" -e APACHE_RUN_SERVERNAME=web \
            -e APACHE_RUN_DOCROOT="${ACCEPTANCE_ROOT}" -e PHPFPM_HOST=phpfpm -e PHPFPM_PORT=9000 \
            -v ${ROOT_DIR}:${ROOT_DIR} ${IMAGE_APACHE} >/dev/null
    fi
    waitFor ac-web-${SUFFIX} 80

    local DBPARAMS="-e typo3DatabaseDriver=pdo_sqlite"
    case ${DBMS} in
        mariadb|mysql)
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name mariadb-ac-${SUFFIX} --network ${NETWORK} -d -e MYSQL_ROOT_PASSWORD=funcp --tmpfs /var/lib/mysql/:rw,noexec,nosuid ${IMAGE_MARIADB} >/dev/null
            waitFor mariadb-ac-${SUFFIX} 3306
            DBPARAMS="-e typo3DatabaseDriver=${DATABASE_DRIVER:-mysqli} -e typo3DatabaseName=func_test -e typo3DatabaseUsername=root -e typo3DatabaseHost=mariadb-ac-${SUFFIX} -e typo3DatabasePassword=funcp"
            ;;
        postgres)
            ${CONTAINER_BIN} run --rm ${CI_PARAMS} --name postgres-ac-${SUFFIX} --network ${NETWORK} -d -e POSTGRES_PASSWORD=funcp -e POSTGRES_USER=postgres -e POSTGRES_DB=bamboo --tmpfs /var/lib/postgresql/data:rw,noexec,nosuid ${IMAGE_POSTGRES} >/dev/null
            waitFor postgres-ac-${SUFFIX} 5432
            DBPARAMS="-e typo3DatabaseDriver=pdo_pgsql -e typo3DatabaseName=bamboo -e typo3DatabaseUsername=postgres -e typo3DatabaseHost=postgres-ac-${SUFFIX} -e typo3DatabasePassword=funcp"
            ;;
    esac

    local COMMAND=(.Build/bin/codecept run -c codeception.yml --env headless "$@")
    ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name acceptance-${SUFFIX} ${DBPARAMS} ${CODECEPT_ENV} \
        -e typo3TestingAcceptanceBaseUrl=http://web:80 \
        -e typo3TestingAcceptanceAdminPassword=password \
        -e typo3TestingAcceptanceEditorPassword=password \
        ${IMAGE_PHP} "${COMMAND[@]}"
}

case ${TEST_SUITE} in
    unit)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-${SUFFIX} ${XDEBUG_MODE} -e XDEBUG_CONFIG="${XDEBUG_CONFIG}" ${IMAGE_PHP} \
            .Build/bin/phpunit -c "${UNIT_PHPUNIT_CONFIG}" "$@"
        SUITE_EXIT_CODE=$?
        ;;
    functional)
        runFunctional "$@"
        SUITE_EXIT_CODE=$?
        ;;
    phpstan)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name phpstan-${SUFFIX} ${IMAGE_PHP} \
            .Build/bin/phpstan analyse -c "${PHPSTAN_CONFIG}" --memory-limit=512M --no-progress "$@"
        SUITE_EXIT_CODE=$?
        ;;
    cgl)
        DRY_RUN_OPTIONS=""
        [ "${DRY_RUN}" -eq 1 ] && DRY_RUN_OPTIONS="--dry-run --diff"
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name cgl-${SUFFIX} ${IMAGE_PHP} \
            php -dxdebug.mode=off .Build/bin/php-cs-fixer fix -v ${DRY_RUN_OPTIONS} --config="${PHP_CS_FIXER_CONFIG}"
        SUITE_EXIT_CODE=$?
        ;;
    lintPhp)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-php-${SUFFIX} ${IMAGE_PHP} /bin/sh -c \
            "find Classes Tests -name '*.php' -print0 | xargs -0 -n1 -P4 php -dxdebug.mode=off -l >/dev/null"
        SUITE_EXIT_CODE=$?
        ;;
    lintYaml)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-yaml-${SUFFIX} ${IMAGE_PHP} /bin/sh -c \
            "find Configuration -name '*.yaml' -o -name '*.yml' | xargs -r .Build/bin/yaml-lint --parse-tags"
        SUITE_EXIT_CODE=$?
        ;;
    lintTypoScript)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-typoscript-${SUFFIX} ${IMAGE_PHP} \
            php Build/Scripts/lintTypoScript.php
        SUITE_EXIT_CODE=$?
        ;;
    lintHtml)
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-html-${SUFFIX} ${IMAGE_PHP} /bin/sh -c \
            "find Resources -name '*.html' -print0 | xargs -0 -r -I{} php -dxdebug.mode=off -l {} >/dev/null; \
             .Build/bin/typo3 fluid:analyze Resources/Private/Templates Resources/Private/Partials Resources/Private/Layouts 2>/dev/null || true"
        SUITE_EXIT_CODE=$?
        ;;
    lintScss)
        if [ "${ENABLE_FRONTEND_LINT:-0}" != "1" ] || [ -z "${FRONTEND_BUILD_DIR}" ]; then
            echo "Frontend lint is disabled for this package." >&2
            exit 1
        fi
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name lint-scss-${SUFFIX} -e HOME=${ROOT_DIR}/.cache ${IMAGE_NODEJS} /bin/sh -c \
            "cd ${FRONTEND_BUILD_DIR} && npm ci && npm run lint"
        SUITE_EXIT_CODE=$?
        ;;
    unitJavascript)
        if [ "${ENABLE_JS_UNIT:-0}" != "1" ] || [ -z "${FRONTEND_BUILD_DIR}" ]; then
            echo "JavaScript unit tests are disabled for this package." >&2
            exit 1
        fi
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name unit-js-${SUFFIX} -e HOME=${ROOT_DIR}/.cache ${IMAGE_NODEJS} /bin/sh -c \
            "cd ${FRONTEND_BUILD_DIR} && npm ci && npm run test:unit"
        SUITE_EXIT_CODE=$?
        ;;
    acceptance)
        runAcceptance "$@"
        SUITE_EXIT_CODE=$?
        ;;
    composerUpdate)
        if [ "${TYPO3_VERSION}" == "13" ]; then
            REQUIRE="typo3/cms-core:^13.4 typo3/cms-fluid:^13.4 typo3/cms-extbase:^13.4"
        else
            REQUIRE="typo3/cms-core:^14.3 typo3/cms-fluid:^14.3 typo3/cms-extbase:^14.3"
        fi
        ${CONTAINER_BIN} run ${CONTAINER_COMMON_PARAMS} --name composer-update-${SUFFIX} \
            -e COMPOSER_CACHE_DIR=.cache/composer -e COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION} ${IMAGE_PHP} /bin/sh -c \
            "composer require --no-update --no-ansi ${REQUIRE} && composer update --no-interaction --no-progress"
        SUITE_EXIT_CODE=$?
        ;;
    clean)
        rm -rf .cache .Build/public/typo3temp/var/tests Tests/Acceptance/_output Tests/Acceptance/Support/_generated .php-cs-fixer.cache
        SUITE_EXIT_CODE=0
        ;;
    update)
        ${CONTAINER_BIN} images "ghcr.io/typo3/core-testing-*" --format "{{.Repository}}:latest" 2>/dev/null | xargs -r -I {} ${CONTAINER_BIN} pull {} || true
        SUITE_EXIT_CODE=0
        ;;
    *)
        loadHelp
        echo "Invalid suite: ${TEST_SUITE}" >&2
        echo "${HELP}" >&2
        exit 1
        ;;
esac

cleanUp

echo ""
echo "###########################################################################"
echo "Result of ${TEST_SUITE}"
echo "PHP: ${PHP_VERSION} | TYPO3 target: ${TYPO3_VERSION}"
if [[ ${TEST_SUITE} =~ ^(functional|acceptance)$ ]]; then
    echo "DBMS: ${DBMS}"
fi
if [[ ${SUITE_EXIT_CODE} -eq 0 ]]; then
    echo "SUCCESS"
else
    echo "FAILURE"
fi
echo "###########################################################################"
echo ""

exit ${SUITE_EXIT_CODE}
