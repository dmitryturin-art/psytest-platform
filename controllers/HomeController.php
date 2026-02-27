<?php
/**
 * Home Controller
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

class HomeController extends BaseController
{
    /**
     * Home page - redirect to tests list
     */
    public function index(): void
    {
        header('Location: /tests');
        exit;
    }
    
    /**
     * List all available tests
     */
    public function tests(): void
    {
        $tests = $this->moduleLoader->getActiveModules();
        
        echo $this->view->render('tests-list', [
            'tests' => $tests,
        ]);
    }
    
    /**
     * Privacy policy page
     */
    public function privacy(): void
    {
        echo $this->view->render('static-page', [
            'title' => 'Политика конфиденциальности',
            'content' => $this->getPrivacyContent(),
        ]);
    }
    
    /**
     * Terms of service page
     */
    public function terms(): void
    {
        echo $this->view->render('static-page', [
            'title' => 'Условия использования',
            'content' => $this->getTermsContent(),
        ]);
    }
    
    /**
     * Deleted session page
     */
    public function deleted(): void
    {
        echo $this->view->render('static-page', [
            'title' => 'Данные удалены',
            'content' => '
                <div class="empty-state">
                    <div class="empty-icon">✓</div>
                    <h3>Ваши данные успешно удалены</h3>
                    <p>Все результаты тестирования были безвозвратно удалены из нашей системы.</p>
                    <a href="/tests" class="btn btn-primary">Пройти тесты</a>
                </div>
            ',
        ]);
    }
    
    /**
     * Error page
     */
    public function error(int $code = 404): void
    {
        http_response_code($code);
        echo $this->view->render('error-page', [
            'errorCode' => $code,
        ]);
    }
    
    /**
     * Privacy policy content
     */
    private function getPrivacyContent(): string
    {
        return '
            <div class="static-content">
                <h2>1. Общие положения</h2>
                <p>Настоящая политика конфиденциальности описывает, как мы собираем, используем 
                и защищаем вашу личную информацию при использовании нашего сервиса.</p>
                
                <h2>2. Сбор информации</h2>
                <p>Мы собираем только ту информацию, которую вы предоставляете добровольно:</p>
                <ul>
                    <li>Результаты психологического тестирования</li>
                    <li>Адрес электронной почты (опционально)</li>
                    <li>Технические данные (IP-адрес, user agent) для обеспечения безопасности</li>
                </ul>
                
                <h2>3. Использование информации</h2>
                <p>Собранная информация используется исключительно для:</p>
                <ul>
                    <li>Предоставления результатов тестирования</li>
                    <li>Обеспечения безопасности сервиса</li>
                    <li>Генерации отчётов и интерпретаций</li>
                </ul>
                
                <h2>4. Хранение и защита</h2>
                <p>Все данные хранятся в зашифрованном виде на защищённых серверах. 
                Результаты тестирования доступны только по уникальной ссылке.</p>
                
                <h2>5. Удаление данных</h2>
                <p>Вы можете запросить удаление всех ваших данных в любой момент, 
                используя ссылку «Удалить мои данные» на странице результатов.</p>
                
                <h2>6. Третьи лица</h2>
                <p>Мы не передаём ваши персональные данные третьим лицам, за исключением 
                случаев, предусмотренных законодательством РФ.</p>
            </div>
        ';
    }
    
    /**
     * Terms of service content
     */
    private function getTermsContent(): string
    {
        return '
            <div class="static-content">
                <h2>1. Принятие условий</h2>
                <p>Используя данный сервис, вы соглашаетесь с настоящими условиями использования.</p>
                
                <h2>2. Описание сервиса</h2>
                <p>Сервис предоставляет возможность прохождения психологических тестов онлайн. 
                Результаты носят ознакомительный характер и не являются диагнозом.</p>
                
                <h2>3. Ограничения</h2>
                <p>Сервис не предназначен для:</p>
                <ul>
                    <li>Постановки медицинских диагнозов</li>
                    <li>Замены профессиональной консультации</li>
                    <li>Использования в судебных или юридических целях</li>
                </ul>
                
                <h2>4. Интеллектуальная собственность</h2>
                <p>Все тестовые методики и материалы защищены авторским правом.</p>
                
                <h2>5. Ответственность</h2>
                <p>Администрация сервиса не несёт ответственности за возможные последствия 
                использования результатов тестирования.</p>
                
                <h2>6. Изменение условий</h2>
                <p>Администрация оставляет за собой право изменять условия использования 
                в любое время без предварительного уведомления.</p>
            </div>
        ';
    }
}
