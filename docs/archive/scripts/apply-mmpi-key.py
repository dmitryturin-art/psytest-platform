#!/usr/bin/env python3
"""Apply corrected MMPI key (multi-scale) to questions-566-full.json."""
import json

with open('modules/smil/Scoring/keys/mmpi-key-solomin.json') as f:
    key_items = json.load(f)['items']

with open('modules/smil/questions-566-full.json') as f:
    data = json.load(f)

updated = 0
empty_scales = 0

for q in data['questions']:
    qid = str(q['id'])
    old_scale = q.pop('scale', None)
    old_direction = q.pop('direction', None)

    if qid in key_items:
        new_scales = key_items[qid]
        q['scales'] = new_scales
        updated += 1
    else:
        q['scales'] = []
        empty_scales += 1

data['description'] = '566 вопросов СМИЛ (MMPI) — multi-scale ключи по Соломину'
data['source'] = 'Ключ: И.Л. Соломин, Личностный опросник MMPI, стр. 63-68'
data['note'] = 'Формат scales (массив scale/direction). Тексты вопросов без изменений.'

with open('modules/smil/questions-566-full.json', 'w') as f:
    json.dump(data, f, ensure_ascii=False, indent=2)

print(f"Questions with scales: {updated}")
print(f"Questions without scales (empty): {empty_scales}")
print(f"Total: {len(data['questions'])}")
