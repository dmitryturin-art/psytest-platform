# Регистрация BDI/HADS и профессиональная UI-полировка

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Зарегистрировать тесты BDI и HADS в базе данных, улучшить UI главной страницы, страницы тестирования и результатов до профессионального клинического уровня.

**Architecture:** Phinx миграция для регистрации тестов → CSS улучшения для профессионального стиля → JavaScript для улучшенного UX прохождения теста.

**Tech Stack:** PHP 8.1+, Phinx migrations, CSS3, vanilla JavaScript

## Global Constraints

- PHP ≥ 8.1
- Профессиональный клинический дизайн (не модный, без иконок)
- Типографика: Georgia/PT Serif для заголовков, PT Sans для текста
- Цвета: белый фон, #333 текст, #2c5aa0 акценты
- Без анимаций и излишней интерактивности
- Responsive только для корректного отображения, не mobile-first
- Коммиты частые, atomic changes

---

## File Structure

**Будут созданы:**
- `database/migrations/20260622_add_bdi_hads_tests.php` — Phinx миграция
- `public/css/professional-theme.css` — профессиональные стили
- `public/js/test-progress.js` — прогресс-бар для тестирования

**Будут изменены:**
- `templates/tests-list.twig` — главная страница списка тестов
- `templates/test-page.twig` — страница прохождения теста
- `templates/result-layout.twig` — улучшенная страница результатов
- `public/css/main.css` — интеграция нового стиля

---

### Task 1: Register BDI and HADS Tests via Phinx Migration

**Files:**
- Create: `database/migrations/20260622140000_add_bdi_hads_tests.php`

**Interfaces:**
- Consumes: Existing `tests` table schema
- Produces: 4 tests visible on `/tests` page (SMIL, BAI, BDI, HADS)

- [ ] **Step 1: Create Phinx migration file**

Run: `vendor/bin/phinx create AddBdiHadsTests`

This creates: `database/migrations/20260622XXXXXX_add_bdi_hads_tests.php`

- [ ] **Step 2: Write migration up() method**

Edit the migration file:

```php
<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddBdiHadsTests extends AbstractMigration
{
    public function up(): void
    {
        $this->table('tests')->insert([
            [
                'slug' => 'beck-depression',
                'name' => 'Шкала депрессии Бека (BDI)',
                'description' => 'Опросник для оценки тяжести депрессии. 21 вопрос, 4 варианта ответа (0-3). Результат классифицируется как минимальная, лёгкая, умеренная или тяжёлая депрессия.',
                'question_count' => 21,
                'duration_minutes' => 10,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'slug' => 'hads',
                'name' => 'Госпитальная шкала тревоги и депрессии (HADS)',
                'description' => 'Скрининг тревоги и депрессии в соматической практике. 14 вопросов образуют 2 подшкалы: тревога (A) и депрессия (D) по 7 пунктов каждая. Быстрая оценка эмоционального состояния.',
                'question_count' => 14,
                'duration_minutes' => 5,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ])->save();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM tests WHERE slug IN ('beck-depression', 'hads')");
    }
}
```

- [ ] **Step 3: Run migration**

Run: `vendor/bin/phinx migrate`

Expected output:
```
 == 20260622140000 AddBdiHadsTests: migrating
 == 20260622140000 AddBdiHadsTests: migrated 0.0234s
```

- [ ] **Step 4: Verify tests in database**

Run: `mysql -u root psytest -e "SELECT slug, name FROM tests ORDER BY id"`

Expected:
```
smil            СМИЛ (адаптация MMPI, Ф. Собчик)
beck-anxiety    Шкала тревоги Бека (BAI)
beck-depression Шкала депрессии Бека (BDI)
hads            Госпитальная шкала тревоги и депрессии (HADS)
```

- [ ] **Step 5: Test on homepage**

Run: `php -S localhost:8000 -t public`  
Open: `http://localhost:8000/tests`

Expected: 4 test cards displayed

- [ ] **Step 6: Commit**

```bash
git add database/migrations/20260622*
git commit -m "feat: register BDI and HADS tests in database

- Add Phinx migration for beck-depression and hads tests
- Both tests now visible on /tests homepage
- BDI: 21 questions, 10 min
- HADS: 14 questions, 5 min (anxiety + depression subscales)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 2: Professional CSS Theme for Homepage

**Files:**
- Create: `public/css/professional-theme.css`
- Modify: `templates/layout.twig` — include new CSS
- Modify: `templates/tests-list.twig` — apply professional classes

**Interfaces:**
- Consumes: Existing HTML structure
- Produces: Clean, professional test list without decorations

- [ ] **Step 1: Create professional CSS theme**

Create `public/css/professional-theme.css`:

```css
/* Professional Clinical Theme */
:root {
    --color-text: #333;
    --color-text-light: #666;
    --color-border: #ddd;
    --color-accent: #2c5aa0;
    --color-bg: #fff;
    --color-bg-alt: #f9f9f9;
    --font-serif: Georgia, "PT Serif", serif;
    --font-sans: "PT Sans", "Helvetica Neue", Arial, sans-serif;
}

/* Typography */
body {
    font-family: var(--font-sans);
    font-size: 16px;
    line-height: 1.6;
    color: var(--color-text);
    background: var(--color-bg);
}

h1, h2, h3, h4 {
    font-family: var(--font-serif);
    font-weight: 600;
    color: var(--color-text);
}

h1 { font-size: 32px; margin: 0 0 24px; }
h2 { font-size: 24px; margin: 0 0 16px; }

/* Test List */
.tests-list {
    max-width: 900px;
    margin: 40px auto;
    padding: 0 20px;
}

.test-item {
    border: 1px solid var(--color-border);
    background: var(--color-bg);
    padding: 24px;
    margin-bottom: 16px;
}

.test-item__header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 12px;
}

.test-item__title {
    font-size: 20px;
    margin: 0;
}

.test-item__meta {
    color: var(--color-text-light);
    font-size: 14px;
    white-space: nowrap;
}

.test-item__description {
    margin: 0 0 16px;
    line-height: 1.5;
}

.test-item__footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.test-item__info {
    color: var(--color-text-light);
    font-size: 14px;
}

/* Buttons */
.btn-professional {
    display: inline-block;
    padding: 10px 24px;
    background: var(--color-accent);
    color: white;
    text-decoration: none;
    border: none;
    cursor: pointer;
    font-size: 16px;
}

.btn-professional:hover {
    background: #234a7e;
}

.btn-professional-secondary {
    background: white;
    color: var(--color-accent);
    border: 1px solid var(--color-accent);
}

.btn-professional-secondary:hover {
    background: var(--color-bg-alt);
}
```

- [ ] **Step 2: Include CSS in layout**

In `templates/layout.twig` add before `</head>`:

```twig
<link rel="stylesheet" href="{{ basePath }}/css/professional-theme.css">
```

- [ ] **Step 3: Update tests-list template**

In `templates/tests-list.twig` replace test-card structure:

```twig
<div class="tests-list">
    <h1>Доступные психологические тесты</h1>
    
    {% for test in tests %}
    <div class="test-item">
        <div class="test-item__header">
            <h2 class="test-item__title">{{ test.name }}</h2>
            <span class="test-item__meta">{{ test.duration_minutes }} мин</span>
        </div>
        
        <p class="test-item__description">{{ test.description }}</p>
        
        <div class="test-item__footer">
            <span class="test-item__info">{{ test.question_count }} вопросов</span>
            <a href="{{ basePath }}/test/{{ test.slug }}" class="btn-professional">
                Пройти тест
            </a>
        </div>
    </div>
    {% endfor %}
</div>
```

- [ ] **Step 4: Test homepage styling**

Run: `php -S localhost:8000 -t public`  
Open: `http://localhost:8000/tests`

Expected:
- Clean list layout (no shadows, no cards)
- Georgia/PT Serif headings
- Clear information hierarchy
- Professional blue accent buttons

- [ ] **Step 5: Commit**

```bash
git add public/css/professional-theme.css templates/layout.twig templates/tests-list.twig
git commit -m "feat(ui): add professional clinical theme for homepage

- Clean list layout without decorative elements
- Georgia/PT Serif typography for headings
- Clear information hierarchy
- Professional blue accent (#2c5aa0)
- No shadows, gradients, or animations

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 3: Improve Test-Taking Page UX

**Files:**
- Create: `public/js/test-progress.js`
- Modify: `templates/test-page.twig`
- Modify: `public/css/professional-theme.css` — add test page styles

**Interfaces:**
- Consumes: Test questions from module
- Produces: Clear progress indicator and large answer buttons

- [ ] **Step 1: Add progress bar styles to CSS**

In `public/css/professional-theme.css` add:

```css
/* Test Taking Page */
.test-header {
    border-bottom: 2px solid var(--color-border);
    padding-bottom: 16px;
    margin-bottom: 32px;
}

.progress-bar {
    width: 100%;
    height: 6px;
    background: var(--color-bg-alt);
    border: 1px solid var(--color-border);
    margin: 16px 0;
}

.progress-bar__fill {
    height: 100%;
    background: var(--color-accent);
    transition: width 0.3s ease;
}

.progress-text {
    text-align: center;
    color: var(--color-text-light);
    font-size: 14px;
    margin: 8px 0;
}

.question-text {
    font-size: 20px;
    line-height: 1.6;
    margin: 32px 0 24px;
}

.answer-buttons {
    display: flex;
    gap: 16px;
    justify-content: center;
    margin: 32px 0;
}

.answer-btn {
    min-width: 120px;
    min-height: 50px;
    padding: 12px 24px;
    background: white;
    border: 2px solid var(--color-border);
    color: var(--color-text);
    font-size: 16px;
    cursor: pointer;
}

.answer-btn:hover {
    border-color: var(--color-accent);
}

.answer-btn.selected {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: white;
}

.test-navigation {
    display: flex;
    justify-content: space-between;
    margin-top: 48px;
    padding-top: 24px;
    border-top: 1px solid var(--color-border);
}
```

- [ ] **Step 2: Create progress bar JavaScript**

Create `public/js/test-progress.js`:

```javascript
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        updateProgress();
        setupAnswerButtons();
    });

    function updateProgress() {
        const totalQuestions = parseInt(document.querySelector('[data-total-questions]')?.dataset.totalQuestions || 0);
        const currentQuestion = parseInt(document.querySelector('[data-current-question]')?.dataset.currentQuestion || 1);

        if (totalQuestions === 0) return;

        const percentage = Math.round((currentQuestion / totalQuestions) * 100);
        const progressFill = document.querySelector('.progress-bar__fill');
        const progressText = document.querySelector('.progress-text');

        if (progressFill) {
            progressFill.style.width = percentage + '%';
        }

        if (progressText) {
            progressText.textContent = `Вопрос ${currentQuestion} из ${totalQuestions} • Выполнено ${percentage}%`;
        }
    }

    function setupAnswerButtons() {
        const buttons = document.querySelectorAll('.answer-btn');
        buttons.forEach(btn => {
            btn.addEventListener('click', function() {
                // Remove selected from siblings
                this.parentElement.querySelectorAll('.answer-btn').forEach(b => {
                    b.classList.remove('selected');
                });
                // Add selected to this
                this.classList.add('selected');
                // Update hidden input
                const value = this.dataset.value;
                const input = document.querySelector('input[name="answer"]');
                if (input) {
                    input.value = value;
                }
            });
        });
    }
})();
```

- [ ] **Step 3: Update test-page template**

In `templates/test-page.twig` add progress bar and improve question layout:

```twig
<div class="test-container">
    <div class="test-header" data-total-questions="{{ total_questions }}" data-current-question="{{ current_question }}">
        <h1>{{ test.name }}</h1>
        <div class="progress-bar">
            <div class="progress-bar__fill"></div>
        </div>
        <p class="progress-text"></p>
    </div>

    <div class="question-block">
        <p class="question-text">{{ current_question }}. {{ question.text }}</p>

        <form method="POST" action="{{ basePath }}/test/{{ test.slug }}/save">
            <input type="hidden" name="question_id" value="{{ question.id }}">
            <input type="hidden" name="answer" value="">

            <div class="answer-buttons">
                <button type="button" class="answer-btn" data-value="1">Верно</button>
                <button type="button" class="answer-btn" data-value="2">?</button>
                <button type="button" class="answer-btn" data-value="0">Неверно</button>
            </div>

            <div class="test-navigation">
                {% if current_question > 1 %}
                <button type="submit" name="action" value="prev" class="btn-professional-secondary">← Назад</button>
                {% else %}
                <span></span>
                {% endif %}

                <button type="submit" name="action" value="next" class="btn-professional">Вперёд →</button>
            </div>
        </form>
    </div>
</div>

<script src="{{ basePath }}/js/test-progress.js"></script>
```

- [ ] **Step 4: Test progress and navigation**

Run: `php -S localhost:8000 -t public`  
Open: `/test/smil`

Expected:
- Progress bar at top showing percentage
- Text: "Вопрос 1 из 566 • Выполнено 0%"
- Large clickable answer buttons (120x50px)
- Selected button turns blue
- Navigation: Назад | Вперёд

- [ ] **Step 5: Commit**

```bash
git add public/js/test-progress.js public/css/professional-theme.css templates/test-page.twig
git commit -m "feat(ui): improve test-taking page UX

- Add progress bar showing question N of Total and percentage
- Large clickable answer buttons (120x50px minimum)
- Clear visual feedback on selection (blue highlight)
- Navigation buttons: Назад/Вперёд
- No radio buttons, direct click on answer

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 4: Polish Results Page Presentation

**Files:**
- Modify: `public/css/professional-theme.css` — results page styles
- Modify: `templates/result-layout.twig` — improve section cards

**Interfaces:**
- Consumes: Result sections from module
- Produces: Professional results page with clear sections

- [ ] **Step 1: Add results page styles**

In `public/css/professional-theme.css` add:

```css
/* Results Page */
.results-wrapper {
    max-width: 1000px;
    margin: 40px auto;
    padding: 0 20px;
}

.results-header {
    text-align: center;
    border-bottom: 2px solid var(--color-border);
    padding-bottom: 24px;
    margin-bottom: 32px;
}

.results-title {
    font-size: 32px;
    margin: 0 0 8px;
}

.results-subtitle {
    color: var(--color-text-light);
    font-size: 18px;
    margin: 0 0 16px;
}

.results-meta {
    color: var(--color-text-light);
    font-size: 14px;
}

.results-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
    margin: 24px 0;
    padding: 24px 0;
    border-bottom: 1px solid var(--color-border);
}

.results-section {
    border: 1px solid var(--color-border);
    background: var(--color-bg);
    padding: 32px;
    margin-bottom: 24px;
}

.section-title {
    font-size: 24px;
    margin: 0 0 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--color-border);
}

.results-disclaimer {
    background: var(--color-bg-alt);
    border: 1px solid var(--color-border);
    padding: 20px;
    margin-top: 32px;
    font-size: 14px;
    color: var(--color-text-light);
}

/* Scores Table */
.scores-table {
    width: 100%;
    border-collapse: collapse;
    margin: 16px 0;
}

.scores-table th {
    background: var(--color-bg-alt);
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid var(--color-border);
}

.scores-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--color-border);
}

.scores-table tr:nth-child(even) {
    background: var(--color-bg-alt);
}

.category-header td {
    background: #e8e8e8 !important;
    font-weight: 600;
    padding: 8px 16px;
}

/* Badge for levels */
.badge {
    display: inline-block;
    padding: 4px 12px;
    font-size: 12px;
    font-weight: 600;
    border-radius: 3px;
}

.badge-low { background: #e3f2fd; color: #1976d2; }
.badge-normal { background: #e8f5e9; color: #388e3c; }
.badge-elevated { background: #fff3e0; color: #f57c00; }
.badge-high { background: #fce4ec; color: #c2185b; }
.badge-very_high { background: #ffebee; color: #d32f2f; }
```

- [ ] **Step 2: Add section card classes to result-layout.twig**

In `templates/result-layout.twig` update sections rendering:

```twig
<div class="results-wrapper">
    <div class="results-header">
        <h1 class="results-title">{{ test.name }}</h1>
        <p class="results-subtitle">Результаты тестирования</p>
        <div class="results-meta">
            📅 {{ session.created_at|date("d.m.Y H:i") }} | 
            🔗 ID: {{ session.id|slice(0, 8) }}...
        </div>
    </div>

    <div class="results-actions">
        <a href="{{ basePath }}/result/{{ test.slug }}/{{ session.session_token }}/pdf"
           class="btn-professional-secondary" target="_blank">📄 Скачать PDF</a>
        <button class="btn-professional-secondary" onclick="copyResultLink()">🔗 Копировать ссылку</button>
        <button class="btn-professional-secondary" onclick="openDeleteModal()">🗑️ Удалить данные</button>
    </div>

    <div class="results-content">
        {% for section in sections %}
        <div class="results-section">
            {% if section.title %}
            <h2 class="section-title">{{ section.title }}</h2>
            {% endif %}
            <div class="section-body">
                {{ include(section.block, section.data|merge({'_section_type': section.type}), ignore_missing = true) }}
            </div>
        </div>
        {% endfor %}
    </div>

    <div class="results-disclaimer">
        <p><strong>Важно:</strong> Результаты носят ознакомительный характер и не являются диагнозом. Для профессиональной интерпретации обратитесь к квалифицированному специалисту.</p>
    </div>
</div>
```

- [ ] **Step 3: Test results page styling**

Run: `php -S localhost:8000 -t public`  
Open: `/result/smil/{token}`

Expected:
- Clean section cards with borders
- Tables with alternating row colors
- Color-coded level badges
- Clear typography hierarchy
- Professional spacing (32px padding)

- [ ] **Step 4: Test print layout**

In browser: File → Print (or Cmd+P)

Expected:
- Action buttons hidden in print
- Sections readable
- Chart visible (if supported by print CSS)

- [ ] **Step 5: Add print styles**

In `public/css/professional-theme.css` add:

```css
@media print {
    .results-actions,
    .btn-professional,
    .btn-professional-secondary {
        display: none !important;
    }

    .results-section {
        page-break-inside: avoid;
        border: 1px solid #000;
    }

    .section-title {
        border-bottom: 2px solid #000;
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add public/css/professional-theme.css templates/result-layout.twig
git commit -m "feat(ui): polish results page with professional styling

- Section cards with clear borders and spacing
- Alternating table rows for readability
- Color-coded level badges (low/normal/elevated/high/very high)
- Professional typography and spacing
- Print-friendly CSS (hide buttons, preserve sections)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 5: Final Validation and Cross-Browser Testing

**Files:**
- None (validation task)

**Interfaces:**
- Consumes: All UI improvements
- Produces: Confirmed professional appearance across pages

- [ ] **Step 1: Homepage validation**

Open: `http://localhost:8000/tests`

Checklist:
- ✓ 4 tests displayed (SMIL, BAI, BDI, HADS)
- ✓ Clean list layout (no shadows, decorations)
- ✓ Georgia headings, PT Sans body text
- ✓ Professional blue buttons
- ✓ All tests clickable

- [ ] **Step 2: Test-taking page validation**

Open: `/test/smil`

Checklist:
- ✓ Progress bar visible and updating
- ✓ "Вопрос N из Total • Выполнено X%"
- ✓ Large answer buttons (≥120×50px)
- ✓ Selected button highlighted
- ✓ Навигация working (Назад/Вперёд)

- [ ] **Step 3: Results page validation**

Open: `/result/smil/{token}`

Checklist:
- ✓ Classic MMPI chart rendering
- ✓ Section cards with clear borders
- ✓ Tables readable with alternating rows
- ✓ Additional scales showing (23 rows)
- ✓ Level badges color-coded
- ✓ Action buttons working (PDF, Copy, Delete)

- [ ] **Step 4: Cross-browser quick check**

Test in Safari, Chrome, Firefox (if available)

Expected: Consistent appearance

- [ ] **Step 5: Responsive check**

Resize browser to ~768px width

Expected: Layout doesn't break (acceptable horizontal scroll)

- [ ] **Step 6: Accessibility quick check**

Check:
- Text contrast ratio (should pass WCAG AA)
- Keyboard navigation (Tab through buttons)
- Screen reader friendliness (semantic HTML)

Expected: Basic accessibility standards met

- [ ] **Step 7: Final commit (if fixes needed)**

If any issues found, fix and commit:

```bash
git add .
git commit -m "fix(ui): cross-browser and accessibility fixes

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- ✅ Task 1: BDI/HADS registration
- ✅ Task 2-4: Professional UI for homepage, test-taking, results
- ✅ Task 5: Validation

**Placeholder scan:**
- ✅ No TBD, TODO
- ✅ All CSS and JS code complete
- ✅ Migration code complete

**Type consistency:**
- ✅ CSS class names consistent (.btn-professional, .test-item)
- ✅ Data attributes consistent (data-total-questions)
- ✅ Template variable names match

---
