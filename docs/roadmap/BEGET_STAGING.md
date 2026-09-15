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
| Database | отдельная staging DB на MySQL 5.7.21; 12 migrations; свежий pre-migration dump сохранён |
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

Артефакт собирается только скриптом `bin/build-release.sh`: source берётся из
`git archive HEAD`, затем применяются якорные exclude-паттерны, а `git ls-files public`
автоматически сверяется с содержимым артефакта. Это защищает и от выпадения tracked
assets (инцидент L-015 с фоновой сеткой СМИЛ), и от попадания ignored локальных файлов.
Ручная сборка rsync-командой запрещена.

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
7. Clean migrations применены; после release `c8e2b28` Phinx status показывает все 12 migrations как `up`.

## Оставшиеся эксплуатационные задачи

- настроить ежедневный cleanup через панель Beget с явным `/usr/local/bin/php8.3`;
- задать `AI_WORKER_PHP_BIN=/usr/local/bin/php8.3` в `.env` релиза: без него заказ ИИ-разбора доготавливается в веб-процессе и обрывается по 504;
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

13. Выкладка `c8e2b28` доставила universal one-time invitations и защиту completed-сессии от повторной перезаписи. SHA-256 артефакта `49990831f1ea749760e4148ee4a90fad3297abece55fc52d4b3d1d5e10b8172c` совпал после upload; перед `AddTestInvites` создан и проверен сжатый dump `backups/pre-deploy-c8e2b28.sql.gz`. После миграции все 12 migrations имеют status `up`; `public_html` и `current` атомарно указывают на `release-c8e2b28`. HTTPS `/`, `/tests`, health, privacy и terms — `200`; новых непустых error logs в окно smoke нет. Прохождение тестов smoke не запускалось, поэтому сессии не создавались.

14. Выкладка `a7999f0` (14.09) сняла общий ключ СМИЛ (`DropTestAccessKey`) и добавила карточки клиентов (`AddTherapistClients`). SHA-256 артефакта `58fb45e48df54086a60b68e200f60ce18a846d3c1daee7c08c816035ed712bd8` совпал; pre-deploy dump `backups/pre-deploy-a7999f0.sql.gz` проверен. Smoke: `/`, `/tests`, health, правовые, `/admin/login` — `200`; `/test/smil` — `404`; HTTP — `301`. Особенности доступа: home deploy-аккаунта — корень сайта, `~/test.23time.ru` не существует; точный путь `/admin` без cookie перехватывает anti-bot заглушка nginx Beget (с cookie `beget=begetok` работает).

15. Выкладка `4e63510` (14.09) добавила кабинет посетителя (`AddVisitorAccounts`). SHA-256 `c0a55a044f1a2275ebf40ef03917c95834810023794786683076a02432557748` совпал; dump `backups/pre-deploy-4e63510.sql.gz` проверен. В `.env` релиза добавлены `MAIL_TRANSPORT=mail` и `MAIL_FROM=info@23time.ru` (локальный `mail()` хостинга, без пароля; запасной вариант — SMTP Beget через `MAIL_HOST/PORT/USER/PASS/ENCRYPTION`). Smoke: основные маршруты и `/account/login` — `200`, кабинет без входа — `303`, `/test/smil` — `404`.

16. Выкладка `66df1cb` (14.09) добавила снимки заданий ИИ (`AddAiReportSnapshots`). SHA-256 `daa225712a7bcc6344ba57ff8bd437fb06103589d239476e60a5ba1d009dc655` совпал; dump `backups/pre-deploy-66df1cb.sql.gz` проверен. Smoke: основные маршруты — `200`, `/test/smil` — `404`.

17. Выкладка `c8b5feb` (14.09) добавила версии разбора и публикацию клиенту (`AddAiReportRevisions`). SHA-256 `4da6fc01842dbfbec62f55aa269f50924ddcc8ae2c12d9d442e48e11bb7537bf` совпал; dump `backups/pre-deploy-c8b5feb.sql.gz` проверен. Smoke: основные маршруты — `200`, `/test/smil` — `404`.

18. Выкладка `c9cff61` (14.09) добавила email клиента и уведомление, фоновую обработку черновиков из кабинета и подсказку раздела ИИ (`AddClientEmail`). SHA-256 `30f230489e05b52ede123882d3ec24f68f9af4d2b72189b952dfcb3d4847a00c` совпал; dump `backups/pre-deploy-c9cff61.sql.gz` проверен. Smoke: основные маршруты — `200`, `/test/smil` — `404`.

19. Выкладка `869986d` (14.09) добавила привязку найденной сессии к карточке клиента; миграций нет. SHA-256 `821567385776c7b7215050f3207cb772bd785291855312c78c28c12a20871f7e` совпал; dump `backups/pre-deploy-869986d.sql.gz` проверен.

20. Выкладка `be4342e` (15.09) добавила парное прохождение в карточке кейса; миграций нет. SHA-256 `96e154b91bd3738909914a89e8af75b1838af2e930c497fddd1286125581cef1` совпал; dump `backups/pre-deploy-be4342e.sql.gz` проверен.

21. Выкладка `2bd89f4` (15.09) добавила ссылку в письмо-уведомление, favicon, исправления пары/СМИЛ/PDF и кабинет промптов с выключателем ИИ (`AddPromptVersions`). SHA-256 `e7777b140b63e28773d7f4333b955e7e6be0f953e27f205b6930e8126d4ec33d` совпал; dump `backups/pre-deploy-2bd89f4.sql.gz` проверен.

22. Выкладка `e223062` (15.09) заменила дополнительные шкалы СМИЛ на 16 verified по Собчик; миграций нет. SHA-256 `28535fd1bbff3cbff39840afb979028e22346e72eeb75be27d640cc69e95fc6b` совпал; dump `backups/pre-deploy-e223062.sql.gz` проверен.

23. Выкладка `8d3ccfa` (15.09): вторая партия дополнительных шкал СМИЛ (35 verified) и глоссарий для ИИ; миграций нет. SHA-256 `a436f06360bae0bcc72a5ecfcbbecbb30f1ea083dc17e55e99f7c5b311cd29ba` совпал; dump `backups/pre-deploy-8d3ccfa.sql.gz` проверен.

24. Выкладка `33dbdc4` (15.09): фоновый воркер отдельным процессом (в `.env` добавлен `AI_WORKER_PHP_BIN=/usr/local/bin/php8.3`; PHP web — apache2handler без `fastcgi_finish_request`), переподключение к БД перед транзакцией, глоссарий 35 шкал, свёрнутая анкета; миграций нет. SHA-256 `790ae8388c7032408a5789620a7f048c40f22741373cf1712f41474c6140d07b` совпал; dump `backups/pre-deploy-33dbdc4.sql.gz` проверен.

25. Выкладка `975b653` (15.09): визуальный редактор разбора (Toast UI локально в `public/vendor/`), перезагрузка и автообновление статуса; миграций нет. SHA-256 `9366fa96fa8b1c7ffa70ab24ee8c57d0dd58b2df5d37c06f8dfc4bd82fe39285` совпал; dump `backups/pre-deploy-975b653.sql.gz` проверен. Ручной запуск воркера по SSH — только через `setsid nohup …`.

26. Выкладка `b0386cb` (15.09): «Заказать заново» для исчерпанных заданий ИИ; миграций нет. SHA-256 `90edb6f13e51537b5df8adca11f69ebc49e70362011dbdfd4622408d9de2e4f8` совпал; dump `backups/pre-deploy-b0386cb.sql.gz` проверен. Загрузку по SSH вести с `-o ServerAliveInterval=10` (первая попытка зависла на 2,7 МБ из 6,3).

27. Выкладка `60e536b` (15.09): фикс залипающего списка заголовков в редакторе, возврат зависших заданий при просмотре; миграций нет. SHA-256 `5cd6dd942b443079c3be6aa78114d3dc46c0f3b9058e4b5a40fe7cebb1a3e418` совпал; dump `backups/pre-deploy-60e536b.sql.gz` проверен.

28. Выкладка `9b80e42` (15.09): «Заказать заново» пересобирает снимок; заметные кнопки; миграций нет. SHA-256 `df4b33361c5b2bb1b9b69d42067899311e0b4238809e729e064a79a0977c1c9c` совпал; dump `backups/pre-deploy-9b80e42.sql.gz` проверен.

29. Выкладки `eddee4c` (S3.3 + G3) и `0f32c96` («Заказать заново» для готовых черновиков), 15.09; миграций нет. SHA-256 `e7cc502b7e048928a304c6c5e5b2a2b1f9fddb42ccae75e8e7565285316372a7` и `9fa122a8ea4674acff3dae8db16efe3c0fc4409753eedc17871dfe0c1c09bcee` совпали; dumps проверены.

30. Выкладка `63f9057` (15.09): воркер с `--limit=10` и самоперезапуск из карточки кейса; миграций нет. SHA-256 `07b0b83601b6224eef64bda41588bf4a9c7083333195173be5c4bb6150a859d5` совпал; dump проверен. Ручной запуск воркера по SSH на Beget невозможен: процесс убивается при закрытии сессии даже с `setsid`.

31. Выкладка `9d52345` (15.09): выгрузка кейса в PDF и версия для печати, русские статусы; миграций нет. SHA-256 `a7efb2f3837624afa6d9cf11687151d5ce885afe7a1c3ab71b9c446cc6378714` совпал; dump проверен. Смоук: основные маршруты `200`, `/test/smil` `404`.

32. Выкладка `80e8daf` (15.09): партия S3.4 — 75 дополнительных шкал СМИЛ с глоссарием; миграций нет. SHA-256 `5792b93a9754c3c9946667bf4f0571dbea599de7ac8d133e61612d52ed05b25b` совпал; dump проверен. Смоук: основные маршруты `200`, `/test/smil` `404`.

33. Выкладка `9ab675c` (15.09): глоссарий дополнительных шкал сверен по источникам владельца (07.G5); миграций нет. SHA-256 `7d9b615714f4b8a412899efff778727d99ae38bc67c94179f7a25d4a6e7614d8` совпал; dump проверен. Смоук: основные маршруты `200`, `/test/smil` `404`.

34. Выкладка `9ddab4f` (15.09): компактный режим глоссария СМИЛ переключателем в кабинете (07.G6); миграций нет. SHA-256 `5d0297e289cc90f0e96bd8009565fc02f1914b851cbd857466b331848ca34305` совпал; dump проверен. Смоук: основные маршруты `200`, `/test/smil` `404`.

35. Выкладка `4d8ab91` (15.09): партия S3.5 — 105 дополнительных шкал СМИЛ с глоссарием; миграций нет. SHA-256 `dcdc1b91ba37d63bcf4d8c662db303ca36e3e8be5189c607c19028dab42870fb` совпал; dump проверен. Смоук: основные маршруты `200`, `/test/smil` `404`.

Rollback текущего релиза: атомарно направить `public_html` на `releases/9ddab4f/public` и `current` на `releases/9ddab4f`; pre-deploy dump и прежние releases сохранены в `backups/` и `releases/`. Следующий шаг — K2 (клиенты/назначения) или короткий owner-pilot; production go-live отдельно.
