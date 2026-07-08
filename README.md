# PsyTest Platform - Модульная система психологического тестирования

[![PHP](https://img.shields.io/badge/PHP-8.1+-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-Proprietary-red.svg)](LICENSE)

**PsyTest** — модульный веб-сервис для проведения онлайн-психологических тестов с профессиональным анализом результатов, поддержкой парных сравнений и возможностью платной AI-интерпретации.

## ✨ Особенности

- 🧩 **Модульная архитектура** — каждый тест изолирован, легко добавлять новые методики
- 🎨 **Единый дизайн** — все тесты в одном стиле с профессиональным UI/UX
- 🔐 **Без регистрации** — доступ по уникальным ссылкам
- 🛡️ **Безопасность** — криптографические токены, защита от XSS/CSRF, 152-ФЗ
- 📄 **PDF-отчёты** — автоматическая генерация бланков с результатами
- 👥 **Парный режим** — сравнение результатов партнёров
- 🤖 **AI-интерпретация** — развёрнутый анализ через OpenRouter API
- 💳 **ЮMoney** — интеграция платёжной системы

## 📋 Содержание

- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Структура проекта](#структура-проекта)
- [Создание тестов](#создание-тестов)
- [Документация](#документация)
- [Лицензия](#лицензия)

## 📋 Требования

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.2+
- Composer
- Apache с mod_rewrite (или Nginx)

## 🚀 Установка

### 1. Клонирование репозитория

```bash
git clone https://github.com/YOUR_USERNAME/psytest-platform.git
cd psytest-platform
```

### 2. Установка зависимостей

```bash
composer install
```

### 3. Настройка окружения

```bash
cp .env.example .env
# Отредактируйте .env, указав ваши данные
```

### 4. Инициализация базы данных

```bash
php bin/install-db.php
```

### 5. Настройка веб-сервера

**Apache:**
```apache
DocumentRoot /path/to/psytest-platform/public
<Directory /path/to/psytest-platform/public>
    AllowOverride All
    Require all granted
</Directory>
```

**Nginx:**
```nginx
server {
    listen 80;
    server_name psytest.local;
    root /path/to/psytest-platform/public;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## ⚡ Быстрый старт

### Локальная разработка

```bash
# Запуск встроенного сервера PHP
php -S localhost:8000 -t public

# Открыть в браузере
# http://localhost:8000/tests
```

### Docker (опционально)

```bash
# Запустить MySQL
docker run --name psytest-mysql \
  -e MYSQL_ROOT_PASSWORD=secret \
  -e MYSQL_DATABASE=psytest \
  -p 3306:3306 \
  -d mysql:8

# Инициализировать БД
php bin/install-db.php
```

## 📁 Структура проекта

```
psytest-platform/
├── bin/                    # CLI утилиты
├── config/                 # Конфигурация
├── core/                   # Ядро системы
├── controllers/            # Контроллеры
├── modules/                # Модули тестов
│   └── smil/               # Тест СМИЛ (MMPI)
├── services/               # Сервисы (платежи, AI, email)
├── templates/              # Twig шаблоны
├── public/                 # Публичная директория
│   ├── css/
│   ├── js/
│   └── index.php
├── database/               # SQL схема
├── storage/                # Хранилище
└── docs/                   # Документация
```

## 🧩 Создание тестов

### Пример структуры модуля

```
modules/beck-depression/
├── BeckDepressionModule.php   # Класс модуля
├── metadata.json              # Метаданные
└── questions.json             # Вопросы
```

### Базовый класс модуля

```php
<?php
namespace PsyTest\Modules\BeckDepression;

use PsyTest\Modules\BaseTestModule;

class BeckDepressionModule extends BaseTestModule
{
    public function getMetadata(): array { ... }
    public function getQuestions(): array { ... }
    public function calculateResults(array $answers): array { ... }
    public function generateInterpretation(array $scores): array { ... }
    public function renderResults(array $results): string { ... }
    public function supportsPairMode(): bool { return false; }
}
```

Подробная инструкция в [DEVELOPMENT.md](DEVELOPMENT.md#создание-нового-теста)

## 📚 Документация

| Файл | Описание |
|------|----------|
| [README.md](README.md) | Основная документация |
| [QUICKSTART.md](QUICKSTART.md) | Быстрый старт |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Разработка с ИИ |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Развёртывание |
| [VSCODE.md](docs/archive/VSCODE.md) | Настройка VS Code (архив) |

## 🔧 Доступные тесты

| Тест | Описание | Шкалы |
|------|----------|-------|
| **СМИЛ** | Адаптация MMPI (Ф. Собчик) | 10 основных + 3 доп. |
| *Больше тестов скоро* | *Добавляйте свои!* | *Ваши шкалы* |

## 🤖 AI-интерпретация

Интеграция с OpenRouter API для генерации профессиональных интерпретаций:

```env
OPENROUTER_API_KEY=your_key
OPENROUTER_MODEL=deepseek/deepseek-chat
```

## 💳 Платежи

Интеграция с ЮMoney для платных интерпретаций:

```env
YOOMONEY_SHOP_ID=your_shop_id
YOOMONEY_API_KEY=your_api_key
```

## 🛡️ Безопасность

- ✅ CSRF токены для всех POST-запросов
- ✅ XSS защита через Twig экранирование
- ✅ SQL Injection защита (PDO prepared statements)
- ✅ Криптографические токены сессий
- ✅ 152-ФЗ совместимость (удаление данных)

## 🧪 Тестирование и качество

```bash
# Unit-тесты
composer test

# Статический анализ (PHPStan level 6)
composer analyse

# Проверка стиля кода (dry-run)
composer lint

# Авто-исправление стиля
composer lint:fix

# Миграции БД
composer migrate

# Smoke-тест архитектуры (без БД)
php test-architecture.php
```

## 🤝 Вклад

1. Fork репозиторий
2. Создайте ветку (`git checkout -b feature/amazing-feature`)
3. Commit изменения (`git commit -m 'Add amazing feature'`)
4. Push в ветку (`git push origin feature/amazing-feature`)
5. Откройте Pull Request

## 📄 Лицензия

Proprietary. Все права защищены.

## 📞 Контакты

- **Email**: your-email@example.com
- **Telegram**: @your-telegram
- **Website**: https://your-website.com

## 🙏 Благодарности

- Ф.Б. Собчик за адаптацию СМИЛ
- OpenRouter за AI API
- Twig за шаблонизатор
- DomPDF за генерацию PDF

---

**Версия:** 1.0.0  
**PHP:** 8.1+  
**Лицензия:** Proprietary
