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
| Database | отдельная staging DB на MySQL 5.7.21; 8 migrations; свежий pre-migration dump сохранён |
| Composer | системный Composer 1; deployment artifact должен включать локально собранный `vendor/` |
| Инструменты | Git 2.42, `tar`, `unzip` и `rsync` доступны |
| Cron | `crontab` в SSH shell отсутствует; retention job настраивается через панель/Beget API |
| Web root сейчас | symlink на `releases/3a2daa8/public`; release `5da9ab5` сохранён для rollback |
| HTTPS | Let's Encrypt активен; HTTP получает `301` на HTTPS через versioned `.htaccess` |
| Права | отдельный SSH account имеет read/write ACL на каталог сайта |
| Активный release | `releases/3a2daa8`, production dependencies без dev tools |
| Rollback evidence | исходный `public_html` сохранён каталогом и архивом `backups/public-html-predeploy-20260822.tar.gz` |

SSH/DB логины, пароли и другие секреты намеренно не записываются в этот документ.

## Подтверждённая топология

```text
test.23time.ru
  -> ~/test.23time.ru/public_html   # только содержимое project/public
  -> ~/test.23time.ru               # core/modules/vendor/config вне web root
  -> отдельная staging database
```

## Сборка релиза

Артефакт собирается только скриптом `bin/build-release.sh` (якорные exclude-паттерны
и автоматическая сверка `git ls-files public` с содержимым артефакта — защита после
инцидента L-015 с выпавшей фоновой сеткой графика СМИЛ). Ручная сборка rsync-командой
запрещена.

Полная последовательность выкладки (все шаги обязательны):

1. `bin/build-release.sh` → `tmp/release-<sha>.tar.gz` + sha256.
2. Загрузить в `backups/`, сверить sha256 на сервере.
3. Распаковать в `releases/<sha>/`, скопировать `.env` из предыдущего релиза (`chmod 600`).
4. Pre-migration dump → `backups/pre-deploy-<sha>.sql.gz`.
5. `/usr/local/bin/php8.3 vendor/bin/phinx migrate -c phinx.php`.
6. Атомарное переключение: `ln -sfn releases/<sha>/public public_html.new && mv -T public_html.new public_html`.
7. Стабильная точка для cron: `ln -sfn releases/<sha> current`.
8. Smoke: `/api/health`, главная, страница результата, логи ошибок пусты.

Rollback: вернуть `public_html` на `releases/<предыдущий>/public`; дампы и прежние
релизы сохраняются в `backups/` и `releases/`.

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
7. Clean migrations применены; Phinx status показывает все 8 migrations как `up`.

## Оставшиеся эксплуатационные задачи

- настроить ежедневный cleanup через панель Beget с явным `/usr/local/bin/php8.3`;
- смена SSH/DB credentials не проводится — владелец 25.08 подтвердил, что ротацию не заказывал; пересматривается только при признаках компрометации;
- не включать payment, AI или owner dashboard до их отдельных этапов;
- использовать только synthetic/добровольно введённые данные, пока staging не принят как production.

## Выполненная активация

1. Release `2f8f821` собран из lockfile, проверен CI на MySQL 5.7/8.0 и распакован без xattr warnings.
2. Config/DB проверены через PHP 8.3; migrations применены после pre-migration dump.
3. `public_html` атомарно переключён на release; исходный каталог не удалён.
4. HTTP/HTTPS/health/routes/security headers и desktop/mobile layout проверены.
5. Synthetic BDI на mobile прошёл 21/21 и сформировал бесплатный результат `0/63`; нет validation error, horizontal overflow или console errors.
6. 08.1F атомарно переключил staging на `398ca23`: `/tests` — `200`, HTTP — `301`, health — `ok`; `Set-Cookie` содержит `Secure`, `HttpOnly`, `SameSite=Lax`, а каждый dynamic security header приходит один раз. Static assets обслуживает внешний nginx Beget: он отдаёт корректный MIME/cache, но не применяет Apache `.htaccess` headers к статике.
7. 08.2 атомарно переключил staging на `1559188`: новая главная и каталог отвечают `200`, health — `200`/`ok`, HTTP сохраняет `301` на HTTPS; начальные страницы BDI и СМИЛ также отвечают `200`. Расчёты, страницы результатов, PDF и сам SMIL-график этим release не менялись.
8. 04.0C атомарно переключил staging на `2b0ce92`: приглашение Лазаруса остаётся защищённым, а мобильное прохождение использует сетку ответов 5×2 и не прокручивается к заголовку между вопросами. Расчёты и результаты этим release не менялись.
9. 04.0D атомарно переключил staging на `779a2b2`: pair results Лазаруса получили единый со шкалой индивидуального результата visual component, два суммарных профиля, ясные русские подписи и адаптивные mobile-карточки для подробного сравнения. Checksum артефакта совпал перед распаковкой; HTTPS health и каталог — `200`, HTTP `/tests` — `301` на HTTPS. Scoring не менялся.
10. 04.0E атомарно переключил staging на `5da9ab5`: meter совпадения, control раскрытия и отдельный compact landscape pair PDF выложены без изменения scoring; release сохранён как текущий rollback.
11. 04.0F атомарно переключил staging на `3a2daa8`: общий result PDF с парным сравнением теперь получает compact landscape-layout. SHA-256 артефакта `2c2b874d88f6aa0baaca2b3067704264f1bc23662d43c6757024b653cf3f02e2` совпал после загрузки. Перед необратимой cleanup-миграцией подтверждены две пустые таблицы и сохранён dump `backups/db-pre-3a2daa8-20260824.sql`; после неё все 8 migrations `up`. HTTPS health и `/tests` — `200`, HTTP `/tests` — `301`, retired interpretation — `410`, выключенная admin login — `404`; cookie сохраняет `Secure`, `HttpOnly`, `SameSite=Lax`.

12. Выкладка `2e276b3` устранила зависание страницы результата при заказе разбора: сессия закрывается до фоновой работы модели, поэтому 303-редирект возвращается сразу, а посетитель видит ожидание с крутилкой и опрос состояния. SHA-256 артефакта `ff83347a69ec7ff1bede4b983175a9d1e1f1750912b84ef7f512f5d21a114ebb` совпал после загрузки; pre-deploy dump сохранён в `backups/pre-deploy-2e276b3.sql.gz`. Миграции побайтово совпали с уже применёнными, поэтому `phinx migrate` не запускался. HTTPS health, главная, `/tests` и живая страница результата — `200`, HTTP `/tests` — `301`; новая `main.css` доехала с новой версией в адресе, логи ошибок пусты. Расчёты и scoring не менялись.

Rollback текущего релиза: атомарно направить `public_html` обратно на `releases/78bdf24/public`; pre-migration dump и прежние releases сохранены в `backups/` и `releases/`. Следующий шаг — ручная проверка владельцем полного pair result PDF, затем короткий пилот.
