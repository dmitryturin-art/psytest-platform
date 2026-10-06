/**
 * Кабинет специалиста: меню строки «⋯» и фильтры без кнопки «Применить» (04.D2).
 *
 * Скрипт только улучшает страницу. Без него меню — обычный <details>: открывается
 * и закрывается щелчком или Enter, а фильтры применяются кнопкой «Применить».
 */
(function () {
    'use strict';

    var menus = Array.prototype.slice.call(document.querySelectorAll('details[data-row-menu]'));

    var close = function (menu, returnFocus) {
        if (!menu.open) {
            return;
        }
        menu.open = false;
        if (returnFocus) {
            var toggle = menu.querySelector('summary');
            if (toggle) {
                toggle.focus();
            }
        }
    };

    menus.forEach(function (menu) {
        menu.addEventListener('toggle', function () {
            if (!menu.open) {
                menu.classList.remove('row-menu--up');
                return;
            }
            // Открыто только одно меню.
            menus.forEach(function (other) {
                if (other !== menu) {
                    close(other, false);
                }
            });
            // У нижнего края окна список раскрывается вверх.
            var list = menu.querySelector('.row-menu__list');
            if (list) {
                var rect = list.getBoundingClientRect();
                if (rect.bottom > window.innerHeight - 8 && rect.top - rect.height > 80) {
                    menu.classList.add('row-menu--up');
                }
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        menus.forEach(function (menu) {
            if (menu.open) {
                close(menu, true);
            }
        });
    });

    document.addEventListener('click', function (event) {
        var item = event.target.closest ? event.target.closest('.row-menu__item') : null;
        menus.forEach(function (menu) {
            // Щелчок мимо меню или по его пункту закрывает список (пункт при этом срабатывает).
            if (menu.open && (!menu.contains(event.target) || (item && menu.contains(item)))) {
                close(menu, false);
            }
        });
    });

    // Фильтры: выбор в списке сразу обновляет страницу; поиск — по Enter или при уходе из поля.
    var forms = document.querySelectorAll('form[data-auto-submit]');
    Array.prototype.forEach.call(forms, function (form) {
        form.classList.add('filter-bar--auto');
        // Пустые поля не попадают в адрес: `?status=completed`, а не `?client=&test=&…`.
        var send = function () {
            Array.prototype.forEach.call(form.elements, function (field) {
                if (field.name && field.value === '') {
                    field.disabled = true;
                }
            });
            form.submit();
        };
        Array.prototype.forEach.call(form.querySelectorAll('select'), function (select) {
            select.addEventListener('change', send);
        });
        var search = form.querySelector('input[type="search"]');
        if (search) {
            var initial = search.value;
            search.addEventListener('change', function () {
                if (search.value !== initial) {
                    send();
                }
            });
        }
    });

    // Возврат «Назад» из кэша страниц: поля снова доступны.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) {
            return;
        }
        Array.prototype.forEach.call(forms, function (form) {
            Array.prototype.forEach.call(form.elements, function (field) {
                field.disabled = false;
            });
        });
    });
}());
