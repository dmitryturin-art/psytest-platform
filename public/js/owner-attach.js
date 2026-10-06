/**
 * Диалог «Привязать к клиенту / Сменить клиента» (07.K9).
 *
 * Скрипт только улучшает страницу. Без него кнопки [data-attach] отправляют форму
 * на сервер и открывают страницу выбора клиента: привязка работает целиком.
 * С ним кнопка открывает один общий <dialog>, куда подставляются приглашение,
 * текущий клиент и подтверждение смены.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('owner-attach-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    var title = dialog.querySelector('[data-attach-title]');
    var subject = dialog.querySelector('[data-attach-subject]');
    var inviteField = dialog.querySelector('[data-attach-invite]');
    var select = dialog.querySelector('select[name="client_id"]');
    var newField = dialog.querySelector('[data-new-client-field]');
    var newInput = newField ? newField.querySelector('input') : null;
    var confirmBox = dialog.querySelector('[data-attach-confirm]');
    var confirmInput = dialog.querySelector('[data-attach-confirm-input]');
    var confirmFrom = dialog.querySelector('[data-attach-from]');
    var submit = dialog.querySelector('[data-attach-submit]');
    var cancel = dialog.querySelector('[data-attach-cancel]');

    var open = function (button) {
        var inviteId = button.value || '';
        var form0 = button.form;
        if (!inviteId && form0 && form0.elements.invite_id) {
            inviteId = form0.elements.invite_id.value;
        }
        var currentId = button.getAttribute('data-client-id') || '';
        var currentLabel = button.getAttribute('data-client-label') || '';
        var testName = button.getAttribute('data-test-name') || '';
        var changing = currentId !== '';

        inviteField.value = inviteId;
        title.textContent = changing ? 'Сменить клиента' : 'Привязать к клиенту';
        subject.textContent = testName
            ? (changing ? 'Сейчас: «' + currentLabel + '». Методика: ' + testName + '.' : 'Методика: ' + testName + '.')
            : '';
        submit.textContent = changing ? 'Сменить' : 'Привязать';

        select.value = '';
        Array.prototype.forEach.call(select.options, function (option) {
            var isCurrent = changing && option.value === currentId;
            option.disabled = option.value === '' || isCurrent;
            option.textContent = option.textContent.replace(/ \(сейчас\)$/, '') + (isCurrent ? ' (сейчас)' : '');
        });
        if (newField) {
            newField.hidden = true;
        }
        if (newInput) {
            newInput.value = '';
            newInput.required = false;
        }
        confirmBox.hidden = !changing;
        confirmInput.checked = false;
        confirmInput.required = changing;
        confirmFrom.textContent = currentLabel;
        dialog.showModal();
        select.focus();
    };

    Array.prototype.forEach.call(document.querySelectorAll('[data-attach]'), function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            // Закрыть меню строки, из которого пришли.
            var menu = button.closest('details[data-row-menu]');
            if (menu) {
                menu.open = false;
            }
            open(button);
        });
    });

    cancel.addEventListener('click', function () {
        dialog.close();
    });
    dialog.addEventListener('click', function (event) {
        // Щелчок по затемнению закрывает окно.
        if (event.target === dialog) {
            dialog.close();
        }
    });
}());
