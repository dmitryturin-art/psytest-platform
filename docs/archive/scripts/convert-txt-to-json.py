#!/usr/bin/env python3
"""
Конвертация вопросов из source/qw.txt в JSON формат

Использование:
    python3 bin/convert-txt-to-json.py
"""

import json
import re
from pathlib import Path


# Контрольные вопросы (27 штук)
CONTROL_QUESTIONS = [
    14, 33, 48, 63, 66, 69, 121, 123, 133, 151, 168, 182, 184, 197, 200, 205,
    266, 275, 293, 334, 349, 350, 462, 464, 474, 542, 551
]


def parse_txt_file(txt_path: str) -> tuple:
    """
    Парсит TXT файл с вопросами
    
    Returns:
        tuple: (male_questions, female_questions)
    """
    print(f"Читаю: {txt_path}")
    
    with open(txt_path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Разделяем на мужской и женский варианты
    parts = re.split(r'Женский вариант\.', content, flags=re.IGNORECASE)
    
    if len(parts) < 2:
        print("  ⚠️ Не найден раздел 'Женский вариант'")
        male_section = content
        female_section = ""
    else:
        # Первая часть - до "Женский вариант"
        male_part = parts[0]
        # Ищем начало мужского варианта
        male_match = re.search(r'Мужской вариант\.', male_part, re.IGNORECASE)
        if male_match:
            male_section = male_part[male_match.end():]
        else:
            male_section = male_part
        
        female_section = parts[1]
    
    print(f"  Мужская секция: {len(male_section)} символов")
    print(f"  Женская секция: {len(female_section)} символов")
    
    # Парсим вопросы
    male_questions = parse_questions(male_section)
    female_questions = parse_questions(female_section)
    
    return male_questions, female_questions


def parse_questions(text: str) -> dict:
    """
    Извлекает вопросы из текста
    
    Returns:
        dict: {question_id: question_text}
    """
    questions = {}
    
    # Паттерн: номер + точка + текст до следующего номера
    # Текст может быть многострочным
    lines = text.split('\n')
    
    current_id = None
    current_text = []
    
    for line in lines:
        line = line.strip()
        
        # Пропускаем пустые строки и цитаты
        if not line or line.startswith('"') or line.startswith('Джордж'):
            continue
        
        # Проверяем, начинается ли строка с номера вопроса
        match = re.match(r'^(\d{1,3})\.\s+(.+)$', line)
        
        if match:
            # Сохраняем предыдущий вопрос
            if current_id is not None and current_text:
                questions[current_id] = ' '.join(current_text).strip()
            
            # Начинаем новый вопрос
            current_id = int(match.group(1))
            current_text = [match.group(2)]
        elif current_id is not None:
            # Продолжение текущего вопроса
            current_text.append(line)
    
    # Сохраняем последний вопрос
    if current_id is not None and current_text:
        questions[current_id] = ' '.join(current_text).strip()
    
    return questions


def merge_questions(male_questions: dict, female_questions: dict) -> list:
    """
    Объединяет мужские и женские варианты
    
    Returns:
        list: Список вопросов для JSON
    """
    print("\nОбъединяю вопросы...")
    
    questions = []
    different_count = 0
    
    for q_id in range(1, 567):
        male_text = male_questions.get(q_id, "")
        female_text = female_questions.get(q_id, "")
        
        # Если один из вариантов отсутствует, используем другой
        if not male_text and female_text:
            male_text = female_text
        elif not female_text and male_text:
            female_text = male_text
        elif not male_text and not female_text:
            print(f"  ⚠️ Вопрос {q_id}: отсутствуют оба варианта!")
            continue
        
        # Проверяем гендерные различия
        if male_text != female_text:
            different_count += 1
        
        question = {
            "id": q_id,
            "text_male": male_text,
            "text_female": female_text,
            "is_control": q_id in CONTROL_QUESTIONS
        }
        
        questions.append(question)
    
    print(f"  ✅ Всего вопросов: {len(questions)}")
    print(f"  ✅ С гендерными различиями: {different_count}")
    print(f"  ✅ Контрольных вопросов: {sum(1 for q in questions if q['is_control'])}")
    
    return questions


def save_to_json(questions: list, output_path: str):
    """Сохраняет вопросы в JSON"""
    print(f"\nСохраняю в: {output_path}")
    
    data = {
        "description": "566 вопросов СМИЛ (MMPI) с гендерными вариантами - адаптация Л.Н. Собчик",
        "source": "source/metod/sob-01.pdf -> source/qw.txt",
        "note": "Каждый вопрос имеет: id, text_male, text_female, is_control. Для большинства вопросов текст идентичен, но есть гендерные различия.",
        "control_questions_info": "27 контрольных вопросов - при инструкции 'Обведите номер данного утверждения кружочком' респондент должен ответить 'Да'",
        "control_questions": CONTROL_QUESTIONS,
        "questions": questions
    }
    
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4)
    
    print(f"  ✅ Сохранено!")


def main():
    print("=" * 80)
    print("Конвертация вопросов из TXT в JSON")
    print("=" * 80)
    
    project_root = Path(__file__).parent.parent
    txt_path = project_root / "source" / "qw.txt"
    output_path = project_root / "modules" / "smil" / "questions-566-gender.json"
    
    if not txt_path.exists():
        print(f"❌ Ошибка: Файл не найден: {txt_path}")
        return
    
    # Парсинг TXT
    male_questions, female_questions = parse_txt_file(str(txt_path))
    
    print(f"\nИзвлечено:")
    print(f"  Мужских вопросов: {len(male_questions)}")
    print(f"  Женских вопросов: {len(female_questions)}")
    
    # Объединение
    questions = merge_questions(male_questions, female_questions)
    
    if len(questions) >= 560:  # Должно быть 566
        save_to_json(questions, str(output_path))
        
        # Показываем примеры
        print("\nПримеры вопросов:")
        for i in [0, 2, 18, 24]:  # №1, 3, 19, 25
            q = questions[i]
            print(f"\n  №{q['id']} {'[КОНТР.]' if q['is_control'] else ''}:")
            print(f"    М: {q['text_male'][:60]}...")
            print(f"    Ж: {q['text_female'][:60]}...")
            if q['text_male'] != q['text_female']:
                print(f"    ⚠️ ГЕНДЕРНОЕ РАЗЛИЧИЕ")
        
        print("\n" + "=" * 80)
        print("✅ ГОТОВО!")
        print(f"Файл: {output_path}")
        print("=" * 80)
    else:
        print(f"\n⚠️ Предупреждение: Извлечено только {len(questions)} вопросов из 566")


if __name__ == "__main__":
    main()
