/**
 * Редактор разбора: визуальный режим поверх той же textarea.
 *
 * Источник правды не меняется — на сервер уходит поле `content` формы, как и
 * раньше. Toast UI Editor лишь редактирует его содержимое: без JS (и если
 * библиотека не загрузилась) страница работает ровно как прежде, textarea
 * остаётся видимой и редактируемой.
 *
 * Тулбар намеренно урезан до разметки, которую умеет рендерить
 * `ReportMarkdown`: заголовки, жирный, курсив, цитата, черта, списки, таблица.
 * Ссылок, картинок и блоков кода в отчёте нет, поэтому и кнопок для них нет.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-markdown-editor-form]');
    if (!form) return;

    var textarea = form.querySelector('[data-markdown-editor]');
    if (!textarea) return;

    // Библиотека лежит локально, но если файл не доехал — остаётся textarea.
    if (!window.toastui || typeof window.toastui.Editor !== 'function') return;

    var host = document.createElement('div');
    host.className = 'owner-markdown-editor';
    textarea.parentNode.insertBefore(host, textarea);
    textarea.classList.add('owner-editor-textarea--enhanced');

    // Скрытое поле не может получить фокус, поэтому встроенную проверку
    // «обязательно» снимаем и делаем её сами при отправке.
    var wasRequired = textarea.required;
    textarea.required = false;

    var limit = textarea.maxLength > 0 ? textarea.maxLength : 0;

    var notice = document.createElement('p');
    notice.className = 'owner-notice owner-notice--error';
    notice.setAttribute('role', 'alert');
    notice.hidden = true;

    var editor = new window.toastui.Editor({
        el: host,
        initialValue: textarea.value,
        initialEditType: 'wysiwyg',
        previewStyle: 'tab',
        language: 'ru-RU',
        // Никакой телеметрии наружу: кабинет не ходит во внешние сервисы.
        usageStatistics: false,
        height: '70vh',
        hideModeSwitch: false,
        toolbarItems: [
            ['heading', 'bold', 'italic'],
            ['hr', 'quote'],
            ['ul', 'ol'],
            ['table'],
        ],
        events: {
            // Держим textarea в актуальном состоянии постоянно, а не только при
            // отправке: так проверка длины и любой внешний код видят настоящий текст.
            change: function () {
                textarea.value = editor.getMarkdown();
            },
        },
    });

    host.parentNode.insertBefore(notice, host.nextSibling);

    var fail = function (message) {
        notice.textContent = message;
        notice.hidden = false;
        host.scrollIntoView({ block: 'nearest' });
    };

    form.addEventListener('submit', function (event) {
        textarea.value = editor.getMarkdown();
        notice.hidden = true;

        if (wasRequired && textarea.value.trim() === '') {
            event.preventDefault();
            fail('Текст разбора пуст: пустая версия не сохраняется.');

            return;
        }

        if (limit > 0 && textarea.value.length > limit) {
            event.preventDefault();
            fail('Текст длиннее допустимых ' + limit + ' символов — сократите его.');
        }
    });
})();
