# Этап 02 — клиническая безопасность, privacy и бесплатный пилот

Статус: **В работе**. Этап 01 завершён; первичные owner-решения по retention и AI consent приняты 2026-08-16.

## Цель

Сделать бесплатную платформу пригодной для закрытого пилота: кризисный сигнал не теряется, пользователь понимает обработку данных, а удаление действительно завершает lifecycle.

## Контрольная точка владельца

До публикации BDI safety-flow согласован только кризисный текст. Он не включает страны, номера, ссылки или каталог ресурсов. Срок хранения принят: anonymous — 180 дней; therapist-режим — бессрочно с ручным удалением. Отдельное AI-consent обязательно при checkout интерпретации. IP не используется для кризисного flow.

Current-state evidence для этого выбора: [DATA_MAP_CURRENT.md](../DATA_MAP_CURRENT.md).

## Work packages

1. **BDI safety signal.** 02.2A: сервер извлекает item 9 из валидированных ответов независимо от total score и сохраняет machine-readable severity 1–3. Не превращает это в диагноз и не добавляет клиентский текст; Crisis UI остаётся отдельным пакетом.
2. **Crisis UI.** 02.2B `788e590` опубликован и подтверждён [PHP 8.3/MySQL CI](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32503879209): при item 9 > 0 заметный спокойный action block показан сразу после submit, перед CTA: «Ваш ответ на этот пункт может означать, что сейчас вам особенно нужна поддержка. Если есть риск причинить себе вред или вы не уверены, что сможете оставаться в безопасности, пожалуйста, не оставайтесь один: свяжитесь с близким человеком и обратитесь в местную экстренную или кризисную службу.» Никаких номеров, URL, страны или дополнительных действий. Automated browser coverage остаётся отдельным пакетом.
3. **CountryResolver.** 02.3A остаётся pure, не подключённой доменной заготовкой. Страна, IP/GeoIP и ручной selector не входят в этот safety-flow без нового решения владельца.
4. **Resource registry.** 02.3B остаётся пустой fail-closed таблицей без seed data и public reader. Никакие contacts/URLs не публикуются без нового решения владельца.
5. **Data map и consent.** 02.4A `a14f5eb` исправляет публичный current-state текст и delete copy: они не обещают шифрование, отсутствие будущих получателей или мгновенное физическое удаление; GitHub Actions [31949538307](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31949538307) — success. Regression-test защищает эти границы. Остаются legal review, minimization технических данных и отдельное согласие на AI до его включения.
6. **Lifecycle/delete.** 02.1: `anonymous`-класс, 180-day cron lifecycle, session-bound logs и известные result/AI/pair PDFs покрыты integration tests. 02.4B `93a6bb1` добавляет минимальный защищённый `/admin`: один owner account, Argon2id configuration, secure session, CSRF, login limit; назначить можно лишь завершённую anonymous session, а therapist-case удаляется физически с подтверждением и обезличенным audit event. [PHP 8.3/MySQL CI](https://github.com/dmitryturin-art/psytest-platform/actions/runs/32506141069) — success. Будущие AI jobs/consents и финансовое разделение остаются отдельно. Каждое новое правило доказывается integration test.
7. **Методики и лицензии.** 02.5A `7240d3b` создаёт versioned registry текущих модулей: factual evidence, gaps и release gates; GitHub Actions [31950300793](https://github.com/dmitryturin-art/psytest-platform/actions/runs/31950300793) — success. Пока право конкретной формы не подтверждено, не добавлять новый public content и не включать paid interpretation; существующие scoring core и бесплатный flow не меняются.
8. **Закрытый бесплатный пилот.** Небольшая группа, anonymized issue log, support path, никаких платежей/AI.
9. **Pilot questionnaire UX.** 02.6A `89a5e5a`: сохранение последнего ответа сразу обновляет общий progress, поэтому BDI завершается на `21 / 21` и `100%`, даже когда перехода к следующему вопросу уже нет. Scoring и submit flow не изменены; regression contract и desktop/mobile browser QA пройдены.
10. **Questionnaire navigation.** 02.6B `0954117`: пустая sticky-панель скрыта на первом вопросе и появляется только при доступном действии «Назад» или «Завершить». Desktop/mobile browser QA пройдены.

## Privacy-инварианты

- Не отправлять crisis signal/country в маркетинговую аналитику.
- Не хранить точный IP ради кризисной географии.
- Не обещать шифрование/соответствие закону без фактической реализации и правовой проверки.

## Проверка и exit criteria

- Unit/HTTP/browser tests: item 9 > 0 при низком total показывает утверждённый block; item 9 = 0 и другие методики его не показывают; UI не содержит country/resource/IP/GeoIP controls.
- Data deletion integration test перечисляет и удаляет каждую связанную сущность.
- Владелец утвердил клинический текст; privacy copy соответствует data map.
- Закрытый бесплатный пилот не выявил P0/P1 без зарегистрированного решения.

## Покрытие аудита

`CLIN-01`, `DATA-01`, `DATA-02`, `METH-01`, privacy-часть будущего AI flow.
