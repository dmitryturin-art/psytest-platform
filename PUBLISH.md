# 📤 Инструкция по публикации на GitHub

## Быстрая публикация

### 1. Создайте репозиторий на GitHub

1. Откройте https://github.com/new
2. Заполните поля:
   - **Repository name**: `psytest-platform`
   - **Description**: "Модульная система психологического тестирования на PHP+MySQL"
   - **Visibility**: Public ✅
   - **Initialize**: ❌ НЕ ставьте галочки (README, .gitignore и т.д.)
3. Нажмите **Create repository**

### 2. Отправьте код в репозиторий

После создания репозитория выполните в терминале:

```bash
cd /Users/dmitrijturin/VibeCoding/hyptest

# Добавьте ваш remote (замените YOUR_USERNAME на логин GitHub)
git remote add origin https://github.com/YOUR_USERNAME/psytest-platform.git

# Проверьте
git remote -v

# Отправьте код
git push -u origin main
```

### 3. Проверьте публикацию

Откройте ваш репозиторий:
```
https://github.com/YOUR_USERNAME/psytest-platform
```

Вы должны увидеть все файлы проекта.

---

## 🔧 Настройка Git (если нужно)

### Настройте имя и email

```bash
git config --global user.name "Ваше Имя"
git config --global user.email "your-email@example.com"
```

### Проверьте настройки

```bash
git config --global --list
```

---

## 📝 Следующие шаги

### 1. Добавьте тему проекта

В файле `README.md` уже есть badge, но можно добавить больше:

```markdown
[![Stars](https://img.shields.io/github/stars/YOUR_USERNAME/psytest-platform)](https://github.com/YOUR_USERNAME/psytest-platform)
[![Forks](https://img.shields.io/github/forks/YOUR_USERNAME/psytest-platform)](https://github.com/YOUR_USERNAME/psytest-platform)
```

### 2. Создайте GitHub Pages (опционально)

Для демонстрации:

1. Settings → Pages
2. Source: Deploy from branch
3. Branch: main, folder: /public
4. Save

### 3. Добавьте Issues шаблоны

Создайте папку `.github/ISSUE_TEMPLATE/` с шаблонами для:
- Bug Report
- Feature Request
- New Test Module

### 4. Настройте GitHub Actions (CI/CD)

Создайте `.github/workflows/php.yml`:

```yaml
name: PHP Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install
      - run: php test-architecture.php
```

---

## 🆘 Решение проблем

### Ошибка: "remote origin already exists"

```bash
git remote remove origin
git remote add origin https://github.com/YOUR_USERNAME/psytest-platform.git
```

### Ошибка: "Authentication failed"

1. Создайте Personal Access Token:
   - GitHub → Settings → Developer settings → Personal access tokens
   - Generate new token (classic)
   - Выберите scope: repo
   - Скопируйте токен

2. Используйте токен вместо пароля:
   ```bash
   git push https://YOUR_USERNAME:YOUR_TOKEN@github.com/YOUR_USERNAME/psytest-platform.git
   ```

### Ошибка: "Updates were rejected"

```bash
git pull origin main --rebase
git push -u origin main
```

---

## 📊 Статистика репозитория

После публикации можно добавить в README:

```bash
# Размер репозитория
git count-objects -vH

# Количество коммитов
git rev-list --count HEAD

# Количество файлов
git ls-files | wc -l
```

---

## 🎯 Чек-лист публикации

- [ ] Создан аккаунт на GitHub
- [ ] Создан репозиторий `psytest-platform`
- [ ] Настроен Git remote
- [ ] Код отправлен (`git push`)
- [ ] README отображается на GitHub
- [ ] Все файлы на месте
- [ ] Лицензия добавлена
- [ ] Документация доступна

---

**Готово!** 🎉

Ваш проект теперь на GitHub!
