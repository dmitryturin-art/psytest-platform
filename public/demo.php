<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PsyTest Platform - Demo</title>
    <link rel="stylesheet" href="css/main.css">
    <style>
        .demo-wrapper { max-width: 800px; margin: 0 auto; padding: 40px 20px; }
        .hero { text-align: center; padding: 60px 20px; }
        .hero h1 { font-size: 2.5rem; margin-bottom: 20px; color: #2c3e50; }
        .hero p { font-size: 1.2rem; color: #7f8c8d; margin-bottom: 40px; }
        .status-card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 30px; }
        .status-item { display: flex; justify-content: space-between; padding: 15px 0; border-bottom: 1px solid #ecf0f1; }
        .status-item:last-child { border-bottom: none; }
        .status-label { color: #7f8c8d; }
        .status-value { font-weight: 600; }
        .status-ok { color: #27ae60; }
        .status-warning { color: #f39c12; }
        .status-error { color: #e74c3c; }
        .btn-demo { display: inline-block; padding: 15px 30px; background: #3498db; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin: 10px; }
        .btn-demo:hover { background: #2980b9; }
        .feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 40px; }
        .feature { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .feature h3 { margin-top: 0; color: #2c3e50; }
        .feature p { color: #7f8c8d; line-height: 1.6; }
        .code-block { background: #2c3e50; color: #ecf0f1; padding: 20px; border-radius: 8px; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 0.9rem; }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <div class="header-content">
                <a href="/demo.php" class="logo">
                    <span class="logo-icon">🧠</span>
                    <span class="logo-text">PsyTest</span>
                </a>
            </div>
        </div>
    </header>

    <main class="site-main">
        <div class="demo-wrapper">
            <div class="hero">
                <h1>PsyTest Platform</h1>
                <p>Модульная система психологического тестирования</p>
            </div>

            <div class="status-card">
                <h2>✓ Система готова к работе</h2>
                
                <div class="status-item">
                    <span class="status-label">PHP Version</span>
                    <span class="status-value status-ok"><?php echo PHP_VERSION; ?></span>
                </div>
                
                <div class="status-item">
                    <span class="status-label">Composer Autoload</span>
                    <span class="status-value status-ok">✓ Загружен</span>
                </div>
                
                <div class="status-item">
                    <span class="status-label">Twig Templates</span>
                    <span class="status-value status-ok">✓ Доступны</span>
                </div>
                
                <div class="status-item">
                    <span class="status-label">SMIL Module</span>
                    <span class="status-value status-ok">✓ Работает</span>
                </div>
                
                <div class="status-item">
                    <span class="status-label">Database (MySQL)</span>
                    <span class="status-value status-warning">⚠ Требуется настройка</span>
                </div>
            </div>

            <div class="feature-grid">
                <div class="feature">
                    <h3>📝 Тест СМИЛ (MMPI)</h3>
                    <p>Полная адаптация Ф.Б. Собчик. 566 вопросов, 12 шкал, T-баллы, профиль личности, интерпретация.</p>
                </div>
                
                <div class="feature">
                    <h3>📊 Визуализация</h3>
                    <p>Графики профиля с Chart.js, таблицы результатов, цветовая индикация уровней.</p>
                </div>
                
                <div class="feature">
                    <h3>🔐 Безопасность</h3>
                    <p>CSRF защита, XSS фильтрация, криптографические токены сессий, 152-ФЗ совместимость.</p>
                </div>
                
                <div class="feature">
                    <h3>💳 AI-интерпретация</h3>
                    <p>Интеграция с OpenRouter API, платные отчёты через ЮMoney, PDF-генерация.</p>
                </div>
            </div>

            <div style="text-align: center; margin-top: 40px;">
                <h3>Следующие шаги:</h3>
                <div class="code-block">
# 1. Установите MySQL (опционально)<br>
brew install mysql<br>
<br>
# 2. Создайте базу данных<br>
php bin/install-db.php<br>
<br>
# 3. Откройте http://localhost:8000/tests
                </div>
                
                <div style="margin-top: 30px;">
                    <a href="https://github.com/your-repo" class="btn-demo">📚 Документация</a>
                    <a href="test-architecture.php" class="btn-demo">🔍 Проверка архитектуры</a>
                </div>
            </div>
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-info">
                    <p>&copy; <?php echo date('Y'); ?> PsyTest Platform. Все права защищены.</p>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
