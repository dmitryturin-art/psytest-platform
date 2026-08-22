# Staging на Beget: фактическое обследование

Статус: **release подготовлен вне web root 2026-08-22; публичная заглушка не переключена**.

Цель — закрытый бесплатный пилот PsyTest на `test.23time.ru`. Платежи, внешний AI, купоны и пользовательские аккаунты не включаются.

## Проверенные факты

| Область | Фактическое состояние |
|---|---|
| Хостинг | виртуальный shared hosting Beget |
| Web root | `~/test.23time.ru/public_html` |
| Web server | `nginx-reuseport/1.21.1` перед PHP handler |
| PHP | web и отдельный CLI `/usr/local/bin/php8.3` — 8.3.20; default CLI `php` — 5.6 и не используется |
| PHP extensions | доступны `mbstring`, `pdo_mysql`, `dom`, `xml`, `curl`, `openssl`, `zip`, `intl`, `sodium` |
| Database | отдельная пустая staging DB доступна; server — MySQL 5.7.21, charset database пока `utf8` |
| Composer | системный Composer 1; deployment artifact должен включать локально собранный `vendor/` |
| Инструменты | Git 2.42, `tar`, `unzip` и `rsync` доступны |
| Cron | `crontab` в SSH shell отсутствует; retention job настраивается через панель/Beget API |
| Web root сейчас | стандартная Beget-заглушка; файлов PsyTest нет |
| HTTPS | HTTP отвечает; HTTPS на момент проверки недоступен |
| Права | отдельный SSH account имеет read/write ACL на каталог сайта |
| Неактивный release | `releases/e2113ab`, production dependencies без dev tools; checksum `2c5d055c…d5a30` |
| Rollback evidence | исходный `public_html` сохранён как `backups/public-html-predeploy-20260822.tar.gz`; публичный `index.php` не изменился |

SSH/DB логины, пароли и другие секреты намеренно не записываются в этот документ.

## Подтверждённая топология

```text
test.23time.ru
  -> ~/test.23time.ru/public_html   # только содержимое project/public
  -> ~/test.23time.ru               # core/modules/vendor/config вне web root
  -> отдельная staging database
```

Корень домена доступен deployment account по ACL, поэтому текущие относительные
пути `public/index.php` сохраняются без публичного размещения исходников и без
bootstrap-адаптера. Приложение не встраивается в WordPress и не использует его
базу. YooKassa/WooCommerce не входят в бесплатный staging.

## Блокеры до активации

1. Выпустить для `test.23time.ru` бесплатный Let's Encrypt в панели Beget и проверить HTTPS.
2. ~~Проверить migration chain и runtime tests на MySQL 5.7.~~ Выполнено в 08.1C: MySQL 5.7 и 8.0 проходят полный CI.
3. ~~Исправить `.htaccess` для прямого document root.~~ Выполнено в 08.1B и защищено regression-тестом.
4. ~~Подготовить artifact с production dependencies.~~ 08.1D: архив собран локально из lockfile, checksum совпал после загрузки; Phinx включён, dev tools и `.env` отсутствуют.
5. Создать server `.env` вне Git с `APP_ENV=production`, `APP_DEBUG=false`, HTTPS URL, staging DB и Argon2id hash владельца.
6. До приглашения участников установить Basic Auth поверх HTTPS; файл паролей хранить рядом с `public_html`, не внутри него.
7. Настроить ежедневный cleanup через панель Beget с явным `/usr/local/bin/php8.3`.

## Запреты до снятия блокеров

- не заменять Beget-заглушку приложением;
- не передавать Basic Auth по обычному HTTP;
- не подключать WordPress/WooCommerce DB;
- не использовать default CLI `php` или системный Composer для migrations/build;
- не включать оплату, webhook или внешний AI;
- не копировать локальный `.env`, реальные PDF, ответы или логи.

## Активация после HTTPS

1. Создать `releases/e2113ab/.env` с mode `600`: production/debug false, staging DB, пустые payment/AI credentials; owner dashboard остаётся выключенным без отдельного Argon2id hash.
2. Проверить config и DB connection через `/usr/local/bin/php8.3`, затем применить migrations из release.
3. Настроить Basic Auth только поверх HTTPS, сохранив password file вне release/public.
4. Переключить `public_html` на `releases/e2113ab/public`, выполнить health/tests/result smoke на desktop и mobile.
5. При failed smoke вернуть исходный `public_html` из predeploy backup; release и пустую/тестовую DB не выдавать за production.

Следующий безопасный пакет: 08.1E — server config, migration и закрытая активация после подтверждения HTTPS.
