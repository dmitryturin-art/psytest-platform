/**
 * Список приглашений: выбор строк, панель «Выбрано N» и диалог подтверждения (07.K8).
 *
 * Скрипт только улучшает страницу. Без него: панель действий всегда видна,
 * галочки отправляются вместе с кнопкой, а «В корзину» и «Удалить сейчас»
 * ведут на страницу подтверждения — одиночный клик ничего не удаляет.
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-bulk-form]');
    if (!form) {
        return;
    }

    var all = function (selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    };

    var rows = function () {
        return all('input[data-bulk-row]:not(:disabled)');
    };

    var bar = document.querySelector('[data-bulk-bar]');
    var counter = document.querySelector('[data-bulk-count]');
    var master = document.querySelector('input[data-bulk-all]');
    var confirmedField = form.querySelector('[data-confirmed]');
    var deleteField = form.querySelector('[data-confirm-delete]');

    // Выбор строк: панель появляется только когда что-то отмечено.
    var sync = function () {
        var boxes = rows();
        var checked = boxes.filter(function (box) { return box.checked; }).length;
        if (counter) {
            counter.textContent = String(checked);
        }
        if (bar) {
            bar.hidden = checked === 0;
        }
        if (master) {
            master.checked = boxes.length > 0 && checked === boxes.length;
            master.indeterminate = checked > 0 && checked < boxes.length;
        }
    };

    rows().forEach(function (box) {
        box.addEventListener('change', sync);
    });
    if (master) {
        master.addEventListener('change', function () {
            rows().forEach(function (box) { box.checked = master.checked; });
            sync();
        });
    }
    sync();

    // Диалог подтверждения: один <dialog>, тексты из <template data-dialog-template>.
    var dialog = document.getElementById('owner-invite-dialog');
    var supportsDialog = dialog && typeof dialog.showModal === 'function';
    if (!supportsDialog) {
        // Без <dialog> остаётся страница подтверждения на сервере.
        return;
    }

    var pending = null;
    var title = dialog.querySelector('[data-dialog-title]');
    var selected = dialog.querySelector('[data-dialog-selected]');
    var text = dialog.querySelector('[data-dialog-text]');
    var check = dialog.querySelector('[data-dialog-check]');
    var checkInput = dialog.querySelector('[data-dialog-check-input]');
    var confirmButton = dialog.querySelector('[data-dialog-confirm]');

    var selectionSize = function (button) {
        // Кнопка строки действует на одну строку, кнопка панели — на отмеченные.
        if (button.name === 'invite_id') {
            return 1;
        }
        return rows().filter(function (box) { return box.checked; }).length;
    };

    var plural = function (n) {
        var mod100 = n % 100;
        var mod10 = n % 10;
        if (mod100 >= 11 && mod100 <= 14) { return n + ' приглашений'; }
        if (mod10 === 1) { return n + ' приглашение'; }
        if (mod10 >= 2 && mod10 <= 4) { return n + ' приглашения'; }
        return n + ' приглашений';
    };

    all('button[data-confirm]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            var kind = button.getAttribute('data-confirm');
            var template = dialog.querySelector('template[data-dialog-template="' + kind + '"]');
            if (!template) {
                return;
            }
            var count = selectionSize(button);
            if (count === 0) {
                // Панель без выбранных строк: серверное сообщение «Ничего не выбрано».
                return;
            }
            event.preventDefault();
            pending = button;

            var content = template.content;
            title.textContent = content.querySelector('[data-title]').textContent;
            text.textContent = content.querySelector('[data-text]').textContent;
            confirmButton.textContent = content.querySelector('[data-confirm-label]').textContent;
            selected.textContent = 'Выбрано: ' + plural(count) + '.';
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

    dialog.addEventListener('close', function () {
        var button = pending;
        pending = null;
        if (!button || dialog.returnValue !== 'confirm') {
            return;
        }
        confirmedField.value = '1';
        deleteField.value = check.hidden ? '' : 'delete';
        // Кнопка передаёт своё formaction и, для строки, свой invite_id.
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(button);
        } else {
            form.submit();
        }
    });
}());
