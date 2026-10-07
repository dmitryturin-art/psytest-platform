/**
 * Корзина карточек клиентов: диалог подтверждения (07.K11).
 *
 * Скрипт только улучшает страницу. Без него кнопки «В корзину» и «Удалить сейчас»
 * отправляют форму без подтверждения, и сервер показывает страницу подтверждения:
 * одиночный клик ничего не удаляет и ничего не отправляет в корзину.
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-client-trash-form]');
    var dialog = document.getElementById('owner-client-dialog');
    if (!form || !dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var title = dialog.querySelector('[data-dialog-title]');
    var subject = dialog.querySelector('[data-dialog-selected]');
    var text = dialog.querySelector('[data-dialog-text]');
    var check = dialog.querySelector('[data-dialog-check]');
    var checkInput = dialog.querySelector('[data-dialog-check-input]');
    var confirmButton = dialog.querySelector('[data-dialog-confirm]');
    var confirmedField = form.querySelector('[data-confirmed]');
    var deleteField = form.querySelector('[data-confirm-delete]');
    var pending = null;

    var plural = function (n) {
        var mod100 = n % 100;
        var mod10 = n % 10;
        if (mod100 >= 11 && mod100 <= 14) { return n + ' назначений'; }
        if (mod10 === 1) { return n + ' назначение'; }
        if (mod10 >= 2 && mod10 <= 4) { return n + ' назначения'; }
        return n + ' назначений';
    };

    Array.prototype.forEach.call(document.querySelectorAll('button[data-client-confirm]'), function (button) {
        button.addEventListener('click', function (event) {
            var kind = button.getAttribute('data-client-confirm');
            var template = dialog.querySelector('template[data-dialog-template="' + kind + '"]');
            if (!template) {
                return;
            }
            event.preventDefault();
            // Закрыть меню строки, из которого пришли.
            var menu = button.closest('details[data-row-menu]');
            if (menu) {
                menu.open = false;
            }
            pending = button;

            var count = parseInt(button.getAttribute('data-assignments') || '0', 10) || 0;
            var content = template.content;
            title.textContent = content.querySelector('[data-title]').textContent;
            var body = content.querySelector(count > 0 ? '[data-text-with]' : '[data-text-without]').textContent;
            text.textContent = body.replace('{assignments}', plural(count));
            confirmButton.textContent = content.querySelector('[data-confirm-label]').textContent;
            subject.textContent = 'Карточка: «' + (button.getAttribute('data-client-label') || '') + '».';
            var needsCheck = kind === 'purge';
            check.hidden = !needsCheck;
            checkInput.checked = false;
            confirmButton.disabled = needsCheck;
            dialog.returnValue = '';
            dialog.showModal();
        });
    });

    checkInput.addEventListener('change', function () {
        confirmButton.disabled = !checkInput.checked;
    });

    dialog.addEventListener('click', function (event) {
        // Щелчок по затемнению закрывает окно.
        if (event.target === dialog) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', function () {
        var button = pending;
        pending = null;
        if (!button || dialog.returnValue !== 'confirm') {
            return;
        }
        confirmedField.value = '1';
        deleteField.value = check.hidden ? '' : 'delete';
        // Кнопка передаёт своё formaction: адрес карточки и действие.
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(button);
        } else {
            form.submit();
        }
    });
}());
