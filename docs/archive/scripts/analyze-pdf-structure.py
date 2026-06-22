#!/usr/bin/env python3
"""
Анализ структуры sob-01.pdf для поиска вопросов

Использование:
    python3 bin/analyze-pdf-structure.py > pdf-structure.txt
"""

import re
from pathlib import Path

try:
    import PyPDF2
except ImportError:
    print("Ошибка: Требуется установить PyPDF2")
    print("Выполните: pip install PyPDF2")
    exit(1)


def main():
    project_root = Path(__file__).parent.parent
    pdf_path = project_root / "source" / "metod" / "sob-01.pdf"
    
    print("=" * 80)
    print(f"Анализ структуры: {pdf_path.name}")
    print("=" * 80)
    
    with open(pdf_path, 'rb') as file:
        reader = PyPDF2.PdfReader(file)
        
        print(f"\nВсего страниц: {len(reader.pages)}\n")
        
        # Ищем страницы с вопросами (номера 1-566)
        for page_num in range(len(reader.pages)):
            text = reader.pages[page_num].extract_text()
            
            # Ищем паттерны вопросов
            if re.search(r'\b[1-9]\.\s+[А-Яа-я]', text):
                print(f"\n{'='*80}")
                print(f"СТРАНИЦА {page_num + 1}")
                print(f"{'='*80}")
                
                # Показываем первые 2000 символов
                print(text[:2000])
                
                # Ищем номера вопросов
                question_nums = re.findall(r'\b(\d{1,3})\.\s+[А-Яа-я]', text)
                if question_nums:
                    nums = [int(n) for n in question_nums if int(n) <= 566]
                    if nums:
                        print(f"\n>>> Найдены вопросы: {min(nums)}-{max(nums)}")


if __name__ == "__main__":
    main()
