<?php

/**
 * Home Controller
 */

declare(strict_types=1);

namespace PsyTest\Controllers;

class HomeController extends BaseController
{
    /**
     * Public landing page
     */
    public function index(): void
    {
        echo $this->view->render('home', [
            'tests' => $this->moduleLoader->getActiveModules(),
        ]);
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
            'title' => 'Как сейчас обрабатываются данные',
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
                    <h3>Результат больше не доступен</h3>
                    <p>Ответы, рассчитанный результат и необязательные данные профиля очищены из тестовой сессии.</p>
                    <p>Технические записи и созданные файлы обрабатываются отдельным lifecycle-процессом.</p>
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
                <h2>1. Что описывает эта страница</h2>
                <p>Здесь описано текущее техническое поведение платформы. До публичного запуска
                юридический текст и инфраструктурные настройки проходят отдельную проверку.</p>
                
                <h2>2. Сбор информации</h2>
                <p>Для работы бесплатного результата платформа обрабатывает:</p>
                <ul>
                    <li>ответы и рассчитанные результаты тестирования;</li>
                    <li>имя и адрес электронной почты, только если они введены в форме.</li>
                </ul>
                
                <h2>3. Доступ и использование</h2>
                <p>Уникальная ссылка на результат действует как ключ доступа: не пересылайте её тем,
                кому не хотите открывать результат.</p>
                <ul>
                    <li>ответы используются для расчёта бесплатного результата и PDF;</li>
                    <li>технические записи событий создаются без IP-адреса и сведений о браузере.</li>
                </ul>
                
                <h2>4. Удаление и срок хранения</h2>
                <p>Кнопка «Удалить данные» отключает ссылку на результат и очищает ответы,
                рассчитанный результат и необязательные данные профиля из тестовой сессии.
                Технические записи и созданные файлы обрабатываются отдельным lifecycle-процессом.</p>
                <p>Для анонимных сессий настроена 180-дневная lifecycle-policy; её регулярное
                выполнение в production подтверждается отдельно перед публичным запуском.</p>
                
                <h2>5. Внешние сервисы</h2>
                <p>Сейчас расширенная AI-интерпретация и оплата отключены. Когда они будут введены,
                сведения о получателях данных и отдельное согласие появятся до оформления заказа.</p>
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
