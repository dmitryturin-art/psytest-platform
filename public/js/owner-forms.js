/**
 * Формы кабинета: защита от двойного нажатия и поле «Новый клиент…» (07.K6a).
 *
 * Скрипт только улучшает форму. Без него она работает целиком: поле имени
 * нового клиента всегда видно с подсказкой, а повторную отправку отсекает
 * сервер по одноразовому ключу формы.
 */
(function () {
    'use strict';

    // Кнопка блокируется после первой отправки: второй клик ничего не шлёт.
    var onceForms = document.querySelectorAll('form[data-submit-once]');
    Array.prototype.forEach.call(onceForms, function (form) {
        var sent = false;
        var buttons = form.querySelectorAll('button[type="submit"]');
        var labels = Array.prototype.map.call(buttons, function (button) {
            return button.textContent;
        });

        form.addEventListener('submit', function (event) {
            if (sent) {
                event.preventDefault();
                return;
            }
            sent = true;
            // Блокировка — после того как браузер собрал данные формы.
            window.setTimeout(function () {
                Array.prototype.forEach.call(buttons, function (button) {
                    button.disabled = true;
                    if (button.hasAttribute('data-busy-text')) {
                        button.textContent = button.getAttribute('data-busy-text');
                    }
                });
            }, 0);
        });

        // Возврат кнопкой «Назад» из кэша страниц: форма снова доступна.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }
            sent = false;
            Array.prototype.forEach.call(buttons, function (button, index) {
                button.disabled = false;
                button.textContent = labels[index];
            });
        });
    });

    // Поле имени показывается только при выборе «Новый клиент…».
    var selects = document.querySelectorAll('select[data-new-client-select]');
    Array.prototype.forEach.call(selects, function (select) {
        var form = select.form;
        var field = form ? form.querySelector('[data-new-client-field]') : null;
        if (!field) {
            return;
        }
        var input = field.querySelector('input');
        var hint = field.querySelector('[data-new-client-hint]');
        if (hint) {
            hint.hidden = true;
        }

        var sync = function () {
            var isNew = select.value === '__new__';
            field.hidden = !isNew;
            if (input) {
                input.required = isNew;
                if (isNew) {
                    input.focus();
                }
            }
        };
        select.addEventListener('change', sync);
        field.hidden = select.value !== '__new__';
        if (input) {
            input.required = select.value === '__new__';
        }
    });
}());
