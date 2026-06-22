#!/usr/bin/env python3
"""
Очистка текста вопросов от артефактов PDF (лишние пробелы)

Использование:
    python3 bin/clean-questions-text.py
"""

import json
import re
from pathlib import Path


def clean_text(text: str) -> str:
    """
    Очищает текст от артефактов PDF
    
    Примеры:
        "люб лю чит ать" -> "люблю читать"
        "в стаю св ежим" -> "встаю свежим"
    """
    # Убираем лишние пробелы между буквами
    # Паттерн: буква + пробел + 1-2 буквы + пробел
    text = re.sub(r'([а-яА-ЯёЁ])\s+([а-яА-ЯёЁ]{1,2})\s+', r'\1\2 ', text)
    
    # Повторяем несколько раз для сложных случаев
    for _ in range(3):
        text = re.sub(r'([а-яА-ЯёЁ])\s+([а-яА-ЯёЁ]{1,2})\s+', r'\1\2 ', text)
    
    # Убираем множественные пробелы
    text = re.sub(r'\s+', ' ', text)
    
    # Убираем пробелы перед знаками препинания
    text = re.sub(r'\s+([,.:;!?»)])', r'\1', text)
    
    # Убираем пробелы после открывающих кавычек/скобок
    text = re.sub(r'([«(])\s+', r'\1', text)
    
    return text.strip()


def main():
    project_root = Path(__file__).parent.parent
    input_path = project_root / "modules" / "smil" / "questions-566-gender.json"
    output_path = input_path  # Перезаписываем тот же файл
    
    print("=" * 80)
    print("Очистка текста вопросов от артефактов PDF")
    print("=" * 80)
    
    # Читаем JSON
    print(f"\nЧитаю: {input_path}")
    with open(input_path, 'r', encoding='utf-8') as f:
        data = json.load(f)
    
    questions = data['questions']
    print(f"  Загружено {len(questions)} вопросов")
    
    # Очищаем текст
    print("\nОчистка текста...")
    cleaned_count = 0
    
    for q in questions:
        original_male = q['text_male']
        original_female = q['text_female']
        
        q['text_male'] = clean_text(q['text_male'])
        q['text_female'] = clean_text(q['text_female'])
        
        if q['text_male'] != original_male or q['text_female'] != original_female:
            cleaned_count += 1
    
    print(f"  ✅ Очищено {cleaned_count} вопросов")
    
    # Сохраняем
    print(f"\nСохраняю в: {output_path}")
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4)
    
    print("  ✅ Готово!")
    
    # Показываем примеры
    print("\nПримеры очищенных вопросов:")
    for i in [0, 2, 18]:  # Вопросы 1, 3, 19
        q = questions[i]
        print(f"\n  №{q['id']}:")
        print(f"    М: {q['text_male']}")
        print(f"    Ж: {q['text_female']}")
        if q['text_male'] != q['text_female']:
            print(f"    ⚠️ ГЕНДЕРНОЕ РАЗЛИЧИЕ")
    
    print("\n" + "=" * 80)
    print("✅ ГОТОВО!")
    print("=" * 80)


if __name__ == "__main__":
    main()
