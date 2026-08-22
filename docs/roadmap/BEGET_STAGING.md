# Staging на Beget: фактическое обследование

Статус: **staging активирован 2026-08-22; проходит проверку владельца**.

Цель — бесплатный тестовый запуск PsyTest на `test.23time.ru`. По D-029 Basic Auth не используется; платежи, внешний AI, купоны и пользовательские аккаунты не включаются.

## Проверенные факты

| Область | Фактическое состояние |
|---|---|
| Хостинг | виртуальный shared hosting Beget |
| Web root | `~/test.23time.ru/public_html` |
| Web server | `nginx-reuseport/1.21.1` перед PHP handler |
| PHP | web и отдельный CLI `/usr/local/bin/php8.3` — 8.3.20; default CLI `php` — 5.6 и не используется |
| PHP extensions | доступны `mbstring`, `pdo_mysql`, `dom`, `xml`, `curl`, `openssl`, `zip`, `intl`, `sodium` |
| Database | отдельная staging DB на MySQL 5.7.21; 7 migrations / 10 tables, pre-migration dump сохранён |
| Composer | системный Composer 1; deployment artifact должен включать локально собранный `vendor/` |
| Инструменты | Git 2.42, `tar`, `unzip` и `rsync` доступны |
| Cron | `crontab` в SSH shell отсутствует; retention job настраивается через панель/Beget API |
| Web root сейчас | symlink на `releases/398ca23/public`; release `2f8f821` сохранён для rollback |
| HTTPS | Let's Encrypt активен; HTTP получает `301` на HTTPS через versioned `.htaccess` |
| Права | отдельный SSH account имеет read/write ACL на каталог сайта |
| Активный release | `releases/398ca23`, production dependencies без dev tools; archive checksum `40177947…ddc0` |
| Rollback evidence | исходный `public_html` сохранён каталогом и архивом `backups/public-html-predeploy-20260822.tar.gz` |

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

## Пройденные activation gates

1. Let's Encrypt выпущен; app-level redirect независимо подтверждает HTTP `301` → HTTPS.
2. ~~Проверить migration chain и runtime tests на MySQL 5.7.~~ Выполнено в 08.1C: MySQL 5.7 и 8.0 проходят полный CI.
3. ~~Исправить `.htaccess` для прямого document root.~~ Выполнено в 08.1B и защищено regression-тестом.
4. ~~Подготовить artifact с production dependencies.~~ 08.1D: архив собран локально из lockfile, checksum совпал после загрузки; Phinx включён, dev tools и `.env` отсутствуют.
5. Server `.env` создан с mode `600`, `APP_ENV=production`, `APP_DEBUG=false`; payment/AI выключены. Owner dashboard остаётся выключенным без Argon2id hash.
6. ~~Установить Basic Auth.~~ Владелец отменил это требование для текущего staging (D-029); HTTPS остаётся обязательным.
7. Clean migrations применены; Phinx status показывает все 7 migrations как `up`.

## Оставшиеся эксплуатационные задачи

- настроить ежедневный cleanup через панель Beget с явным `/usr/local/bin/php8.3`;
- сменить SSH/DB credentials после deployment-сессии и обновить server `.env` без передачи новых секретов в чат;
- не включать payment, AI или owner dashboard до их отдельных этапов;
- использовать только synthetic/добровольно введённые данные, пока staging не принят как production.

## Выполненная активация

1. Release `2f8f821` собран из lockfile, проверен CI на MySQL 5.7/8.0 и распакован без xattr warnings.
2. Config/DB проверены через PHP 8.3; migrations применены после pre-migration dump.
3. `public_html` атомарно переключён на release; исходный каталог не удалён.
4. HTTP/HTTPS/health/routes/security headers и desktop/mobile layout проверены.
5. Synthetic BDI на mobile прошёл 21/21 и сформировал бесплатный результат `0/63`; нет validation error, horizontal overflow или console errors.
6. 08.1F атомарно переключил staging на `398ca23`: `/tests` — `200`, HTTP — `301`, health — `ok`; `Set-Cookie` содержит `Secure`, `HttpOnly`, `SameSite=Lax`, а каждый dynamic security header приходит один раз. Static assets обслуживает внешний nginx Beget: он отдаёт корректный MIME/cache, но не применяет Apache `.htaccess` headers к статике.

Rollback текущего релиза: атомарно направить `public_html` обратно на `releases/2f8f821/public`; исходная Beget-заглушка и DB dump дополнительно находятся в `backups/`. Следующий шаг — проверка владельца и короткий пилот.
