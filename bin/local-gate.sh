#!/usr/bin/env bash
#
# Полный quality gate локально, без GitHub Actions.
#
# Тесты слоя данных прогоняются на MySQL 5.7 в Docker — это та же версия,
# что стоит на test.23time.ru (5.7.21). Локальный MySQL разработчика обычно
# новее, и на нём не видно несовместимостей, которые ломают сервер: так уже
# был пойман implicit TIMESTAMP default (пакет 08.1C).
#
#   bin/local-gate.sh          — полный gate, включая MySQL 5.7 в Docker
#   bin/local-gate.sh --fast   — без Docker: всё, кроме тестов слоя данных
#
set -euo pipefail

cd "$(dirname "$0")/.."

FAST=0
[[ "${1:-}" == "--fast" ]] && FAST=1

CONTAINER="psytest-gate-mysql57"
DB_PORT=13357
DB_NAME="psytest_gate"
DB_ROOT_PASSWORD="gate"

RED=$'\033[31m'; GREEN=$'\033[32m'; DIM=$'\033[2m'; OFF=$'\033[0m'
FAILED=()

step() {
    local name="$1"; shift
    printf '%s… ' "$name"
    local output
    if output=$("$@" 2>&1); then
        printf '%sок%s\n' "$GREEN" "$OFF"
    else
        printf '%sПРОВАЛ%s\n' "$RED" "$OFF"
        printf '%s%s%s\n' "$DIM" "$output" "$OFF"
        FAILED+=("$name")
    fi
}

cleanup() {
    if [[ -n "${STARTED_CONTAINER:-}" ]]; then
        docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

echo "=== Статический контур ==="
step "composer validate" composer validate --strict --no-check-publish
step "composer audit   " composer audit
step "PHPStan level 6  " composer analyse
step "php-cs-fixer     " composer lint
step "архитектура      " php bin/check-architecture.php
step "PHPStan baseline " composer baseline:check

if [[ $FAST -eq 1 ]]; then
    echo
    echo "=== Тесты (без слоя данных) ==="
    step "PHPUnit fast     " composer test:fast
else
    echo
    echo "=== MySQL 5.7 в Docker (как на сервере) ==="

    if ! docker info >/dev/null 2>&1; then
        echo "${RED}Docker не запущен.${OFF} Запустите Docker Desktop или прогоните bin/local-gate.sh --fast"
        exit 1
    fi

    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    printf 'поднимаю mysql:5.7… '
    # У mysql:5.7 нет сборки под arm64, поэтому на Apple Silicon образ идёт
    # через эмуляцию amd64. Медленнее старт, но версия ровно серверная.
    docker run -d --name "$CONTAINER" \
        --platform linux/amd64 \
        -e MYSQL_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
        -e MYSQL_DATABASE="$DB_NAME" \
        -p "$DB_PORT:3306" \
        --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -p$DB_ROOT_PASSWORD" \
        --health-interval=2s --health-retries=40 \
        mysql:5.7 >/dev/null
    STARTED_CONTAINER=1

    # Контейнер отвечает на порт раньше, чем готов принимать запросы, поэтому
    # ждём именно healthcheck, а не открытый сокет.
    for _ in $(seq 1 60); do
        [[ "$(docker inspect -f '{{.State.Health.Status}}' "$CONTAINER" 2>/dev/null)" == "healthy" ]] && break
        sleep 2
    done

    if [[ "$(docker inspect -f '{{.State.Health.Status}}' "$CONTAINER" 2>/dev/null)" != "healthy" ]]; then
        echo "${RED}не поднялся${OFF}"
        docker logs --tail 20 "$CONTAINER" || true
        exit 1
    fi
    printf '%sготов%s\n' "$GREEN" "$OFF"

    export DB_HOST=127.0.0.1
    export DB_PORT="$DB_PORT"
    export DB_NAME="$DB_NAME"
    export DB_USER=root
    export DB_PASS="$DB_ROOT_PASSWORD"

    docker exec "$CONTAINER" mysql -uroot -p"$DB_ROOT_PASSWORD" \
        -e "SELECT VERSION()" 2>/dev/null | tail -1 | sed 's/^/версия в контейнере: /'

    step "миграции         " composer migrate
    step "PHPUnit полностью" composer test
fi

echo
if [[ ${#FAILED[@]} -eq 0 ]]; then
    printf '%sGate пройден.%s\n' "$GREEN" "$OFF"
    exit 0
fi

printf '%sGate не пройден: %s%s\n' "$RED" "${FAILED[*]}" "$OFF"
exit 1
