/**
 * Версия кейса для печати (07.K5j).
 *
 * Кнопка «Печать» открывает системный диалог; сохранение в PDF или Word делает
 * сам браузер. Обработчик вешается здесь, а не атрибутом onclick в шаблоне,
 * чтобы страница оставалась без встроенных скриптов.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target instanceof Element && target.closest('[data-print]')) {
            window.print();
        }
    });
})();
