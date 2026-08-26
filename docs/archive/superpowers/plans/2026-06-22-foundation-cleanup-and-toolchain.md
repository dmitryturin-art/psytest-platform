# Фундамент: очистка файлов и настройка toolchain — План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Очистить репозиторий от дублей и мёртвого кода, настроить dev-toolchain (PHPUnit, PHPStan, PHP CS Fixer, Phinx) — фундамент, разблокирующий последующий рефакторинг.

**Architecture:** Чистка выполняется без изменения логики расчётов. Удаляются дублирующие JSON-файлы вопросов СМИЛ (источник истины — `questions-566-full.json`), мёртвые методы в SmilModule, избыточные/устаревшие markdown-документы. Параллельно в `composer.json` добавляются dev-зависимости с конфигурацией для статического анализа и тестов.

**Tech Stack:** PHP 8.1+, Composer, PHPUnit 10, PHPStan 1.11, PHP CS Fixer 3, Phinx.

## Global Constraints

- PHP 8.1+ (declare(strict_types=1) в новых PHP-файлах).
- PSR-4 автозагрузка, пространство имён `PsyTest\*`.
- НЕ изменять логику расчётов (сырые/T-баллы/валидность) — это план чистки и toolchain, математика трогается в Плане 3.
- НЕ удалять рабочий `questions-566-full.json` и `SmilModule.php` — только дубли и мёртвый код.
- Каждый шаг завершается запуском проверки (php -l / phpstan / phpunit) перед коммитом.
- Ветка: `refactor/technical-debt-phase1`.

---

## File Structure

**Удаляемые файлы (дубли/мёртвое):**
- `modules/smil/questions.json` — 50-вопросная демо, устарела.
- `modules/smil/questions-566-correct.json` — предок `-full`.
- `modules/smil/questions-566-gender.json` — предок `-full`.
- `modules/smil/questions-566-psytest.json` — предок `-full`.
- `modules/smil/questions-full-obsolete.json` — помечен obsolete в названии.

**Модифицируемые файлы:**
- `modules/smil/SmilModule.php` — удаление мёртвых методов `getTScoresMale()`/`getTScoresFemale()`/`lookupTScore()` + упрощение цепочки фолбэков в `getQuestions()`.
- `composer.json` — dev-зависимости и scripts.
- `.gitignore` — фикс блокировки `database/schema.sql`.

**Создаваемые файлы:**
- `phpunit.xml` — конфиг PHPUnit.
- `phpstan.neon` — конфиг PHPStan.
- `.php-cs-fixer.php` — конфиг стиля.
- `phinx.php` — конфиг миграций.
- `tests/` — директория тестов + `.gitkeep`.
- `docs/archive/` — для устаревших документов.

**Перемещаемые файлы:**
- `BAI-TEST.md`, `INSTALLED.md`, `PUBLISH.md`, `VSCODE.md`, `WORKLOG.md` → `docs/archive/`.
- `plans/00-09` → `docs/archive/plans/`.

---

### Task 1: Удаление дублирующих файлов вопросов СМИЛ

**Files:**
- Delete: `modules/smil/questions.json`, `modules/smil/questions-566-correct.json`, `modules/smil/questions-566-gender.json`, `modules/smil/questions-566-psytest.json`, `modules/smil/questions-full-obsolete.json`
- Modify: `modules/smil/SmilModule.php` (метод `getQuestions()`, строки ~211-233)

**Interfaces:**
- Consumes: ничего (чистое удаление).
- Produces: единственный источник вопросов `modules/smil/questions-566-full.json`, загружаемый без фолбэков.

- [ ] **Step 1: Проверить, что `questions-566-full.json` — рабочий источник**

Run:
```bash
php -r "require 'vendor/autoload.php'; \$m = new PsyTest\Modules\Smil\SmilModule(); echo count(\$m->getQuestions());"
```
Expected: `566`

- [ ] **Step 2: Удалить 5 дублирующих файлов**

Run:
```bash
git rm modules/smil/questions.json modules/smil/questions-566-correct.json modules/smil/questions-566-gender.json modules/smil/questions-566-psytest.json modules/smil/questions-full-obsolete.json
```
Expected: `rm ...` для каждого файла, без ошибок.

- [ ] **Step 3: Упростить `getQuestions()` — убрать цепочку фолбэков**

В `modules/smil/SmilModule.php` заменить метод `getQuestions()` (строки ~211-233) на:

```php
    public function getQuestions(): array
    {
        if ($this->questions === null) {
            $this->questions = $this->loadQuestionsFromJson('questions-566-full.json');
        }
        return $this->questions;
    }
```

- [ ] **Step 4: Проверить синтаксис и загрузку**

Run:
```bash
php -l modules/smil/SmilModule.php
php -r "require 'vendor/autoload.php'; \$m = new PsyTest\Modules\Smil\SmilModule(); echo count(\$m->getQuestions()) . PHP_EOL;"
```
Expected: `No syntax errors` и `566`.

- [ ] **Step 5: Запустить архитектурный smoke-тест**

Run: `php test-architecture.php`
Expected: секция "Проверка модуля СМИЛ" — `✓ Модуль загружается`, `✓ Расчёт результатов работает`, ошибок нет.

- [ ] **Step 6: Commit**

```bash
git add modules/smil/SmilModule.php
git commit -m "refactor(smil): remove duplicate question JSON files and fallback chain

questions-566-full.json is the single source of truth (566 items).
Removed: questions.json, questions-566-correct.json, questions-566-gender.json,
questions-566-psytest.json, questions-full-obsolete.json"
```

---

### Task 2: Удаление мёртвого кода T-таблиц в SmilModule

**Files:**
- Modify: `modules/smil/SmilModule.php` (удалить методы `getTScoresMale()`, `getTScoresFemale()`, `lookupTScore()`)

**Context:** Эти 3 метода (~75 строк) содержат неверные placeholder-таблицы и **нигде не вызываются**. Реальный расчёт T-баллов идёт через линейную формулу в `convertToTScores()`. Подтверждено grep'ом: единственные упоминания — определения методов.

**Interfaces:**
- Consumes: ничего.
- Produces: SmilModule без мёртвого кода (готов к рефакторингу калькуляторов в Плане 3).

- [ ] **Step 1: Подтвердить, что методы нигде не вызываются**

Run: `grep -rn "getTScoresMale\|getTScoresFemale\|lookupTScore" --include="*.php" .`
Expected: только строки `protected function ...` с определениями в `modules/smil/SmilModule.php` (3 совпадения), вызовов нет.

- [ ] **Step 2: Удалить три метода**

Удалить в `modules/smil/SmilModule.php`:
- `protected function getTScoresMale(): array { ... }` (строки ~668-686)
- `protected function getTScoresFemale(): array { ... }` (строки ~691-709)
- `protected function lookupTScore(int|string $scale, int $rawScore, array $tables): float { ... }` (строки ~714-743)

Также удалить phpdoc-комментарий перед `lookupTScore` ("Lookup T-score from table with interpolation").

- [ ] **Step 3: Проверить синтаксис**

Run: `php -l modules/smil/SmilModule.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Подтвердить отсутствие упоминаний**

Run: `grep -rn "getTScoresMale\|getTScoresFemale\|lookupTScore" --include="*.php" .`
Expected: пусто (0 совпадений).

- [ ] **Step 5: Smoke-тест**

Run: `php test-architecture.php`
Expected: секция СМИЛ — `✓ Расчёт результатов работает`, ошибок нет.

- [ ] **Step 6: Commit**

```bash
git add modules/smil/SmilModule.php
git commit -m "refactor(smil): remove dead T-score table code

getTScoresMale/Female() and lookupTScore() were never called. Real T-score
calculation uses linear formula in convertToTScores(). Removed ~75 lines of
incorrect placeholder tables."
```

---

### Task 3: Перемещение устаревших markdown-документов в archive

**Files:**
- Move: `BAI-TEST.md`, `INSTALLED.md`, `PUBLISH.md`, `VSCODE.md`, `WORKLOG.md` → `docs/archive/`
- Move: `plans/00-executive-summary.md` ... `plans/09-mmpi-dual-curve-visualization.md` → `docs/archive/plans/`

**Context:** В корне 10 `.md` файлов, многие устарели или дублируют `docs/`. В `plans/` — 10 пересекающихся документов тех. аудита. Сохраняем в `docs/archive/` (не удаляем безвозвратно), в корне оставляем только актуальные: `README.md`, `QUICKSTART.md`, `DEVELOPMENT.md`, `DEPLOYMENT.md`, `AGENTS.md`.

**Interfaces:**
- Consumes: ничего.
- Produces: чистый корень репозитория, исторические документы сохранены в `docs/archive/`.

- [ ] **Step 1: Создать директории archive**

Run:
```bash
mkdir -p docs/archive/plans
```
Expected: директории созданы.

- [ ] **Step 2: Переместить корневые .md в archive**

Run:
```bash
git mv BAI-TEST.md docs/archive/
git mv INSTALLED.md docs/archive/
git mv PUBLISH.md docs/archive/
git mv VSCODE.md docs/archive/
git mv WORKLOG.md docs/archive/
```
Expected: `Renamed ...` для каждого.

- [ ] **Step 3: Переместить plans/ в docs/archive/plans/**

Run:
```bash
git mv plans/00-executive-summary.md docs/archive/plans/
git mv plans/01-technical-audit-report.md docs/archive/plans/
git mv plans/02-refactoring-optimization-plan.md docs/archive/plans/
git mv plans/03-smil-validation-report.md docs/archive/plans/
git mv plans/04-project-status-report.md docs/archive/plans/
git mv plans/05-sob-01-analysis.md docs/archive/plans/
git mv plans/06-results-page-analysis.md docs/archive/plans/
git mv plans/07-implementation-roadmap.md docs/archive/plans/
git mv plans/08-current-status-and-next-steps.md docs/archive/plans/
git mv plans/09-mmpi-dual-curve-visualization.md docs/archive/plans/
```
Expected: `Renamed ...` для каждого.

- [ ] **Step 4: Удалить пустую директорию plans/**

Run: `rmdir plans`
Expected: директория удалена (после перемещения всех файлов пуста).

- [ ] **Step 5: Проверить, что ключевые ссылки в README не сломаны**

Run: `grep -n "QUICKSTART\|DEVELOPMENT\|DEPLOYMENT\|VSCODE\|WORKLOG\|INSTALLED\|PUBLISH\|plans/" README.md`
Expected: ссылки на QUICKSTART/DEVELOPMENT/DEPLOYMENT (остались в корне). Если есть ссылки на перемещённые файлы (VSCODE, WORKLOG и т.п.) — обновить пути на `docs/archive/...`.

При необходимости обновить `README.md` (секция "Документация", строки ~174-183): оставить только ссылки на существующие в корне файлы.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "docs: archive obsolete root markdown files and plans

Moved BAI-TEST, INSTALLED, PUBLISH, VSCODE, WORKLOG and plans/00-09 to
docs/archive/. Root keeps only live docs: README, QUICKSTART, DEVELOPMENT,
DEPLOYMENT, AGENTS."
```

---

### Task 4: Перенос source/ (эталонные PDF) вне кода

**Files:**
- Modify: `.gitignore` (добавить `/source/`)

**Context:** `source/` содержит эталонные PDF Собчик, psytests.org, скриншоты — справочные материалы, а не код. Должны храниться вне репозитория (или в git-LFS), но не в дереве исходников.

**Interfaces:**
- Consumes: ничего.
- Produces: `source/` больше не отслеживается git, остаётся на диске для справки.

- [ ] **Step 1: Проверить статус source/ в git**

Run: `git ls-files source/ | head -5`
Expected: список файлов (если отслеживается) ИЛИ пусто (если уже в .gitignore).

- [ ] **Step 2: Добавить /source/ в .gitignore**

В `.gitignore` после секции "IDE" добавить:
```
# Reference materials (Sobchik PDFs, psytests.org samples)
/source/
```

- [ ] **Step 3: Если source/ отслеживался — убрать из индекса (файлы остаются на диске)**

Run: `git rm -r --cached source/ 2>/dev/null || echo "source/ not tracked, skip"`
Expected: если отслеживался — `rm ...` для файлов; если нет — `source/ not tracked, skip`.

- [ ] **Step 4: Подтвердить, что файлы на диске на месте**

Run: `ls source/ | head -3`
Expected: список PDF/HTML файлов (физически не удалены).

- [ ] **Step 5: Commit**

```bash
git add .gitignore
git commit -m "chore: stop tracking source/ reference materials

PDFs (Sobchik, psytests.org samples) are reference materials, not source code.
Removed from git index, added to .gitignore. Files remain on disk."
```

---

### Task 5: Исправление .gitignore для database/schema.sql и коммит AGENTS.md

**Files:**
- Modify: `.gitignore` (правило `*.sql` блокирует `database/schema.sql`)
- Add: `AGENTS.md` (сейчас untracked)

**Context:** Правило `*.sql` в `.gitignore` (строка 43) блокирует `database/schema.sql`, который нужен в репозитории. `AGENTS.md` — конфигурация superpowers, должен отслеживаться.

**Interfaces:**
- Consumes: ничего.
- Produces: `database/schema.sql` корректно отслеживается, `AGENTS.md` в git.

- [ ] **Step 1: Проверить статус schema.sql**

Run: `git check-ignore database/schema.sql; echo "exit: $?"`
Expected: если проигнорирован — путь файла + `exit: 0`; если нет — `exit: 1`.

- [ ] **Step 2: Исправить правило *.sql в .gitignore**

В `.gitignore` найти секцию "Database backups":
```
# Database backups
*.sql
database/backups/
```
Заменить на:
```
# Database backups (keep schema, ignore dumps)
*.sql
!database/schema.sql
database/backups/
```

- [ ] **Step 3: Заставить schema.sql отслеживаться (если был проигнорирован)**

Run: `git add -f database/schema.sql 2>/dev/null; git status --short database/`
Expected: `A  database/schema.sql` (если только добавлен) ИЛИ ничего (если уже отслеживался).

- [ ] **Step 4: Закоммитить AGENTS.md**

`AGENTS.md` уже существует (untracked). Добавить:
Run: `git add AGENTS.md`

- [ ] **Step 5: Commit**

```bash
git add .gitignore AGENTS.md database/schema.sql
git commit -m "chore: fix .gitignore to track schema.sql; commit AGENTS.md

- *.sql rule was blocking database/schema.sql; added negation rule
- AGENTS.md (superpowers config) now tracked"
```

---

### Task 6: Добавление dev-зависимостей в composer.json

**Files:**
- Modify: `composer.json` (новая секция `require-dev`, обновление `scripts`)

**Interfaces:**
- Consumes: ничего.
- Produces: `vendor/bin/phpunit`, `vendor/bin/phpstan`, `vendor/bin/php-cs-fixer`, `vendor/bin/phinx` доступны. Команды `composer test`, `composer analyse`, `composer lint`, `composer migrate` работают.

- [ ] **Step 1: Обновить composer.json**

Заменить содержимое `composer.json` на:
```json
{
  "name": "psytest/platform",
  "description": "Modular web service for psychological testing",
  "type": "project",
  "license": "proprietary",
  "require": {
    "php": ">=8.1",
    "twig/twig": "^3.0",
    "dompdf/dompdf": "^3.0",
    "ramsey/uuid": "^4.7",
    "monolog/monolog": "^3.0"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "phpstan/phpstan": "^1.11",
    "friendsofphp/php-cs-fixer": "^3.54",
    "robmorgan/phinx": "^0.16"
  },
  "autoload": {
    "psr-4": {
      "PsyTest\\Core\\": "core/",
      "PsyTest\\Services\\": "services/",
      "PsyTest\\Modules\\": "modules/",
      "PsyTest\\Controllers\\": "controllers/",
      "PsyTest\\Modules\\Smil\\": "modules/smil/",
      "PsyTest\\Modules\\BeckAnxiety\\": "modules/beck-anxiety/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "PsyTest\\Tests\\": "tests/"
    }
  },
  "config": {
    "optimize-autoloader": true,
    "sort-packages": true
  },
  "scripts": {
    "test": "phpunit",
    "analyse": "phpstan analyse core controllers services modules --level=6",
    "lint": "php-cs-fixer fix --dry-run --diff",
    "lint:fix": "php-cs-fixer fix",
    "migrate": "phinx migrate",
    "migrate:create": "phinx create",
    "post-install-cmd": [
      "chmod +x bin/cleanup-sessions.php 2>/dev/null || true"
    ]
  }
}
```

- [ ] **Step 2: Установить зависимости**

Run: `composer install`
Expected: установка PHPUnit, PHPStan, CS Fixer, Phinx без ошибок.

- [ ] **Step 3: Проверить доступность инструментов**

Run:
```bash
vendor/bin/phpunit --version
vendor/bin/phpstan --version
vendor/bin/php-cs-fixer --version
vendor/bin/phinx --version
```
Expected: версии каждого инструмента выведены.

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "build: add dev toolchain (PHPUnit, PHPStan, CS Fixer, Phinx)

Composer scripts: test, analyse, lint, lint:fix, migrate.
Added autoload-dev for tests/ namespace."
```

---

### Task 7: Конфигурация PHPUnit

**Files:**
- Create: `phpunit.xml`
- Create: `tests/.gitkeep`

**Interfaces:**
- Consumes: `PsyTest\Tests\` namespace (из autoload-dev Task 6).
- Produces: `composer test` запускает пустой набор тестов (0 тестов, exit 0), готовый к добавлению тестов в Плане 3.

- [ ] **Step 1: Создать директорию tests**

Run: `mkdir -p tests`
Expected: директория создана.

- [ ] **Step 2: Создать phpunit.xml**

Создать файл `phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         executionOrder="depends,defects"
         requireCoverageMetadata="false"
         beStrictAboutCoverageMetadata="false"
         beStrictAboutOutputDuringTests="true"
         displayDetailsOnTestsThatTriggerWarnings="true"
         failOnRisky="true"
         failOnIncomplete="false">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>core</directory>
            <directory>services</directory>
            <directory>modules</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Создать tests/.gitkeep**

Создать пустой файл `tests/.gitkeep` (чтобы директория отслеживалась).

- [ ] **Step 4: Добавить .phpunit.cache в .gitignore**

В `.gitignore` в секцию "Temporary files" добавить:
```
.phpunit.cache/
```

- [ ] **Step 5: Запустить PHPUnit**

Run: `composer test`
Expected: `No tests executed!` и exit code 0.

- [ ] **Step 6: Commit**

```bash
git add phpunit.xml tests/.gitkeep .gitignore
git commit -m "build: configure PHPUnit with tests/ suite"
```

---

### Task 8: Конфигурация PHPStan

**Files:**
- Create: `phpstan.neon`

**Interfaces:**
- Consumes: конфигурация из `composer.json` scripts.
- Produces: `composer analyse` запускает статический анализ уровня 6.

- [ ] **Step 1: Создать phpstan.neon**

Создать файл `phpstan.neon`:
```neon
parameters:
    level: 6
    paths:
        - core
        - controllers
        - services
        - modules
    excludePaths:
        - */vendor/*
    tmpDir: .phpstan
```

- [ ] **Step 2: Добавить .phpstan в .gitignore**

В `.gitignore` в секцию "Temporary files" добавить:
```
.phpstan/
```

- [ ] **Step 3: Запустить анализ (baseline)**

Run: `composer analyse`
Expected: вывод ошибок уровня 6. **Это нормально для первого прогона** — фикс ошибок НЕ входит в этот план (может затронуть логику). Зафиксировать количество ошибок.

Run (для записи baseline, если ошибок много):
```bash
vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon
```
Expected: создан `phpstan-baseline.neon`, после повторного `composer analyse` ошибок меньше.

- [ ] **Step 4: Если создан baseline — подключить его**

Если на Step 3 создан `phpstan-baseline.neon`, добавить в `phpstan.neon`:
```neon
includes:
    - phpstan-baseline.neon
parameters:
    level: 6
    ...
```

Если ошибок мало (<=20) — baseline не нужен, фиксим позже.

- [ ] **Step 5: Commit**

```bash
git add phpstan.neon .gitignore phpstan-baseline.neon 2>/dev/null
git commit -m "build: configure PHPStan level 6 with baseline

Static analysis over core/controllers/services/modules. Baseline captures
existing errors to fix incrementally without blocking."
```

---

### Task 9: Конфигурация PHP CS Fixer

**Files:**
- Create: `.php-cs-fixer.php`

**Interfaces:**
- Consumes: конфигурация из `composer.json` scripts.
- Produces: `composer lint` проверяет стиль (dry-run), `composer lint:fix` исправляет.

- [ ] **Step 1: Создать .php-cs-fixer.php**

Создать файл `.php-cs-fixer.php`:
```php
<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/core')
    ->in(__DIR__ . '/controllers')
    ->in(__DIR__ . '/services')
    ->in(__DIR__ . '/modules')
    ->in(__DIR__ . '/tests')
    ->notPath('*/vendor/*');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    ->setFinder($finder);
```

- [ ] **Step 2: Запустить проверку стиля (dry-run)**

Run: `composer lint`
Expected: список файлов, требующих исправления (diff). **Фикс НЕ делаем в этом шаге** — только подтверждаем, что инструмент работает.

- [ ] **Step 3: Commit (без auto-fix — форматирование отдельной задачей)**

```bash
git add .php-cs-fixer.php
git commit -m "build: configure PHP CS Fixer (PSR-12 + strict types)

lint (dry-run) and lint:fix scripts. Code reformatting deferred to a
dedicated formatting commit to keep diffs reviewable."
```

---

### Task 10: Конфигурация Phinx (миграции БД)

**Files:**
- Create: `phinx.php`
- Create: `database/migrations/.gitkeep`

**Interfaces:**
- Consumes: переменные окружения из `.env` (DB_HOST, DB_NAME, DB_USER, DB_PASS).
- Produces: `composer migrate` запускает миграции, `bin/migrate` доступен.

- [ ] **Step 1: Создать директорию миграций**

Run: `mkdir -p database/migrations`
Expected: директория создана.

- [ ] **Step 2: Создать phinx.php**

Создать файл `phinx.php`:
```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$config = require __DIR__ . '/config.php';

$env = getenv('APP_ENV') ?: 'development';

return [
    'paths' => [
        'migrations' => __DIR__ . '/database/migrations',
    ],
    'environments' => [
        'default_migration_path' => __DIR__ . '/database/migrations',
        'default_environment' => $env,
        $env => [
            'name' => $config->getString('DB_NAME'),
            'connection' => null,
            'host' => $config->getString('DB_HOST'),
            'user' => $config->getString('DB_USER'),
            'pass' => $config->getString('DB_PASS'),
            'charset' => $config->getString('DB_CHARSET', 'utf8mb4'),
            'port' => 3306,
        ],
    ],
];
```

**Примечание:** Phinx использует PDO; если `config.php` не имеет геттера `getString` для всех ключей — использовать прямое чтение `getenv('DB_NAME')` и т.п. Проверить API `config.php` перед использованием.

- [ ] **Step 3: Проверить, что config.php поддерживает getString**

Run: `grep -n "function getString\|function get\b" config.php`
Expected: список методов-геттеров. Если `getString` отсутствует — адаптировать phinx.php под доступный API (например, `getenv()`).

- [ ] **Step 4: При необходимости упростить phinx.php на getenv()**

Если `config.php` API нестабилен, заменить блок окружения на:
```php
$env => [
    'adapter' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'localhost',
    'name' => getenv('DB_NAME') ?: 'psytest',
    'user' => getenv('DB_USER') ?: 'root',
    'pass' => getenv('DB_PASS') ?: '',
    'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    'port' => 3306,
],
```

- [ ] **Step 5: Создать database/migrations/.gitkeep**

Создать пустой файл `database/migrations/.gitkeep`.

- [ ] **Step 6: Проверить, что phinx инициализируется**

Run: `vendor/bin/phinx status`
Expected: `no migrations found` или подобное (без fatal error). Может требовать подключение к БД — если БД недоступна, ошибка подключения допустима (конфиг валиден).

- [ ] **Step 7: Commit**

```bash
git add phinx.php database/migrations/.gitkeep
git commit -m "build: configure Phinx migrations (database/migrations/)

Replaces manual schema.sql edits with versioned migrations. Initial
schema migration will be added in a later plan."
```

---

### Task 11: Финальная проверка и обновление README

**Files:**
- Modify: `README.md` (секция тестирования/разработки)

**Interfaces:**
- Consumes: все инструменты из Tasks 6-10.
- Produces: README отражает новые команды, корень чистый.

- [ ] **Step 1: Обновить секцию тестирования в README**

В `README.md` найти секцию "Тестирование" (строки ~217-229) и заменить на:
```markdown
## 🧪 Тестирование и качество

```bash
# Unit-тесты
composer test

# Статический анализ (PHPStan level 6)
composer analyse

# Проверка стиля кода (dry-run)
composer lint

# Авто-исправление стиля
composer lint:fix

# Миграции БД
composer migrate

# Smoke-тест архитектуры (без БД)
php test-architecture.php
```
```

- [ ] **Step 2: Удалить ссылки на перемещённые/несуществующие файлы**

Проверить README на упоминания `VSCODE.md`, `WORKLOG.md`, `INSTALLED.md`, `PUBLISH.md`, `BAI-TEST.md`, `plans/`, `test-smil.php`:
Run: `grep -n "VSCODE\|WORKLOG\|INSTALLED\|PUBLISH\|BAI-TEST\|plans/\|test-smil" README.md`
Ожидание: обновить найденные ссылки на `docs/archive/...` или удалить, если неактуальны.

- [ ] **Step 3: Полный прогон проверок**

Run:
```bash
php test-architecture.php
composer test
composer analyse
composer lint
```
Expected:
- `test-architecture.php`: без ошибок, `✓` в секциях.
- `composer test`: `No tests executed!`, exit 0.
- `composer analyse`: только baseline-ошибки (без новых).
- `composer lint`: список для форматирования (не блокирует).

- [ ] **Step 4: Проверить чистоту git status**

Run: `git status`
Expected: working tree clean (или только untracked служебные файлы `.phpunit.cache`, которые в .gitignore).

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs: update README with new toolchain commands

Reflects PHPUnit, PHPStan, CS Fixer, Phinx scripts. Removed references to
archived files."
```

---

## Критерии приёмки плана

- [ ] В `modules/smil/` один файл вопросов: `questions-566-full.json`.
- [ ] SmilModule не содержит `getTScoresMale/Female`, `lookupTScore`.
- [ ] Корень репозитория содержит только: `README.md`, `QUICKSTART.md`, `DEVELOPMENT.md`, `DEPLOYMENT.md`, `AGENTS.md`, `LICENSE` + код/конфиги.
- [ ] `source/` не отслеживается git, файлы на диске.
- [ ] `database/schema.sql` отслеживается (не игнорируется).
- [ ] `composer test` запускается (exit 0).
- [ ] `composer analyse` запускается (с baseline).
- [ ] `composer lint` запускается (показывает diff).
- [ ] `vendor/bin/phinx status` не падает с fatal.
- [ ] `php test-architecture.php` проходит без ошибок.
- [ ] Приложение работает: `php -r "require 'vendor/autoload.php'; \$m = new PsyTest\Modules\Smil\SmilModule(); echo count(\$m->getQuestions());"` → 566.

## Зависимости следующих планов

- **План 2 (Контракт):** использует PHPUnit/PHPStan из этого плана для тестирования нового `TestModuleInterface`.
- **План 3 (Корректность СМИЛ):** использует PHPUnit для тестов расчётов на эталонах, опирается на очищенный SmilModule.
- **План 4 (BDI/HADS):** опирается на контракт из Плана 2.
