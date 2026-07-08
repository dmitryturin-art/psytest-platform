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
    # Stop collecting after Т-баллы section begins
    if 'Т-баллы' in line:
        current_scale = None
        continue
    # Detect scale header (handles "Шкала 5 для мужчин:", "Шкала K:" etc.)
    m = re.match(r'Шкала\s+([\w]+).*:', line)
    if m:
        current_scale = m.group(1)
        if current_scale == 'К':
            current_scale = 'K'
        if current_scale == '5' and 'для мужчин' in line:
            current_scale = '5M'
        elif current_scale == '5' and 'для женщин' in line:
            current_scale = '5F'
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

# Build items map: question_id -> [{scale, direction}, ...]
items_map = {}
for scale, dirs in scales.items():
    for dk, ids in dirs.items():
        direction = 1 if dk == 'true' else -1
        for qid in ids:
            key = str(qid)
            if key not in items_map:
                items_map[key] = []
            items_map[key].append({'scale': scale, 'direction': direction})

total_items = sum(len(v) for v in items_map.values())
unique_ids = len(items_map)

# Build per-scale detail with actual question lists
scales_detail = {}
for s, dirs in scales.items():
    scales_detail[s] = {
        'true': dirs.get('true', []),
        'false': dirs.get('false', [])
    }

with open('modules/smil/Scoring/keys/mmpi-key-solomin.json', 'w') as f:
    json.dump({
        'description': 'MMPI-566 key from Solomin (Личностный опросник MMPI), pp. 63-68',
        'format': 'question_id -> [{scale, direction}, ...]',
        'direction': '1 = Верно (True) gives point, -1 = Неверно (False) gives point',
        'total_questions': unique_ids,
        'total_scale_entries': total_items,
        'scales': scales_detail,
        'items': items_map
    }, f, ensure_ascii=False, indent=2)

print(f"Extracted key: {unique_ids} unique questions, {total_items} total scale entries across {len(scales)} scales")
for s in sorted(scales.keys()):
    t = len(scales[s].get('true', []))
    f = len(scales[s].get('false', []))
    print(f"  {s}: {t+f} items ({t} True, {f} False)")
