# Корректность СМИЛ: ключи, калькуляторы, тесты — План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Исправить разметку вопросов→шкала в `questions-566-full.json` по эталонному ключу MMPI (Соломин), выделить калькуляторы сырых/T-баллов/валидности в отдельные классы, покрыть PHPUnit-тестами на эталонных значениях psytests.org.

**Architecture:** Ключ извлекается из PDF Соломина (стр. 63-68) → Python-скрипт обновляет JSON. Калькуляторы (`RawScoreCalculator`, `TScoreCalculator`, `ValidityAssessor`, `AdditionalScalesCalculator`) — чистые классы без сайд-эффектов. SmilModule становится оркестратором. Тесты сверяют raw→T по эталону psytests.org.

**Tech Stack:** PHP 8.1+, Python 3 + pymupdf, PHPUnit 10, JSON.

## Global Constraints

- PHP 8.1+ (declare(strict_types=1)).
- НЕ менять линейную формулу T-баллов `T = 50 + 10*(X-M)/σ` — она верна.
- НЕ менять нормы (M, σ, maxRaw) в `basic_scales_norms.json` — они совпадают с Собчик.
- Ключ (вопрос→шкала→направление) — ИСТОЧНИК ИСТИНЫ: Соломин, стр. 63-68.
- Эталон для end-to-end: образец результата psytests.org (L=4→T=49, K=19→T=63, шк1=9→T=63, и т.д.).
- Ветка: `refactor/technical-debt-phase1`.

---

### Task 1: Извлечение ключа из PDF Соломина

**Files:**
- Create: `modules/smil/Scoring/keys/mmpi-key-solomin.json` — структурированный ключ.
- Create: `bin/extract-mmpi-key.py` — скрипт извлечения (одноразовый, в archive потом).

**Context:** PDF Соломина стр. 63-68 содержит полный ключ MMPI в формате:
```
Шкала L:
Верно (0):
Неверно (15):
15 30 45 60 75 90 105 120 135 150 165 195 225 255 285
```

Скрипт парсит текст с этих страниц и выдаёт JSON: `{scale: {direction: [question_ids]}}`.

**Interfaces:**
- Consumes: PDF Соломина (pymupdf).
- Produces: `mmpi-key-solomin.json` — структурированный ключ для 13 шкал (L, F, K, 1-9, 0).

- [ ] **Step 1: Создать скрипт извлечения ключа**

```python
#!/usr/bin/env python3
"""Extract MMPI key from Solomin PDF pages 63-68."""
import fitz, json, re, sys

PDF = sys.argv[1] if len(sys.argv) > 1 else "/Users/dmitrijturin/Библиотека книг/Обучение клинический психолог/Практикум по патопсихологической и нейропсихологической диагностике/Тесты/И.Л. Соломин - Личностный опросник MMPI.pdf"

doc = fitz.open(PDF)
full_text = ""
for p in range(62, 68):  # pages 63-68 (0-indexed)
    full_text += doc[p].get_text()
doc.close()

# Parse key blocks
scales = {}
current_scale = None
for line in full_text.split('\n'):
    line = line.strip()
    # Detect scale header
    m = re.match(r'Шкала\s+(\w+):', line)
    if m:
        current_scale = m.group(1)
        if current_scale == 'К':
            current_scale = 'K'
        scales[current_scale] = {}
        continue
    # Detect direction header
    m = re.match(r'(Верно|Неверно)\s*\((\d+)\):', line)
    if m and current_scale:
        direction = 1 if m.group(1) == 'Верно' else -1
        continue
    # Collect question numbers
    if current_scale and re.match(r'^[\d\s]+$', line):
        nums = [int(n) for n in line.split() if n.isdigit()]
        if nums:
            direction_key = 'true' if direction == 1 else 'false'
            scales[current_scale][direction_key] = scales[current_scale].get(direction_key, []) + nums

# Translate to standard mapping
key_map = {}
for scale, dirs in scales.items():
    for dk, ids in dirs.items():
        for qid in ids:
            key_map[str(qid)] = {
                'scale': scale,
                'direction': 1 if dk == 'true' else -1
            }

with open('modules/smil/Scoring/keys/mmpi-key-solomin.json', 'w') as f:
    json.dump({
        'description': 'MMPI-566 key from Solomin (Личностный опросник MMPI), pp. 63-68',
        'format': 'question_id -> {scale, direction}',
        'direction': '1 = Верно (True) gives point, -1 = Неверно (False) gives point',
        'total_questions': len(key_map),
        'scales': {s: {'true': len(dirs.get('true',[])), 'false': len(dirs.get('false',[]))} for s, dirs in scales.items()},
        'items': key_map
    }, f, ensure_ascii=False, indent=2)

print(f"Extracted key: {len(key_map)} items across {len(scales)} scales")
for s in sorted(scales.keys()):
    t = len(scales[s].get('true', []))
    f = len(scales[s].get('false', []))
    print(f"  {s}: {t+f} items ({t} True, {f} False)")
```

- [ ] **Step 2: Запустить скрипт**

Run:
```bash
python3 bin/extract-mmpi-key.py
```
Expected: 566 items across 13 scales.

- [ ] **Step 3: Проверить количество пунктов по шкалам**

Run:
```bash
python3 -c "
import json
d = json.load(open('modules/smil/Scoring/keys/mmpi-key-solomin.json'))
for s in sorted(d['scales']):
    info = d['scales'][s]
    print(f'{s}: {info[\"true\"]+info[\"false\"]} items (T={info[\"true\"]}, F={info[\"false\"]})')
"
```
Expected: L=15, F=65, K=30, 1=33, 2=60, 3=59, 4=50, 5=60 (M)/60 (F), 6=40, 7=47, 8=78, 9=46, 0=70.

- [ ] **Step 4: Commit**

```bash
git add modules/smil/Scoring/keys/mmpi-key-solomin.json bin/extract-mmpi-key.py
git commit -m "feat(smil): extract correct MMPI-566 key from Solomin PDF

Key source: И.Л. Соломин, Личностный опросник MMPI, стр. 63-68.
566 items across 13 scales (L,F,K,1-9,0) with correct directions.
Extraction script: bin/extract-mmpi-key.py"
```

---

### Task 2: Исправление questions-566-full.json по ключу

**Files:**
- Modify: `modules/smil/questions-566-full.json` — обновить scale/direction для всех 566 вопросов.
- Create: `bin/apply-mmpi-key.py` — скрипт применения ключа.

**Context:** Текущий JSON имеет неправильные scale/direction. Применяем извлечённый ключ, сохраняя text_male/text_female.

**Interfaces:**
- Consumes: `mmpi-key-solomin.json` (Task 1).
- Produces: исправленный `questions-566-full.json`.

- [ ] **Step 1: Создать скрипт применения ключа**

```python
#!/usr/bin/env python3
"""Apply corrected MMPI key to questions-566-full.json."""
import json

with open('modules/smil/Scoring/keys/mmpi-key-solomin.json') as f:
    key = json.load(f)['items']

with open('modules/smil/questions-566-full.json') as f:
    data = json.load(f)

updated = unchanged = missing = 0
for q in data['questions']:
    qid = str(q['id'])
    if qid in key:
        if q['scale'] != key[qid]['scale'] or q['direction'] != key[qid]['direction']:
            q['scale'] = key[qid]['scale']
            q['direction'] = key[qid]['direction']
            updated += 1
        else:
            unchanged += 1
    else:
        missing += 1

# Update description
data['description'] = '566 вопросов СМИЛ (MMPI) — ключи исправлены по Соломину'
data['source'] = 'Ключ: И.Л. Соломин, Личностный опросник MMPI, стр. 63-68'
data['note'] = 'scale/direction исправлены. Тексты вопросов без изменений.'

with open('modules/smil/questions-566-full.json', 'w') as f:
    json.dump(data, f, ensure_ascii=False, indent=2)

print(f"Updated: {updated}, Unchanged: {unchanged}, Missing in key: {missing}")
```

- [ ] **Step 2: Запустить скрипт**

Run: `python3 bin/apply-mmpi-key.py`
Expected: большинство вопросов обновлено (scale/direction были неверны).

- [ ] **Step 3: Проверить распределение по шкалам**

Run:
```bash
python3 -c "
import json
d = json.load(open('modules/smil/questions-566-full.json'))
from collections import Counter
scales = Counter(q['scale'] for q in d['questions'])
dirs = Counter(q['direction'] for q in d['questions'])
for s in sorted(scales): print(f'{s}: {scales[s]}')
print(f'directions: {dict(dirs)}')
print(f'total: {len(d[\"questions\"])}')
"
```
Expected: L=15, F=65, K=30, 1=33, 2=60, 3=59, 4=50, 5=60, 6=40, 7=47, 8=78, 9=46, 0=70, dirs: {1: ~390, -1: ~176}.

- [ ] **Step 4: Проверить загрузку через SmilModule**

Run:
```bash
php -r "require 'vendor/autoload.php'; \$m = new PsyTest\Modules\Smil\SmilModule(); echo count(\$m->getQuestions());"
```
Expected: `566`.

- [ ] **Step 5: Commit**

```bash
git add modules/smil/questions-566-full.json bin/apply-mmpi-key.py
git commit -m "fix(smil): correct question-to-scale mapping using Solomin key

Applied verified MMPI-566 key from Solomin pp. 63-68.
Fixed scale/direction for all 566 questions. Text and other fields unchanged.
Previous mapping had 40 items for L (should be 15), 40 for F (should be 65), etc."
```

---

### Task 3: RawScoreCalculator — выделение расчёта сырых баллов

**Files:**
- Create: `modules/smil/Scoring/RawScoreCalculator.php`
- Create: `tests/Smil/RawScoreCalculatorTest.php`

**Context:** Извлекает логику `SmilModule::calculateRawScores()` в отдельный класс. Принимает ответы + вопросы, возвращает сырые баллы (13 шкал).

**Interfaces:**
```php
class RawScoreCalculator {
    public function calculate(array $questions, array $answers): array
    // $answers: [question_id => answer], answer ∈ {0, 1, 2}
    // returns: ['L' => int, 'F' => int, ..., '0' => int]
}
```

- [ ] **Step 1: Создать RawScoreCalculator**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules\Smil\Scoring;

final class RawScoreCalculator
{
    public const ANSWER_YES = 1;
    public const ANSWER_NO = 0;
    public const ANSWER_UNKNOWN = 2;

    /** @var array<int, array{scale: string, direction: int}> */
    private array $questionMap;

    /**
     * @param array $questions Array of {id, scale, direction, ...}
     */
    public function __construct(array $questions)
    {
        $this->questionMap = [];
        foreach ($questions as $q) {
            $this->questionMap[(int) $q['id']] = [
                'scale' => (string) $q['scale'],
                'direction' => (int) ($q['direction'] ?? 1),
            ];
        }
    }

    /**
     * Calculate raw scores from answers.
     *
     * @param array<int, int|string> $answers question_id => answer
     * @return array<string, int> scale_code => raw_score
     */
    public function calculate(array $answers): array
    {
        $rawScores = [
            'L' => 0, 'F' => 0, 'K' => 0,
            '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0,
            '6' => 0, '7' => 0, '8' => 0, '9' => 0, '0' => 0,
        ];

        foreach ($answers as $questionId => $answer) {
            $qid = (int) $questionId;
            if (!isset($this->questionMap[$qid])) {
                continue;
            }

            $answerInt = (int) $answer;
            if ($answerInt === self::ANSWER_UNKNOWN) {
                continue;
            }

            $map = $this->questionMap[$qid];
            $scale = $map['scale'];

            if (!isset($rawScores[$scale])) {
                continue;
            }

            $isYes = ($answerInt === self::ANSWER_YES);

            if ($map['direction'] === 1) {
                $rawScores[$scale] += $isYes ? 1 : 0;
            } else {
                $rawScores[$scale] += $isYes ? 0 : 1;
            }
        }

        return $rawScores;
    }

    public function countUnknown(array $answers): int
    {
        $count = 0;
        foreach ($answers as $qid => $answer) {
            if (!is_numeric($qid)) continue;
            if ((int) $answer === self::ANSWER_UNKNOWN) $count++;
        }
        return $count;
    }
}
```

- [ ] **Step 2: Тест — все ответы «Верно»**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\RawScoreCalculator;

final class RawScoreCalculatorTest extends TestCase
{
    private RawScoreCalculator $calc;

    protected function setUp(): void
    {
        $questions = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/questions-566-full.json'),
            true
        )['questions'];
        $this->calc = new RawScoreCalculator($questions);
    }

    public function testAllYesAnswersProducesCorrectMaxima(): void
    {
        $questions = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/questions-566-full.json'),
            true
        )['questions'];
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 1; // Все "Верно"
        }

        $raw = $this->calc->calculate($answers);

        // L: 15 items, all direction=-1, all "Верно" → 0 points
        $this->assertSame(0, $raw['L'], 'L: all False-dir items with Yes→0');
        
        // F: 65 items (45 True + 20 False directions)
        $this->assertSame(45, $raw['F'], 'F: 45 True-dir items with Yes→45');
        
        // K: 30 items (1 True + 29 False directions)
        $this->assertSame(1, $raw['K'], 'K: 1 True-dir item with Yes→1');
    }

    public function testAllNoAnswers(): void
    {
        $questions = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/questions-566-full.json'),
            true
        )['questions'];
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 0; // Все "Нет"
        }

        $raw = $this->calc->calculate($answers);

        // L: 15 items, all direction=-1, all "Нет" → 15 points (max)
        $this->assertSame(15, $raw['L'], 'L: max 15 with all No');
        
        // F: 45 True-dir with No→0, 20 False-dir with No→20
        $this->assertSame(20, $raw['F'], 'F: 20 False-dir items with No→20');
    }

    public function testUnknownAnswersAreSkipped(): void
    {
        $questions = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/questions-566-full.json'),
            true
        )['questions'];
        $answers = [];
        foreach ($questions as $q) {
            $answers[$q['id']] = 2; // Все "Не знаю"
        }

        $raw = $this->calc->calculate($answers);
        foreach ($raw as $scale => $score) {
            $this->assertSame(0, $score, "$scale should be 0 with all Unknown");
        }
    }
}
```

- [ ] **Step 3: Запустить тесты**

Run: `composer test -- --filter=RawScoreCalculatorTest`
Expected: 3 tests pass.

- [ ] **Step 4: Commit**

```bash
git add modules/smil/Scoring/RawScoreCalculator.php tests/Smil/RawScoreCalculatorTest.php
git commit -m "feat(smil): extract RawScoreCalculator from SmilModule

Pure class: questions + answers → 13 raw scores. Handles direction
(+1/-1) and SKIP for ANSWER_UNKNOWN. Tests verify maxima for all-Yes
and all-No scenarios."
```

---

### Task 4: TScoreCalculator — выделение T-баллов

**Files:**
- Create: `modules/smil/Scoring/TScoreCalculator.php`
- Create: `tests/Smil/TScoreCalculatorTest.php`

**Context:** Извлекает `convertToTScores()` в отдельный класс. Линейная формула с K-коррекцией.

**Interfaces:**
```php
class TScoreCalculator {
    public function __construct(array $norms)
    public function calculate(array $rawScores, string $gender): array
    // Returns: ['L' => float, ..., '0' => float] — T-scores (20-120), rounded
}
```

- [ ] **Step 1: TScoreCalculator**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules\Smil\Scoring;

final class TScoreCalculator
{
    private const T_MIN = 20;
    private const T_MAX = 120;

    /** @var array<string, array> */
    private array $norms;

    public function __construct(array $norms)
    {
        $this->norms = $norms;
    }

    /**
     * Convert raw scores to T-scores with K-correction.
     *
     * Formula: T = 50 + 10 * (X - M) / σ, clamped to [20, 120]
     * K-correction: X' = X + round(K * factor)
     */
    public function calculate(array $rawScores, string $gender): array
    {
        $tScores = [];

        foreach ($rawScores as $scale => $rawScore) {
            if (!isset($this->norms[$scale])) {
                $tScores[$scale] = 50.0;
                continue;
            }

            $scaleNorms = $this->norms[$scale][$gender] ?? $this->norms[$scale]['male'];
            $M = (float) $scaleNorms['M'];
            $delta = (float) $scaleNorms['delta'];

            $correctedRaw = (float) $rawScore;
            $kFactor = $this->norms[$scale]['kCorrectionFactor'] ?? null;

            if ($kFactor !== null && isset($rawScores['K'])) {
                $kCorrection = (int) round((float) $rawScores['K'] * (float) $kFactor);
                $correctedRaw = (float) $rawScore + $kCorrection;
            }

            if ($delta == 0.0) {
                $tScores[$scale] = 50.0;
            } else {
                $tScore = 50.0 + 10.0 * ($correctedRaw - $M) / $delta;
                $tScores[$scale] = (float) max(self::T_MIN, min(self::T_MAX, round($tScore)));
            }
        }

        return $tScores;
    }
}
```

- [ ] **Step 2: TScoreCalculatorTest — эталонные значения psytests.org**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\TScoreCalculator;

final class TScoreCalculatorTest extends TestCase
{
    private TScoreCalculator $calc;

    protected function setUp(): void
    {
        $norms = json_decode(
            file_get_contents(__DIR__ . '/../../modules/smil/basic_scales_norms.json'),
            true
        )['scales'];
        $this->calc = new TScoreCalculator($norms);
    }

    public function testReferenceValuesFromPsytestsOrg(): void
    {
        // Raw scores from psytests.org sample (female)
        $raw = [
            'L' => 4, 'F' => 7, 'K' => 19,
            '1' => 9, '2' => 16, '3' => 29, '4' => 20, '5' => 31,
            '6' => 11, '7' => 12, '8' => 14, '9' => 18, '0' => 14,
        ];

        $tScores = $this->calc->calculate($raw, 'female');

        // Reference T-scores from psytests.org
        $this->assertEquals(49, $tScores['L'], 'L: raw=4 → T=49');
        $this->assertEquals(58, $tScores['F'], 'F: raw=7 → T=58');
        $this->assertEquals(63, $tScores['K'], 'K: raw=19 → T=63');
        // Scale 1: raw=9, +0.5K(19*0.5=10) → 19, M=12.90, σ=4.83
        $this->assertEquals(63, $tScores['1'], 'Hs: raw=9 +0.5K→19 → T=63');
    }

    public function testTScoreRangeClamping(): void
    {
        // Extremely high raw → should clamp at 120
        $raw = array_fill_keys(['L','F','K','1','2','3','4','5','6','7','8','9','0'], 1000);
        $tScores = $this->calc->calculate($raw, 'male');
        foreach ($tScores as $t) {
            $this->assertLessThanOrEqual(120, $t);
        }
    }

    public function testZeroRawGivesValidTRange(): void
    {
        $raw = array_fill_keys(['L','F','K','1','2','3','4','5','6','7','8','9','0'], 0);
        $tScores = $this->calc->calculate($raw, 'male');
        foreach ($tScores as $t) {
            $this->assertGreaterThanOrEqual(20, $t);
            $this->assertLessThanOrEqual(50, $t, 'Zero raw should give low T');
        }
    }
}
```

- [ ] **Step 3: Run tests**

Run: `composer test -- --filter=TScoreCalculatorTest`
Expected: 3 tests pass, reference values match psytests.org.

- [ ] **Step 4: Commit**

```bash
git add modules/smil/Scoring/TScoreCalculator.php tests/Smil/TScoreCalculatorTest.php
git commit -m "feat(smil): extract TScoreCalculator with K-correction

Linear formula T=50+10*(X-M)/σ, clamped [20,120]. K-correction applied
per scale factor. Tests verify against psytests.org reference values
(L=4→49, K=19→63, Hs=9+0.5K→63)."
```

---

### Task 5: ValidityAssessor — выделение валидации

**Files:**
- Create: `modules/smil/Scoring/ValidityAssessor.php`
- Create: `tests/Smil/ValidityAssessorTest.php`

**Context:** Извлекает `assessValidity()` + `calculateUnknownScale()` + `calculateControlScale()`.

- [ ] **Step 1: ValidityAssessor**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Modules\Smil\Scoring;

final class ValidityAssessor
{
    private const CONTROL_QUESTIONS = [
        14, 33, 48, 63, 66, 69, 121, 123, 133, 151, 168, 182, 184,
        197, 200, 205, 266, 275, 293, 334, 349, 350, 462, 464, 474, 542, 551,
    ];

    /**
     * Assess protocol validity from T-scores, answers, and control questions.
     */
    public function assess(array $tScores, array $answers): array
    {
        $L = (int) ($tScores['L'] ?? 50);
        $F = (int) ($tScores['F'] ?? 50);
        $K = (int) ($tScores['K'] ?? 50);
        $valid = true;
        $warnings = [];

        $unknownCount = $this->countUnknown($answers);
        $controlScore = $this->countControlCorrect($answers);

        if ($controlScore < 20) {
            $valid = false;
            $warnings[] = "Протокол недостоверен: низкая внимательность (QC = {$controlScore} < 20)";
        }

        if ($unknownCount > 70) {
            $valid = false;
            $warnings[] = "Протокол недостоверен: слишком много ответов \"Не знаю\" ({$unknownCount} > 70)";
        } elseif ($unknownCount > 60) {
            $warnings[] = "Сомнительная достоверность: много ответов \"Не знаю\" ({$unknownCount})";
        } elseif ($unknownCount > 40) {
            $warnings[] = "Настороженность: повышенное количество ответов \"Не знаю\" ({$unknownCount})";
        }

        if ($L >= 65) {
            $valid = false;
            $warnings[] = 'Высокая социальная желательность — результаты могут быть недостоверны';
        }

        if ($F >= 70) {
            $valid = false;
            $warnings[] = 'Высокий показатель F — возможны случайные ответы или преувеличение проблем';
        } elseif ($F >= 65) {
            $warnings[] = 'Повышенный показатель F — возможна тенденция к преувеличению';
        }

        if ($K >= 65) {
            $warnings[] = 'Высокая защитная позиция — клинические шкалы могут быть занижены';
        } elseif ($K <= 35) {
            $warnings[] = 'Низкая защитная позиция — возможна излишняя откровенность';
        }

        $fkIndex = $F - $K;
        if ($fkIndex > 20) {
            $warnings[] = 'Индекс F-K повышен — возможна симуляция';
        } elseif ($fkIndex < -15) {
            $warnings[] = 'Индекс F-K понижен — возможна диссимуляция';
        }

        return [
            'is_valid' => $valid,
            'warnings' => $warnings,
            'L_score' => $L, 'F_score' => $F, 'K_score' => $K,
            'FK_index' => $fkIndex,
            'unknown_count' => $unknownCount,
            'control_score' => $controlScore,
        ];
    }

    private function countUnknown(array $answers): int
    {
        $count = 0;
        foreach ($answers as $qid => $answer) {
            if (!is_numeric($qid)) continue;
            if ((int) $answer === 2) $count++;
        }
        return $count;
    }

    private function countControlCorrect(array $answers): int
    {
        $correct = 0;
        foreach (self::CONTROL_QUESTIONS as $cq) {
            if (isset($answers[$cq]) && (int) $answers[$cq] === 1) {
                $correct++;
            }
        }
        return $correct;
    }
}
```

- [ ] **Step 2: ValidityAssessorTest**

```php
<?php
declare(strict_types=1);

namespace PsyTest\Tests\Smil;

use PHPUnit\Framework\TestCase;
use PsyTest\Modules\Smil\Scoring\ValidityAssessor;

final class ValidityAssessorTest extends TestCase
{
    public function testAllValidProfile(): void
    {
        $assessor = new ValidityAssessor();
        $tScores = ['L' => 50, 'F' => 55, 'K' => 50, '1' => 50, '2' => 55];
        $answers = [];
        foreach (ValidityAssessor::CONTROL_QUESTIONS as $cq) {
            $answers[$cq] = 1; // All control correct
        }

        $result = $assessor->assess($tScores, $answers);
        $this->assertTrue($result['is_valid']);
        $this->assertSame(27, $result['control_score']);
        $this->assertEmpty($result['warnings']);
    }

    public function testHighLInvalidates(): void
    {
        $assessor = new ValidityAssessor();
        $tScores = ['L' => 70, 'F' => 50, 'K' => 50];
        $answers = [];
        foreach (ValidityAssessor::CONTROL_QUESTIONS as $cq) {
            $answers[$cq] = 1;
        }

        $result = $assessor->assess($tScores, $answers);
        $this->assertFalse($result['is_valid']);
        $this->assertStringContainsString('социальная желательность', $result['warnings'][0]);
    }

    public function testLowControlScoreInvalidates(): void
    {
        $assessor = new ValidityAssessor();
        $tScores = ['L' => 50, 'F' => 50, 'K' => 50];
        $answers = []; // No answers → QC = 0

        $result = $assessor->assess($tScores, $answers);
        $this->assertFalse($result['is_valid']);
        $this->assertSame(0, $result['control_score']);
    }
}
```

- [ ] **Step 3: Run tests + commit**

```bash
composer test -- --filter=ValidityAssessorTest
git add modules/smil/Scoring/ValidityAssessor.php tests/Smil/ValidityAssessorTest.php
git commit -m "feat(smil): extract ValidityAssessor from SmilModule"
```

---

### Task 6: AdditionalScalesCalculator

**Files:**
- Create: `modules/smil/Scoring/AdditionalScalesCalculator.php`
- Create: `tests/Smil/AdditionalScalesCalculatorTest.php`

- [ ] **Step 1: AdditionalScalesCalculator** — извлекает логику `calculateAdditionalScales()`.

Тест проверяет, что калькулятор возвращает ненулевые данные при загрузке норм из `additional-scales-norms.json`.

- [ ] **Step 2: Commit**

```bash
git add modules/smil/Scoring/AdditionalScalesCalculator.php tests/Smil/AdditionalScalesCalculatorTest.php
git commit -m "feat(smil): extract AdditionalScalesCalculator"
```

---

### Task 7: Интеграция калькуляторов в SmilModule

**Files:**
- Modify: `modules/smil/SmilModule.php` — заменить внутренние методы на вызовы калькуляторов.

**Context:** SmilModule теперь оркестратор. `calculateResults()` делегирует калькуляторам.

- [ ] **Step 1: Обновить SmilModule — внедрить калькуляторы**

В конструкторе создать экземпляры:
```php
$this->rawScoreCalc = new RawScoreCalculator($this->getQuestions());
$this->tScoreCalc = new TScoreCalculator($this->loadBasicScalesNorms());
$this->validityAssessor = new ValidityAssessor();
$this->additionalCalc = new AdditionalScalesCalculator(...);
```

Метод `calculateResults()` переписать на вызовы калькуляторов.

**Старые методы удалить:** `calculateRawScores()`, `convertToTScores()`, `assessValidity()`, `calculateUnknownScale()`, `calculateControlScale()`, `calculateAdditionalScales()` — заменить на вызовы калькуляторов.

- [ ] **Step 2: Проверка**

Run: `composer test && php test-architecture.php`
Expected: все тесты проходят, архитектура без ошибок.

- [ ] **Step 3: Commit**

```bash
git add modules/smil/SmilModule.php
git commit -m "refactor(smil): integrate calculators into SmilModule

SmilModule now delegates to RawScoreCalculator, TScoreCalculator,
ValidityAssessor, AdditionalScalesCalculator. Removed ~200 lines of
inline calculation logic."
```

---

### Task 8: End-to-end тест — сверка с образцом psytests.org

**Files:**
- Create: `tests/Smil/SmilEndToEndTest.php`

- [ ] **Step 1: End-to-end тест**

Создать фиктивные ответы, которые дают известные raw-баллы из образца, и проверить всю цепочку: ответы → rawScores → tScores → validity.

Для простоты: проверить, что калькуляторы + SmilModule.calculateResults() дают согласованный результат.

- [ ] **Step 2: Commit**

---

### Task 9: Финальная проверка и очистка

- [ ] **Step 1: Удалить bin/extract-mmpi-key.py и bin/apply-mmpi-key.py в docs/archive/scripts/ (одноразовые)**

- [ ] **Step 2: Полный прогон**

```bash
composer test
composer analyse
php test-architecture.php
```

- [ ] **Step 3: Commit**

---

## Критерии приёмки

- [ ] Ключи СМИЛ исправлены (L=15, F=65, K=30, ...) — верифицировано тестами.
- [ ] RawScoreCalculator, TScoreCalculator, ValidityAssessor, AdditionalScalesCalculator — отдельные классы с тестами.
- [ ] T-баллы сверены с эталоном psytests.org (L=4→49, K=19→63, шк1=9→63).
- [ ] SmilModule использует калькуляторы, старые методы удалены.
- [ ] `composer test` проходит (≥10 тестов).
- [ ] `php test-architecture.php` без ошибок.
