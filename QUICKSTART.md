# 🚀 Быстрый старт для локальной разработки

## Шаг 1: Установка PHP и Composer

### macOS (через Homebrew)
```bash
# Установить Homebrew (если нет)
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Установить PHP 8.2
brew install php@8.2

# Установить Composer
brew install composer

# Проверить установку
php -v
composer --version
```

### Windows
1. Скачать PHP: https://windows.php.net/download/
2. Скачать Composer: https://getcomposer.org/download/

### Linux (Ubuntu/Debian)
```bash
sudo apt update
sudo apt install php8.2 php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

## Шаг 2: Установка зависимостей проекта

```bash
cd /Users/dmitrijturin/VibeCoding/hyptest
composer install
```

---

## Шаг 3: Настройка базы данных

### Вариант A: MySQL/MariaDB (рекомендуется)

1. Установите MySQL:
```bash
# macOS
brew install mysql
brew services start mysql

# Или используйте Docker (см. ниже)
```

2. Создайте конфиг .env:
```bash
cp .env.example .env
```

3. Отредактируйте .env:
```
DB_HOST=localhost
DB_NAME=psytest
DB_USER=root
DB_PASS=
```

4. Запустите установку БД:
```bash
php bin/install-db.php
```

### Вариант B: Docker (самый простой)

```bash
# Запустить MySQL в Docker
docker run --name psytest-mysql -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=psytest -p 3306:3306 -d mysql:8

# Обновите .env:
# DB_HOST=127.0.0.1
# DB_USER=root
# DB_PASS=secret
```

---

## Шаг 4: Запуск встроенного PHP сервера

```bash
# Из корня проекта
php -S localhost:8000 -t public
```

Откройте в браузере: http://localhost:8000/tests

---

## Шаг 5: Проверка работы

1. Перейдите на http://localhost:8000/tests
2. Выберите тест СМИЛ
3. Пройдите тестирование (можно ответить на несколько вопросов)
4. Проверьте страницу результатов

---

## 🔧 Решение проблем

### Ошибка "PDO MySQL driver not found"
```bash
# macOS
brew install php@8.2
brew services restart php

# Linux
sudo apt install php-mysql
```

### Ошибка базы данных
Убедитесь, что MySQL запущен:
```bash
# macOS
brew services list

# Запустить MySQL
brew services start mysql
```

### Ошибка "Class not found"
```bash
composer dump-autoload
```

---

## 📝 Тестовые данные

Для быстрого тестирования можно добавить тестовую сессию:

```sql
-- После установки БД можно проверить:
USE psytest;
SELECT * FROM tests;
```

---

## 🎯 Минимальная конфигурация для разработки

Файл `.env`:
```env
APP_NAME=PsyTest
APP_URL=http://localhost:8000
APP_ENV=development
APP_DEBUG=true

DB_HOST=localhost
DB_NAME=psytest
DB_USER=root
DB_PASS=

SESSION_TTL_DAYS=30
CSRF_ENABLED=true
ENCRYPTION_KEY=test-key-change-in-production-12345678

# Опционально - для AI интерпретации
OPENROUTER_API_KEY=
OPENROUTER_MODEL=deepseek/deepseek-chat

# Опционально - для платежей
YOOMONEY_SHOP_ID=
YOOMONEY_API_KEY=
```

---

## 🐛 Отладка

Включите подробные ошибки в `.env`:
```
APP_DEBUG=true
```

Логи находятся в:
```
storage/logs/app.log
storage/logs/cleanup.log
```

---

## 📞 Если что-то не работает

1. Проверьте версию PHP: `php -v` (нужна 8.1+)
2. Проверьте расширения: `php -m | grep -i pdo`
3. Посмотрите логи PHP: `storage/logs/`
4. Пересоздайте autoload: `composer dump-autoload`
