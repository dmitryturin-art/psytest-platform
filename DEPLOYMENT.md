# PsyTest Platform - Deployment Guide

## 📦 Production Deployment Checklist

### Pre-deployment

- [ ] Установить `APP_ENV=production` в `.env`
- [ ] Установить `APP_DEBUG=false` в `.env`
- [ ] Сгенерировать `ENCRYPTION_KEY` (32 случайных символа)
- [ ] Настроить HTTPS на сервере
- [ ] Проверить права доступа к `storage/`

### База данных

- [ ] Создать БД и пользователя
- [ ] Запустить `php bin/install-db.php`
- [ ] Настроить регулярные бэкапы

### Cron задачи

```bash
# Очистка старых сессий (ежедневно в 3:00)
0 3 * * * php /path/to/hyptest/bin/cleanup-sessions.php >> /path/to/hyptest/storage/logs/cron.log 2>&1
```

### Веб-сервер

**Apache:**
- [ ] Включить `mod_rewrite`
- [ ] Настроить `DocumentRoot` на `public/`
- [ ] Проверить `.htaccess`

**Nginx:**
```nginx
server {
    listen 443 ssl http2;
    server_name psytest.local;
    root /path/to/hyptest/public;
    
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    location ~ /\. {
        deny all;
    }
    
    # Hide PHP version
    fastcgi_hide_header X-Powered-By;
}
```

### PHP настройки

```ini
; php.ini или pool config
expose_php = Off
display_errors = Off
log_errors = On
error_log = /path/to/hyptest/storage/logs/php_errors.log

memory_limit = 256M
upload_max_filesize = 10M
post_max_size = 10M
max_execution_time = 300
```

### Безопасность

- [ ] Закрыть доступ к `.env`, `.git`, `storage/`
- [ ] Настроить firewall
- [ ] Включить HTTP Strict Transport Security (HSTS)
- [ ] Настроить Content Security Policy (CSP)

### Мониторинг

- [ ] Настроить логирование
- [ ] Настроить уведомления об ошибках
- [ ] Мониторить место на диске

---

## 🔧 Troubleshooting

### Ошибка "Database connection failed"

Проверьте:
1. Доступность MySQL сервера
2. Правильность учётных данных в `.env`
3. Права пользователя БД

### Ошибка "CSRF token mismatch"

1. Проверьте, что сессии работают
2. Убедитесь, что `session_start()` вызывается
3. Проверьте настройки cookie в браузере

### PDF не генерируется

1. Проверьте права на `storage/pdfs/`
2. Убедитесь, что dompdf установлен: `composer install`
3. Проверьте `memory_limit` в PHP

### AI-интерпретация не работает

1. Проверьте API ключ OpenRouter
2. Проверьте лимиты API
3. Посмотрите логи: `storage/logs/app.log`

---

## 📊 Performance Optimization

### Кэширование

```bash
# Включить OPcache
php -v | grep -i opcache
```

### База данных

```sql
-- Добавить индексы
CREATE INDEX idx_sessions_expires ON test_sessions(expires_at);
CREATE INDEX idx_interpretations_status ON ai_interpretations(payment_status);
```

### CDN для статики

Настройте CDN для:
- `/css/main.css`
- `/js/*.js`
- Chart.js (уже через CDN)

---

## 🔒 Security Hardening

### Заголовки безопасности

```apache
# .htaccess
Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; font-src 'self' data:;"
Header set Referrer-Policy "strict-origin-when-cross-origin"
Header set Permissions-Policy "geolocation=(), microphone=(), camera=()"
```

### Защита от ботов

```apache
# Блокировка подозрительных user-agent
SetEnvIfNoCase User-Agent "^$" bad_bot
SetEnvIfNoCase User-Agent "curl" bad_bot
SetEnvIfNoCase User-Agent "wget" bad_bot
Order Allow,Deny
Allow from all
Deny from env=bad_bot
```

### Rate limiting

Реализуйте rate limiting на уровне веб-сервера или используйте Cloudflare.

---

## 📈 Scaling

### Горизонтальное масштабирование

1. Выделить сессионное хранилище (Redis)
2. Настроить репликацию БД
3. Использовать load balancer

### Оптимизация для высокой нагрузки

```php
// В config.php
'db' => [
    'persistent' => true, // Постоянные соединения
    'pool_size' => 10,
],
```

---

## 🆘 Support

При возникновении проблем:

1. Проверьте логи: `storage/logs/`
2. Включите debug режим (только для разработки)
3. Проверьте требования: PHP 8.1+, MySQL 5.7+
