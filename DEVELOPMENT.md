# Разработка PsyTest

Статус: **актуальное руководство для локальной разработки на 2026-08-24**. Оно не заменяет [AGENTS.md](AGENTS.md), [ROADMAP.md](ROADMAP.md), active phase и фактический код. Архитектурная карта — в [ARCHITECTURE.md](ARCHITECTURE.md).

## Перед началом

1. Прочитайте [текущий статус](docs/roadmap/STATUS.md), active phase и [правила продукта](docs/roadmap/PRODUCT_RULES.md).
2. Не работайте прямо в `main`: один work package — одна ветка `codex/<phase>-<slug>`.
3. Не меняйте scoring, нормы, вопросы или канонический СМИЛ-график без отдельного доказательного пакета и владельческого решения.
4. Не добавляйте в Git `.env`, ключи, ответы респондентов, PDF, дампы БД или production logs.

## Требования

- PHP 8.3+ с расширениями, нужными Composer-зависимостям;
- Composer 2;
- MySQL 5.7 или 8.0 / InnoDB;
- web server с document root, указывающим **только** на `public/`.

Локальный PHP может быть новее 8.3, но Composer lock и CI проверяются против PHP 8.3. Встроенный PHP server пригоден только для локальной разработки.

## Локальный запуск

```bash
composer install
cp .env.example .env
# заполните только локальные DB_* значения в .env
composer migrate
php -S 127.0.0.1:8000 -t public
```

Откройте `http://127.0.0.1:8000/tests`. В production не используйте встроенный server и не направляйте document root в корень репозитория.

`config.php` читает `.env` и environment variables. `DB_*`, `APP_*`, `SESSION_TTL_DAYS`, `ANONYMOUS_RETENTION_DAYS`, `CSRF_ENABLED`, `PDF_STORAGE_PATH` и logging configuration имеют текущий смысл. Мини-кабинет владельца остаётся выключенным, пока server environment не задаст `OWNER_DASHBOARD_PASSWORD_HASH` как Argon2id hash; пароль или hash не попадают в Git (как задать и как поменять — раздел «Пароль кабинета владельца» ниже). Legacy `YOOMONEY_*` и `OPENROUTER_*` не включают payment или AI: public routes retired, новый YooKassa/AI design не реализован.

## Пароль кабинета владельца

Кабинет `/admin` включается ровно одним значением в **серверном** `.env` — Argon2id-хэшем.
Пароль не хранится нигде: ни в Git, ни в базе, ни в логах. Поэтому «сменить пароль» = заменить одну строку.

```bash
php bin/owner-password.php          # спросит пароль дважды, ввод не отображается
```

Скрипт печатает готовую строку вида `OWNER_DASHBOARD_PASSWORD_HASH=$argon2id$...`. Её нужно
положить вместо старой строки в `.env` на сервере (`~/current/.env`, права `600`) — новый пароль
действует со следующего запроса, перезапуск не нужен. Уже открытая сессия в кабинете не рвётся:
чтобы разлогинить себя, нажмите «Выйти» или дождитесь `OWNER_DASHBOARD_SESSION_TTL_MINUTES`.

Для скриптов есть неинтерактивный режим: `echo 'пароль' | php bin/owner-password.php --stdin`
(в интерактивной оболочке так делать не стоит — пароль останется в истории команд).

Проверки, которые скрипт делает до вывода строки: PHP-сборка действительно даёт Argon2id,
хэш подтверждает исходный пароль, пустой пароль отклоняется. Пароль короче 8 символов
не блокируется, но выводится предупреждение: страница входа публична, защита — только
ограничение попыток (`OWNER_DASHBOARD_LOGIN_MAX_ATTEMPTS` за `OWNER_DASHBOARD_LOGIN_WINDOW_MINUTES`).

Смены пароля из самого кабинета намеренно нет: это потребовало бы, чтобы веб-приложение
писало в собственный `.env`, то есть имело право менять свою конфигурацию во время работы.
На shared-хостинге это лишний риск ради экономии одной команды.

## Полный quality gate

Одна команда вместо семи:

```bash
bin/local-gate.sh          # полный gate, тесты слоя данных на MySQL 5.7 в Docker (~20 сек)
bin/local-gate.sh --fast   # без Docker: всё, кроме тестов слоя данных (~6 сек)
```

Скрипт печатает построчный статус, при провале показывает вывод упавшей проверки и
возвращает ненулевой код. Контейнер поднимается и удаляется сам, `.env` проекта не трогается —
подключение к контейнеру идёт через `DB_PORT`.

**Почему MySQL 5.7, а не локальная база.** На `test.23time.ru` стоит MySQL 5.7.21, а у разработчика
локально обычно новее. На новой версии не видно несовместимостей, которые ломают сервер: так уже
был пойман implicit TIMESTAMP default, из-за которого миграции падали именно на 5.7 (пакет 08.1C).
Поэтому тесты слоя данных гоняются на серверной версии. Образ `mysql:5.7` не собран под arm64,
на Apple Silicon он идёт через эмуляцию amd64 — это заметно только на первом старте.

Те же проверки по отдельности, если нужно запустить одну:

```bash
composer validate --strict --no-check-publish
composer audit
composer test
composer analyse
composer lint
php bin/check-architecture.php
composer baseline:check
git diff --check
```

`composer migrate` обязателен при изменении migrations. Для UI нужен browser check на desktop и 390×844; для платежей — sandbox fixtures до отдельного production gate. Не заявляйте зелёный gate без свежего вывода.

Если локальная MySQL недоступна, `composer test:fast` выполняет все тесты, не обращающиеся к базе; это не заменяет `composer test:database` после `composer migrate`.

GitHub Actions остаётся как независимая запись в pull request: fast gate всегда, матрица MySQL 5.7/8.0 — для migrations, persistence-кода, DB-тестов и зависимостей; push в `main` выполняет обе DB-конфигурации. Но ждать его не нужно: `bin/local-gate.sh` прогоняет тот же набор проверок на серверной версии MySQL за секунды и не зависит от доступности чужой инфраструктуры.

## Текущий HTTP flow

`public/index.php` создаёт Router, ModuleLoader и SessionManager, применяет security headers и CSRF middleware. Реестр routes в [ARCHITECTURE.md](ARCHITECTURE.md#публичные-маршруты); source of truth — `public/index.php`.

```text
GET /test/{slug}                  -> новая test session
POST /test/{slug}/save            -> validation и autosave
POST /test/{slug}/submit          -> validation, scoring, result redirect
GET /result/{slug}/{token}        -> базовый результат
GET /result/{slug}/{token}/pdf    -> PDF результата
POST /result/{token}/delete       -> public soft-delete
```

Result token — bearer credential. Не логируйте его и не используйте `partner_token` как альтернативный key доступа. Все browser POST проходят CSRF check.

## Модули

Текущие методики: СМИЛ, BDI, HADS, BAI и Lazarus. `ModuleLoader` находит классы в `modules/` и регистрирует их по `metadata.slug`; directory name не всегда равен slug.

Перед изменением существующего модуля:

1. Найдите tests и golden/reference fixtures модуля.
2. Сначала воспроизведите дефект или добавьте regression test.
3. Не меняйте expected psychometric values без источника, объяснения и одобрения владельца.
4. Не добавляйте вопросы, нормы, дополнительные шкалы или public interpretation без записи в [реестре методик](docs/roadmap/METHODOLOGY_REGISTRY.md).

Добавление новой методики относится к этапу 03. [docs/creating-new-test.md](docs/creating-new-test.md) — исторический черновик, не действующая инструкция для нового модуля.

## Данные, удаление и кризисный signal

Current data map: [DATA_MAP_CURRENT.md](docs/roadmap/DATA_MAP_CURRENT.md). Anonymous clinical sessions удаляются lifecycle job после 180 дней от `created_at`; public delete — soft-delete, а не обещание немедленного уничтожения всех artifacts. Завершённую anonymous session владелец может явно назначить в `/admin` как `therapist_case`; такой кейс удаляется там же физически с подтверждением. Полный кабинет терапевта и AI-отчёты всё ещё не реализованы.

BDI item 9 формирует machine-readable `ClinicalSafetySignal`; при значении выше нуля результат показывает утверждённое generic-сообщение без телефонов, ссылок, страны, country resources или IP/GeoIP. Не расширяйте этот flow конкретными ресурсами или географией без нового решения владельца и отдельной проверки источников.

## Legacy integrations

Не подключайте legacy `PaymentService`, `AIInterpretationService`, `ApiController` payment methods или `interpretation-*` templates к public flow. `/interpretation/{token}`, `/interpretation/{token}/pay` и `/webhook/yoomoney` обязаны оставаться `410 Gone` до самостоятельных этапов:

- YooKassa, цена, купоны и чек — [этап 06](docs/roadmap/phases/06-orders-coupons-yookassa.md);
- AI reports, consent и кабинет терапевта — [этап 07](docs/roadmap/phases/07-ai-reports-therapist-office.md).

## Документация и коммиты

После пакета обновляйте `STATUS.md`, active phase, `AUDIT_TRACEABILITY.md`, `WORKLOG.md`, а при изменении фактического устройства — `ARCHITECTURE.md`. При явной команде «сделай checkpoint» действуйте по `CHECKPOINT.md`. `CHANGELOG.md` содержит только проверенный эффект для владельца.

Проверьте перед коммитом:

```bash
git status --short
git diff --check
git diff --cached
```

Формат коммита: `type(scope): result`, например `fix(session): reject invalid result token`. Не смешивайте security, payment, psychometrics и массовую UI-уборку.

## Что не следует считать готовым

- YooKassa, чеки, купоны и paid CTA;
- внешний AI, provider choice, consent capture и AI-report delivery;
- кабинет терапевта и добровольный пользовательский аккаунт;
- public crisis resource flow;
- production deployment, backup/restore drill и rollback;
- Module API v2, новая design system и verified additional SMIL scales.

Последовательность и критерии определяет [ROADMAP.md](ROADMAP.md). Если документация противоречит коду, не угадывайте: зафиксируйте расхождение в active phase и исправьте current-state документ в том же work package.
