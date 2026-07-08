#!/usr/bin/env python3
"""
Объединение данных из questions-566-gender.json и questions-566-correct.json

Результат: questions-566-full.json с полями:
- id
- text_male, text_female (из gender)
- scale, direction (из correct)
- is_control (из gender)
"""

import json
from pathlib import Path


def main():
    project_root = Path(__file__).parent.parent
    
    # Загрузка файлов
    gender_path = project_root / "modules" / "smil" / "questions-566-gender.json"
    correct_path = project_root / "modules" / "smil" / "questions-566-correct.json"
    output_path = project_root / "modules" / "smil" / "questions-566-full.json"
    
    print("=" * 80)
    print("Объединение данных вопросов СМИЛ")
    print("=" * 80)
    
    with open(gender_path, 'r', encoding='utf-8') as f:
        gender_data = json.load(f)
    
    with open(correct_path, 'r', encoding='utf-8') as f:
        correct_data = json.load(f)
    
    gender_questions = gender_data['questions']
    correct_questions = correct_data['questions']
    
    print(f"\nЗагружено:")
    print(f"  - Гендерные варианты: {len(gender_questions)} вопросов")
    print(f"  - Ключи шкал: {len(correct_questions)} вопросов")
    
    # Объединение
    merged_questions = []
    
    for i in range(len(gender_questions)):
        gender_q = gender_questions[i]
        correct_q = correct_questions[i] if i < len(correct_questions) else {}
        
        # Проверка ID
        if gender_q['id'] != correct_q.get('id', gender_q['id']):
            print(f"⚠️ Несовпадение ID: {gender_q['id']} != {correct_q.get('id')}")
        
        # Объединение полей
        merged = {
            'id': gender_q['id'],
            'text_male': gender_q['text_male'],
            'text_female': gender_q['text_female'],
            'scale': correct_q.get('scale'),
            'direction': correct_q.get('direction', 1),
            'is_control': gender_q.get('is_control', False)
        }
        
        merged_questions.append(merged)
    
    # Создание результата
    result = {
        'description': "566 вопросов СМИЛ (MMPI) - полная версия",
        'source': "Объединение questions-566-gender.json и questions-566-correct.json",
        'note': "Содержит: text_male, text_female, scale, direction, is_control",
        'control_questions': gender_data.get('control_questions', []),
        'questions': merged_questions
    }
    
    # Сохранение
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(result, f, ensure_ascii=False, indent=4)
    
    print(f"\n✅ Создан файл: {output_path.name}")
    print(f"   Вопросов: {len(merged_questions)}")
    print(f"   Контрольных: {len([q for q in merged_questions if q['is_control']])}")
    print(f"   С ключами шкал: {len([q for q in merged_questions if q['scale']])}")


if __name__ == "__main__":
    main()
