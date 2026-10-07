/**
 * Кнопка «Скопировать» у ссылки-приглашения (04.D3a).
 *
 * Без скрипта кнопка скрыта (атрибут hidden), а поле остаётся доступным для
 * выделения. Токен никуда не пишется: берётся значение поля и кладётся в буфер.
 */
(function () {
    'use strict';

    var buttons = document.querySelectorAll('button[data-copy-target]');
    Array.prototype.forEach.call(buttons, function (button) {
        var field = document.getElementById(button.getAttribute('data-copy-target'));
        var label = button.querySelector('[data-copy-label]');
        if (!field || !label) {
            return;
        }
        button.hidden = false;
        var icon = button.querySelector('use');
        var timer = null;

        var done = function () {
            label.textContent = 'Скопировано';
            if (icon) { icon.setAttribute('href', '#i-check'); }
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                label.textContent = 'Скопировать';
                if (icon) { icon.setAttribute('href', '#i-copy'); }
            }, 2000);
        };

        var fallback = function () {
            field.focus();
            field.select();
            try {
                if (document.execCommand('copy')) { done(); }
            } catch (e) { /* поле остаётся выделенным: скопировать можно вручную */ }
        };

        button.addEventListener('click', function () {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value).then(done, fallback);
            } else {
                fallback();
            }
        });
    });
}());
