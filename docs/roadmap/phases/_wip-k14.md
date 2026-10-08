# WIP 07.K14 — раздел «Методики» (временный файл, удалить перед PR)

- Ветка: `codex/07-k14-methodology-section` от `origin/main` 048414a.
- Сделано (код закоммичен): маршруты `/admin/tests`, `/admin/tests/settings`, `/admin/tests/{test}`,
  `POST /admin/tests/{test}/ai` (автосохранение JSON), `/admin/tests/{test}/prompts/{mode}/{kind}` (+ действия);
  301/308 со старых `/admin/prompts…`; шаблоны `owner-tests`, `owner-test`, `owner-ai-settings`, новый
  `owner-prompt-key`, блоки `owner-nav`, `owner-crumbs`, `owner-prompt-state`, `owner-publish-pop`; `owner-tests.js`;
  CSS-блок 07.K14 в `cabinet.css`; навигация; ARCHITECTURE/UI_KIT; тесты (контракт + `Integration/MethodologySectionTest`).
- Сделано также: снимки BEFORE/AFTER, design-critique и правки, полный gate (828 тестов OK).
- Осталось ведущему: приёмка владельцем, затем WORKLOG/STATUS, удалить этот файл, PR.
- Локальный сервер: `php -S 127.0.0.1:8114 -t public <scratchpad>/k14/router.php`; `.env` из основного checkout,
  `DB_NAME=psytest_wt_k14`, `AI_BASE_URL` на 127.0.0.1:9 (провайдер недоступен намеренно).
- Пароль владельца — `<scratchpad>/k14/.owner-pass` (не печатать). Снимки: `node <scratchpad>/k14/shots.js <plan>`.
