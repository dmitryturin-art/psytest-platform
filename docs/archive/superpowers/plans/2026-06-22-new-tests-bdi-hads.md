# Новые тесты BDI + HADS — План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** Добавить два суммирующих теста (BDI депрессии Бека + HADS тревоги/депрессии) на отлаженном контракте `buildSections()`.

**Tech Stack:** PHP 8.1+, PHPUnit 10, Twig 3 (блоки score-badge, recommendations готовы).

## Global Constraints

- Контракт: `TestModuleInterface` + `buildSections()` возвращает `ResultSection[]`.
- Расчёт: простое суммирование (без T-баллов/K-коррекции).
- Вывод: блоки `score-badge.twig` + `recommendations.twig` (уже созданы в Плане 2).
- Ветка: `refactor/technical-debt-phase1`.

---

### Task 1: BDI (Beck Depression Inventory)

**Files:**
- Create: `modules/beck-depression/metadata.json`
- Create: `modules/beck-depression/questions.json`
- Create: `modules/beck-depression/BeckDepressionModule.php`
- Create: `tests/BeckDepressionModuleTest.php`

**Контекст:** 21 вопрос, 4 варианта (0-3). Сумма 0-63 → уровень.

**Уровни:** 0-13 minimal, 14-19 mild, 20-28 moderate, 29-63 severe.

- [ ] **Step 1: metadata.json**

```json
{
  "slug": "bdi",
  "name": "Шкала депрессии Бека (BDI)",
  "description": "Методика диагностики депрессивных состояний Аарона Бека. 21 вопрос.",
  "question_count": 21,
  "estimated_time": 10,
  "scales": ["depression"],
  "requires_demographics": {"gender": false, "age": false}
}
```

- [ ] **Step 2: questions.json** — 21 вопрос с вариантами ответов 0-3.

Стандартные вопросы BDI (Beck Depression Inventory, 1961/1978):
```
1. Самочувствие (0-3)
2. Пессимизм (0-3)
...
21. Сексуальная активность (0-3)
```
(полный список в открытых источниках — использовать стандартный русский перевод Н.В. Тарабриной)

- [ ] **Step 3: BeckDepressionModule extends BaseTestModule**

```php
class BeckDepressionModule extends BaseTestModule {
    public function calculateResults(array $answers): array {
        $total = 0;
        foreach ($answers as $qid => $answer) {
            if (is_numeric($qid)) $total += (int)$answer;
        }
        $level = $total <= 13 ? 'minimal' : ($total <= 19 ? 'mild' : ($total <= 28 ? 'moderate' : 'severe'));
        return ['raw_scores' => ['total' => $total], 'level' => $level, ...];
    }
    
    public function buildSections(array $results): array {
        // score_badge + recommendations
    }
}
```

- [ ] **Step 4: Тест** — проверить суммирование (все 0 → 0, все 3 → 63, уровень).

- [ ] **Step 5: Commit**

---

### Task 2: HADS (Hospital Anxiety and Depression Scale)

**Files:**
- Create: `modules/hads/metadata.json`
- Create: `modules/hads/questions.json`
- Create: `modules/hads/HadsModule.php`
- Create: `tests/HadsModuleTest.php`

**Context:** 14 вопросов, две подшкалы по 7: тревога (A: 1,3,5,7,9,11,13 — часть обратных) и депрессия (D: 2,4,6,8,10,12,14). Каждый 0-3. A=0-21, D=0-21.

**Уровни:** 0-7 норма, 8-10 субклиническая, 11-21 клиническая.

- [ ] **Step 1: metadata.json**
- [ ] **Step 2: questions.json** — 14 вопросов HADS (русский перевод, из PDF-Andreeva).
- [ ] **Step 3: HadsModule** — два score_badge (тревога + депрессия).
- [ ] **Step 4: Тест** — проверить суммирование подшкал.
- [ ] **Step 5: Commit**

---

### Task 3: Регистрация в БД + финальная проверка

- [ ] **Step 1:** Добавить записи в `tests` таблицу для bdi, hads.
- [ ] **Step 2:** Полный прогон: `composer test`, `php test-architecture.php`.
- [ ] **Step 3:** Commit.

---

## Критерии приёмки

- [ ] `composer test` ≥ 35 тестов.
- [ ] `php test-architecture.php` показывает 4 модуля (smil, bai, bdi, hads).
- [ ] Оба новых модуля используют `buildSections()` + общие twig-блоки.
