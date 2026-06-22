#!/usr/bin/env python3
"""
Скрипт для извлечения 566 вопросов СМИЛ из sob-01.pdf
с гендерными вариантами (мужской и женский)

Использование:
    python3 bin/extract-questions-from-pdf.py

Требования:
    pip install PyPDF2
"""

import json
import re
from pathlib import Path

try:
    import PyPDF2
except ImportError:
    print("Ошибка: Требуется установить PyPDF2")
    print("Выполните: pip install PyPDF2")
    exit(1)


# Контрольные вопросы (27 штук)
CONTROL_QUESTIONS = [
    14, 33, 48, 63, 66, 69, 121, 123, 133, 151, 168, 182, 184, 197, 200, 205,
    266, 275, 293, 334, 349, 350, 462, 464, 474, 542, 551
]


def extract_text_from_pdf(pdf_path: str) -> str:
    """Извлекает текст из PDF файла"""
    print(f"Читаю PDF: {pdf_path}")
    
    with open(pdf_path, 'rb') as file:
        reader = PyPDF2.PdfReader(file)
        text = ""
        for page_num, page in enumerate(reader.pages, 1):
            text += page.extract_text() + "\n"
    
    print(f"  ✅ Извлечено {len(text)} символов")
    return text


def extract_questions_section(text: str, variant: str) -> str:
    """
    Извлекает секцию с вопросами для указанного варианта
    
    Args:
        text: Полный текст PDF
        variant: 'Мужской' или 'Женский'
    
    Returns:
        str: Текст секции с вопросами
    """
    print(f"\nИщу секцию: {variant} вариант")
    
    # Ищем начало секции
    start_pattern = f"{variant} в ?ариант"
    start_match = re.search(start_pattern, text, re.IGNORECASE)
    
    if not start_match:
        print(f"  ⚠️ Не найден маркер '{variant} вариант'")
        return ""
    
    start_idx = start_match.end()
    
    # Ищем конец секции (начало следующей секции или ключи)
    if variant == "Мужской":
        end_pattern = r"Женский в ?ариант"
    else:
        end_pattern = r"(Ключи|Обработка|Интерпретация)"
    
    end_match = re.search(end_pattern, text[start_idx:], re.IGNORECASE)
    
    if end_match:
        end_idx = start_idx + end_match.start()
    else:
        # Если не нашли конец, берём до конца текста
        end_idx = len(text)
    
    section = text[start_idx:end_idx]
    print(f"  ✅ Найдена секция ({len(section)} символов)")
    
    return section


def parse_questions_from_section(section: str) -> dict:
    """
    Парсит вопросы из секции текста
    
    Returns:
        dict: {question_id: question_text}
    """
    questions = {}
    
    # Паттерн: номер вопроса (1-566) + точка + текст до следующего номера
    # Учитываем, что текст может быть разбит на строки
    pattern = r'(\d{1,3})\.\s+([^\n]+(?:\n(?!\d{1,3}\.\s)[^\n]+)*)'
    
    matches = re.finditer(pattern, section)
    
    for match in matches:
        q_num = int(match.group(1))
        q_text = match.group(2)
        
        # Очистка текста
        q_text = re.sub(r'\s+', ' ', q_text)  # Убираем лишние пробелы и переносы
        q_text = q_text.strip()
        
        # Фильтруем: только вопросы 1-566, длина > 10 символов
        if 1 <= q_num <= 566 and len(q_text) > 10:
            questions[q_num] = q_text
    
    return questions


def merge_questions(male_questions: dict, female_questions: dict) -> list:
    """
    Объединяет мужские и женские варианты вопросов
    
    Returns:
        list: Список вопросов в формате JSON
    """
    print("\nОбъединяю вопросы...")
    
    # Все ID от 1 до 566
    all_ids = range(1, 567)
    
    questions = []
    missing_male = []
    missing_female = []
    
    for q_id in all_ids:
        male_text = male_questions.get(q_id, "")
        female_text = female_questions.get(q_id, "")
        
        # Если один из вариантов отсутствует, используем другой
        if not male_text and female_text:
            male_text = female_text
            missing_male.append(q_id)
        elif not female_text and male_text:
            female_text = male_text
            missing_female.append(q_id)
        elif not male_text and not female_text:
            print(f"  ⚠️ Вопрос {q_id}: отсутствуют оба варианта!")
            continue
        
        question = {
            "id": q_id,
            "text_male": male_text,
            "text_female": female_text,
            "is_control": q_id in CONTROL_QUESTIONS
        }
        
        questions.append(question)
    
    print(f"  ✅ Всего вопросов: {len(questions)}")
    
    if missing_male:
        print(f"  ⚠️ Мужской вариант отсутствует для {len(missing_male)} вопросов")
    if missing_female:
        print(f"  ⚠️ Женский вариант отсутствует для {len(missing_female)} вопросов")
    
    return questions


def save_to_json(questions: list, output_path: str):
    """Сохраняет вопросы в JSON файл"""
    print(f"\nСохраняю в {output_path}...")
    
    data = {
        "description": "566 вопросов СМИЛ (MMPI) с гендерными вариантами - адаптация Л.Н. Собчик",
        "source": "source/metod/sob-01.pdf - Методика СМИЛ 566",
        "note": "Каждый вопрос имеет: id, text_male, text_female, is_control. Для большинства вопросов текст идентичен, но есть гендерные различия.",
        "control_questions_info": "27 контрольных вопросов - при инструкции 'Обведите номер данного утверждения кружочком' респондент должен ответить 'Да'",
        "control_questions": CONTROL_QUESTIONS,
        "questions": questions
    }
    
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4)
    
    print(f"  ✅ Сохранено {len(questions)} вопросов")
    
    # Статистика по гендерным различиям
    different_count = sum(1 for q in questions if q['text_male'] != q['text_female'])
    control_count = sum(1 for q in questions if q['is_control'])
    
    print(f"\nСтатистика:")
    print(f"  - Всего вопросов: {len(questions)}")
    print(f"  - С гендерными различиями: {different_count}")
    print(f"  - Контрольных вопросов: {control_count}")


def main():
    """Основная функция"""
    print("=" * 80)
    print("Извлечение вопросов СМИЛ из sob-01.pdf")
    print("=" * 80)
    
    # Пути к файлам
    project_root = Path(__file__).parent.parent
    pdf_path = project_root / "source" / "metod" / "sob-01.pdf"
    output_path = project_root / "modules" / "smil" / "questions-566-gender.json"
    
    # Проверка существования PDF
    if not pdf_path.exists():
        print(f"❌ Ошибка: Файл не найден: {pdf_path}")
        return
    
    # Извлечение текста из PDF
    text = extract_text_from_pdf(str(pdf_path))
    
    # Извлечение секций с вопросами
    male_section = extract_questions_section(text, "Мужской")
    female_section = extract_questions_section(text, "Женский")
    
    if not male_section and not female_section:
        print("\n❌ Ошибка: Не удалось найти секции с вопросами")
        print("Проверьте структуру PDF файла")
        return
    
    # Парсинг вопросов
    print("\nПарсинг мужских вопросов...")
    male_questions = parse_questions_from_section(male_section)
    print(f"  ✅ Извлечено {len(male_questions)} вопросов")
    
    print("\nПарсинг женских вопросов...")
    female_questions = parse_questions_from_section(female_section)
    print(f"  ✅ Извлечено {len(female_questions)} вопросов")
    
    # Объединение и сохранение
    if male_questions or female_questions:
        questions = merge_questions(male_questions, female_questions)
        
        if len(questions) >= 500:  # Должно быть 566
            save_to_json(questions, str(output_path))
            
            print("\n" + "=" * 80)
            print("✅ ГОТОВО!")
            print(f"Файл сохранён: {output_path}")
            print("=" * 80)
        else:
            print(f"\n⚠️ Предупреждение: Извлечено только {len(questions)} вопросов из 566")
            print("Проверьте результат вручную")
            save_to_json(questions, str(output_path))
    else:
        print("\n❌ Ошибка: Не удалось извлечь вопросы")


if __name__ == "__main__":
    main()
