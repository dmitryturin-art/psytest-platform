# AGENTS.md — PsyTest Platform

## Superpowers

У этого проекта локально установлены **Superpowers** (obra/superpowers v6.0.3) — методология разработки на базе набора навыков (skills).

<EXTREMELY-IMPORTANT>
У тебя есть superpowers. Перед ЛЮБЫМ ответом или действием — включая уточняющие вопросы — проверь, применим ли какой-либо навык. Если есть хотя бы 1% шанс, что навык применим — ОБЯЗАТЕЛЬНО вызови его через инструмент `skill`.

Сначала загрузи навык `using-superpowers` — он задаёт правила использования всей системы навыков.
</EXTREMELY-IMPORTANT>

### Установленные навыки

Загружай через инструмент `skill` по имени:

**Процесс / сотрудничество**
- `brainstorming` — уточнение дизайна через вопросы, ДО написания кода
- `writing-plans` — детальные планы реализации (задачи по 2–5 минут)
- `executing-plans` — пакетное выполнение плана с контрольными точками
- `subagent-driven-development` — итерации через сабагентов с двухэтапным ревью
- `dispatching-parallel-agents` — параллельные сабагенты
- `requesting-code-review` / `receiving-code-review` — запрос и приём ревью
- `using-git-worktrees` — изолированные ветки разработки
- `finishing-a-development-branch` — merge/PR/cleanup

**Качество**
- `test-driven-development` — RED-GREEN-REFACTOR (тесты сначала, всегда)
- `systematic-debugging` — 4-фазный поиск корневой причины
- `verification-before-completion` — проверка перед закрытием задачи

**Мета**
- `using-superpowers` — введение в систему навыков (загрузить первым)
- `writing-skills` — создание новых навыков

### Правила приоритета

1. Явные инструкции пользователя (CLAUDE.md, AGENTS.md, прямые запросы) — высший приоритет
2. Навыки Superpowers — перекрывают поведение системы по умолчанию
3. Системный промпт — низший приоритет

Если в этом файле или от пользователя сказано «не использовать TDD» — следуй инструкции пользователя. Пользователь главный.

### О проекте

PsyTest Platform — психологическая тест-платформа на PHP (см. README.md, DEVELOPMENT.md, composer.json).

### Установка Superpowers (для справки)

Superpowers установлен локально в `.kilo/superpowers/` (shallow-clone), навыки слинкованы в `.kilo/skills/`. Папка `.kilo/` в git не отслеживается. Для обновления:

```bash
cd .kilo/superpowers && git pull
```
