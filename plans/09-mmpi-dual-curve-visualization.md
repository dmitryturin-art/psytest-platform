# План: Двухкривое отображение профиля MMPI/СМИЛ

**Дата:** 28 февраля 2026  
**Приоритет:** Высокий  
**Статус:** Планирование

---

## 📋 Задача

Реализовать классическое двухкривое отображение профиля MMPI/СМИЛ, где:

1. **Первая кривая** - шкалы достоверности: L, F, K
2. **Вторая кривая** - базовые клинические шкалы: 1-Hs, 2-D, 3-Hy, 4-Pd, 5-Mf, 6-Pa, 7-Pt, 8-Sc, 9-Ma, 0-Si

Обе кривые располагаются на одном графике с общей осью T-баллов для соответствия стандартному формату психодиагностических профилей MMPI и методики СМИЛ Собчик.

---

## 🎯 Цель

Привести визуализацию в соответствие с классическим форматом MMPI, используемым психологами и психиатрами.

---

## 📊 Эталон

**Источник:** [`source/Тест СМИЛ _ MMPI - Мой результат.html`](../source/Тест СМИЛ _ MMPI - Мой результат.html)

**Структура эталонного графика:**

```html
<svg viewBox="0 0 560 621">
  <!-- Линии первой кривой (L, F, K) -->
  <line x1="102" y1="..." x2="138" y2="..." stroke="darkblue" stroke-width="4"/>
  <line x1="138" y1="..." x2="168" y2="..." stroke="darkblue" stroke-width="4"/>
  
  <!-- Линии второй кривой (1-9, 0) -->
  <line x1="208" y1="..." x2="238" y2="..." stroke="darkblue" stroke-width="4"/>
  <!-- ... -->
  
  <!-- Точки первой кривой -->
  <circle cx="102" cy="..." fill="darkgreen" r="5"/>
  <circle cx="138" cy="..." fill="darkgreen" r="5"/>
  <circle cx="168" cy="..." fill="darkgreen" r="5"/>
  
  <!-- Точки второй кривой -->
  <circle cx="208" cy="..." fill="darkgreen" r="5"/>
  <!-- ... -->
</svg>
```

**Координаты X (из эталона):**
- **Кривая 1 (валидность):** L=102, F=138, K=168
- **Кривая 2 (клинические):** 1=208, 2=238, 3=270, 4=304, 5=338, 6=373, 7=412, 8=444, 9=478, 0=513

---

## 🔧 Реализация

### Этап 1: Обновить JavaScript

**Файл:** [`public/js/smil-profile-classic.js`](../public/js/smil-profile-classic.js)

**Изменения:**

```javascript
function renderClassicProfile(container, scores, labels) {
    // Разделить шкалы на две группы
    const validityScores = scores.slice(0, 3);   // L, F, K
    const clinicalScores = scores.slice(3);      // 1-9, 0
    
    const validityPositions = [102, 138, 168];
    const clinicalPositions = [208, 238, 270, 304, 338, 373, 412, 444, 478, 513];
    
    const html = `
        <div class="classic-profile-container">
            <div class="classic-profile-holder">
                <img src="/images/smil-profile-bg.png" alt="СМИЛ профиль" class="profile-background">
                <svg class="profile-overlay" viewBox="0 0 560 621">
                    ${renderCurve(validityScores, validityPositions, 'validity')}
                    ${renderCurve(clinicalScores, clinicalPositions, 'clinical')}
                </svg>
            </div>
        </div>
    `;
    
    container.innerHTML = html;
}

function renderCurve(scores, xPositions, curveType) {
    let svg = '';
    
    // Линии
    for (let i = 0; i < scores.length - 1; i++) {
        const x1 = xPositions[i];
        const y1 = tScoreToY(scores[i]);
        const x2 = xPositions[i + 1];
        const y2 = tScoreToY(scores[i + 1]);
        
        svg += `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}" 
                     stroke="darkblue" stroke-width="4"/>`;
    }
    
    // Точки
    for (let i = 0; i < scores.length; i++) {
        const x = xPositions[i];
        const y = tScoreToY(scores[i]);
        const color = getPointColor(scores[i]);
        
        svg += `<circle cx="${x}" cy="${y}" fill="${color}" r="5" 
                       stroke="white" stroke-width="1"/>`;
    }
    
    return svg;
}
```

---

### Этап 2: Обновить SmilModule.php

**Файл:** [`modules/smil/SmilModule.php`](../modules/smil/SmilModule.php)

**Метод:** `renderProfileChart()`

**Изменения:**

```php
protected function renderProfileChart(array $tScores): string
{
    // Validity scales
    $validityScales = ['L', 'F', 'K'];
    $validityData = [];
    foreach ($validityScales as $scale) {
        $validityData[] = $tScores[$scale] ?? 50;
    }
    
    // Clinical scales
    $clinicalScales = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];
    $clinicalData = [];
    foreach ($clinicalScales as $scale) {
        $clinicalData[] = $tScores[$scale] ?? 50;
    }
    
    // Combine for JavaScript
    $allScores = array_merge($validityData, $clinicalData);
    $allLabels = array_merge($validityScales, $clinicalScales);
    
    $dataJson = json_encode($allScores);
    $labelsJson = json_encode($allLabels);
    
    $html = '<div class="profile-chart-container">';
    $html .= '<h3>📊 Профильный лист MMPI</h3>';
    $html .= '<div id="smilClassicProfile" data-scores=\'' . $dataJson . '\' data-labels=\'' . $labelsJson . '\'></div>';
    $html .= '</div>';
    
    return $html;
}
```

---

### Этап 3: Добавить легенду

**CSS стили:**

```css
.profile-legend {
    display: flex;
    justify-content: center;
    gap: 2rem;
    margin-top: 1rem;
    font-size: 0.875rem;
}

.legend-curve {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.legend-line {
    width: 30px;
    height: 3px;
    background: darkblue;
}

.legend-point {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 1px solid white;
}

.legend-point.normal {
    background: darkgreen;
}

.legend-point.deviation {
    background: crimson;
}
```

---

## 📝 Критерии готовности

- [ ] Две отдельные кривые на одном графике
- [ ] Кривая 1: L, F, K (слева)
- [ ] Кривая 2: 1-9, 0 (справа)
- [ ] Общая ось T-баллов (20-100)
- [ ] Цветовая кодировка точек (зелёный/красный)
- [ ] Фоновое изображение с сеткой
- [ ] Легенда с объяснением кривых
- [ ] Адаптивность (responsive)

---

## 🎨 Визуальное представление

```
T-баллы
  100 ┤
   90 ┤
   80 ┤
   70 ┤     ●━━●━━━━━●━━━━━●
   60 ┤    /          \    /
   50 ┤   ●            ●━━●
   40 ┤  /
   30 ┤ ●
   20 ┤
      └─────────────────────────────
       L F K 1 2 3 4 5 6 7 8 9 0
       └─┬─┘ └──────┬──────────┘
      Валидность  Клинические
```

---

## ⏱️ Оценка

**Сложность:** Средняя  
**Файлы для изменения:** 2-3  
**Зависимости:** Нет

---

## 📚 Ссылки

- Эталон: [`source/Тест СМИЛ _ MMPI - Мой результат.html:88-93`](../source/Тест СМИЛ _ MMPI - Мой результат.html:88)
- Текущая реализация: [`public/js/smil-profile-classic.js`](../public/js/smil-profile-classic.js)
- Метод рендеринга: [`modules/smil/SmilModule.php:1697`](../modules/smil/SmilModule.php:1697)
