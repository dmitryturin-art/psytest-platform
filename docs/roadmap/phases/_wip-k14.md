# WIP 07.K14 — раздел «Методики» (временный файл, удалить перед PR)

- Ветка: `codex/07-k14-methodology-section` от `origin/main` 048414a.
- Сделано: чтение контекста; БД `psytest_wt_k14` создана и смигрирована, `ai_settings.ai_enabled=0`.
- В работе: маршруты `/admin/tests`, `/admin/tests/settings`, `/admin/tests/{test}`, `POST /admin/tests/{test}/ai`,
  `/admin/tests/{test}/prompts/{mode}/{kind}` (+ preview/versions/publish/reset/trial); 301 со старых `/admin/prompts…`.
- Следующие шаги: контроллер → шаблоны `owner-tests.twig`, `owner-test.twig`, `owner-ai-settings.twig`, переделка
  `owner-prompt-key.twig` → JS `owner-test-ai.js` (автосохранение) → CSS → навигация во всех owner-шаблонах →
  тесты → ARCHITECTURE.md → снимки → design-critique → gate.
- Локальный сервер: `php -S 127.0.0.1:8114 -t public <scratchpad>/k14/router.php` (роутер отдаёт статику, иначе
  `public/index.php`); `.env` скопирован из основного checkout, `DB_NAME=psytest_wt_k14`, `AI_BASE_URL` на 127.0.0.1:9.
- Пароль владельца для теста — в `<scratchpad>/k14/.owner-pass` (не печатать). Снимки — puppeteer-core по образцу
  `<scratchpad>/d5/shots.js`.
