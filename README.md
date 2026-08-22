# PsyTest Platform

Модульная PHP-платформа для психологического тестирования: прохождение без обязательной регистрации, бесплатный базовый результат, парные сценарии и печатные отчёты.

Тестовый стенд: [test.23time.ru](https://test.23time.ru/tests). Оплата и AI-интерпретации на нём пока выключены.

[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Status](https://img.shields.io/badge/status-active%20refactoring-D97706)](ROADMAP.md)
[![License](https://img.shields.io/badge/license-proprietary-374151)](LICENSE)

> Проект находится в активной инженерной переработке после независимого аудита. Бесплатные тесты и базовые результаты уже реализованы; платная интерпретация, YooKassa и кабинет терапевта проектируются заново и пока не считаются готовыми к публичному запуску.

## Что уже есть

- пять модулей: СМИЛ, шкалы тревоги и депрессии Бека, HADS и опросник Lazarus;
- единый каталог, прохождение теста и страница бесплатного результата;
- классический профиль СМИЛ с L/F/K, разрывом и шкалами 1–9/0;
- индивидуальный и парный сценарий Lazarus;
- PDF/print-вывод результатов;
- автоматический quality gate на PHP 8.3 с MySQL 5.7 и 8.0, включая миграции, тесты, PHPStan, форматирование и архитектурную проверку.

## Куда развивается продукт

Все тесты и их базовые результаты остаются бесплатными. Платным продуктом будет только необязательный расширенный разбор методик, где он действительно полезен: сначала СМИЛ и Lazarus.

Планируется:

- три варианта разбора: понятный, профессиональный или оба;
- цена по умолчанию 120 ₽, управляемая владельцем;
- YooKassa с чеками, идемпотентными webhook и одноразовыми купонами на 100%;
- кабинет терапевта с профессиональной и клиентской редакциями отчёта;
- настраиваемые AI-провайдер, модель и versioned prompts для каждого теста/режима;
- профессиональная комплектация СМИЛ примерно из 110 проверенных дополнительных шкал;
- современная дизайн-система с сохранением канонического графика СМИЛ.

Полная программа, текущий статус и критерии готовности: [ROADMAP.md](ROADMAP.md). Файловый индекс этапов: [docs/roadmap/README.md](docs/roadmap/README.md).

## Текущая готовность

| Область | Состояние |
|---|---|
| Бесплатное прохождение и базовый результат | реализовано; audited baseline проходит при доступной тестовой MySQL |
| Базовые расчёты СМИЛ и Lazarus | защищены от бездоказательных изменений |
| Дополнительные шкалы СМИЛ | требуют источников и независимой верификации |
| Происхождение и права методик | [реестр создан](docs/roadmap/METHODOLOGY_REGISTRY.md); для всех текущих форм статус пока unverified |
| Платная интерпретация | legacy-flow отключается и будет заменён |
| YooKassa и чеки | запланированы; старый YooMoney-код не считается рабочей интеграцией |
| Кабинет владельца и lifecycle кейсов | реализован минимальный защищённый `/admin`; AI-отчёты и полный кабинет терапевта запланированы |
| Production deployment | не выполнен; предусмотрены staging, backup и rollback gates |

Известные security/privacy/payment проблемы не скрываются: они перечислены в [техническом аудите](docs/audit/2026-08-15-agent-implementation-plan.md) и [матрице закрытия](docs/roadmap/AUDIT_TRACEABILITY.md).

## Технологии

- PHP 8.3+, Twig 3;
- MySQL 5.7 или 8.0 / InnoDB (обе версии проверяются чистой цепочкой migrations в CI);
- PDO, Monolog, Ramsey UUID;
- Dompdf для PDF;
- PHPUnit 10, PHPStan 1, PHP CS Fixer, Phinx.

## Структура

```text
controllers/          HTTP orchestration
core/                 router, database, module loading, shared result model
modules/              isolated test modules and their data
services/             PDF, AI, payment and email integrations
templates/            Twig views and result blocks
public/               the only intended web document root
database/             schema and Phinx migrations
tests/                unit, integration and reference fixtures
docs/audit/            owner review and technical audit
docs/roadmap/          rules, phases, decisions, status and project memory
```

Фактическое устройство подробно описано в [ARCHITECTURE.md](ARCHITECTURE.md). Документ обновляется вместе с архитектурными изменениями; желаемое состояние хранится только в roadmap.

## Локальный запуск

### Требования

- PHP 8.3 с расширениями, нужными Composer-зависимостям;
- Composer;
- MySQL 5.7 или 8.0;
- Apache/Nginx либо встроенный PHP server для разработки.

### Установка

```bash
git clone https://github.com/dmitryturin-art/psytest-platform.git
cd psytest-platform
composer install
cp .env.example .env
```

Заполните локальные `DB_*` в `.env`, создайте пустую базу и примените versioned migrations:

```bash
composer migrate
```

`bin/install-db.php` использует legacy snapshot `database/schema.sql` и не является рекомендуемым способом создания новой среды.

Для локальной проверки:

```bash
php -S 127.0.0.1:8000 -t public
```

Каталог откроется по адресу `http://127.0.0.1:8000/tests`.

`.env` содержит секреты и не должен попадать в Git. Для production встроенный PHP server не используется, а document root должен указывать только на `public/`.

## Проверки

```bash
composer validate --strict --no-check-publish
composer audit
composer test
composer analyse
composer lint
php bin/check-architecture.php
```

PHPStan пока использует ограниченный baseline из 148 исторических сообщений — его рост запрещён отдельной проверкой. `composer audit`, architecture checker и GitHub quality gate сейчас проходят; это всё равно не означает production readiness без следующих privacy, payment, UX и deployment gates. Актуальные evidence публикуются в [STATUS.md](docs/roadmap/STATUS.md) и [WORKLOG.md](docs/roadmap/WORKLOG.md).

## Добавление теста

Сейчас модули располагаются в `modules/<slug>/` и содержат PHP-класс, metadata и данные методики. Действующий контракт ещё имеет специальные случаи и будет заменён Module API v2 на этапе 03.

До этой миграции используйте [руководство по созданию теста](docs/creating-new-test.md) вместе с [архитектурой](ARCHITECTURE.md), но обязательно добавляйте:

- источник и правовой статус методики;
- серверную валидацию ответов;
- независимые reference fixtures для scoring;
- бесплатную базовую интерпретацию;
- accessibility, mobile и print/PDF проверки;
- paid-interpretation capability только при реальной пользе разбора.

## Документация проекта

| Документ | Для чего |
|---|---|
| [Ревью для владельца](docs/audit/2026-08-15-owner-review.md) | состояние проекта простым языком |
| [Технический аудит](docs/audit/2026-08-15-agent-implementation-plan.md) | конкретные defects, решения и инструкции агенту |
| [Roadmap](ROADMAP.md) | этапы, статусы, gates и контрольные точки |
| [Продуктовые правила](docs/roadmap/PRODUCT_RULES.md) | что именно строится и что нельзя исказить |
| [Инженерные правила](docs/roadmap/ENGINEERING_RULES.md) | ветки, проверки, evidence и definition of done |
| [Changelog](CHANGELOG.md) | изменения понятным владельцу языком |
| [Worklog](docs/roadmap/WORKLOG.md) | техническая история действий и проверок |
| [Checkpoint](docs/roadmap/CHECKPOINT.md) | продолжение после паузы без потери контекста |

## Вклад и ветки

Разработка идёт небольшими проверяемыми пакетами в ветках `codex/<phase>-<slug>`. Перед изменениями прочитайте [AGENTS.md](AGENTS.md), активный phase-файл и [текущий статус](docs/roadmap/STATUS.md).

Не добавляйте реальные ответы респондентов, PDF клиентов, API-ключи, `.env`, дампы базы или production-логи в issues, commits и fixtures.

## Лицензия

Проприетарное программное обеспечение. Условия — в [LICENSE](LICENSE). Права на конкретные психодиагностические методики, тексты вопросов и нормативные данные проверяются отдельно перед публичным использованием.
