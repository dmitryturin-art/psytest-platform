/**
 * Карточка кейса как рабочее место (04.D3).
 *
 * Скрипт только улучшает страницу. Без него полоса разделов — обычные ссылки
 * на якоря, блоки результата и анкеты раскрываются сами (<details>), «Открыть»
 * у разбора ведёт к его тексту, а заметка правится маленькой формой.
 *
 * - Полоса разделов отмечает текущий раздел (IntersectionObserver) и, когда
 *   прилипла к верху, показывает название кейса.
 * - Открытые блоки результата запоминаются для этого браузера (localStorage;
 *   без него всё работает, просто без памяти).
 * - «Открыть» у разбора раскрывает текст и прокручивает к нему.
 * - Заметка: «Изменить» открывает поле, «Отмена» возвращает прежний текст.
 */
(function () {
    'use strict';

    var all = function (selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    };

    // ---------- Полоса разделов ----------
    var nav = document.querySelector('[data-case-nav]');
    var header = document.querySelector('.site-header');
    var syncTop = function () {
        if (nav && header) {
            document.documentElement.style.setProperty('--case-nav-top', header.getBoundingClientRect().height + 'px');
        }
    };
    syncTop();
    window.addEventListener('resize', syncTop);

    if (nav && 'IntersectionObserver' in window) {
        var links = all('[data-case-nav-link]', nav);
        var byId = {};
        links.forEach(function (link) {
            byId[link.getAttribute('href').slice(1)] = link;
        });
        var visible = {};
        var mark = function () {
            // Текущий — первый по порядку раздел, видимый в рабочей зоне окна.
            var current = null;
            links.forEach(function (link) {
                var id = link.getAttribute('href').slice(1);
                if (current === null && visible[id]) {
                    current = link;
                }
            });
            if (current === null) {
                return;
            }
            links.forEach(function (link) {
                if (link === current) {
                    link.setAttribute('aria-current', 'true');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
            // На телефоне полоса прокручивается вбок: текущая вкладка остаётся видна.
            var strip = current.parentElement;
            if (strip && strip.scrollWidth > strip.clientWidth) {
                strip.scrollLeft = current.offsetLeft - 16;
            }
        };
        var sections = all('[data-case-section]').filter(function (section) {
            return byId[section.id];
        });
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                visible[entry.target.id] = entry.isIntersecting;
            });
            mark();
        }, { rootMargin: '-140px 0px -45% 0px' });
        sections.forEach(function (section) {
            observer.observe(section);
        });

        // Метка «прилипла»: невидимый ориентир прямо перед полосой.
        var sentinel = document.createElement('div');
        sentinel.setAttribute('aria-hidden', 'true');
        sentinel.className = 'case-nav__sentinel';
        nav.parentNode.insertBefore(sentinel, nav);
        new IntersectionObserver(function (entries) {
            var top = header ? header.getBoundingClientRect().height : 0;
            entries.forEach(function (entry) {
                nav.classList.toggle('is-stuck', !entry.isIntersecting && entry.boundingClientRect.top < top + 1);
            });
        }, { rootMargin: '-' + Math.round(header ? header.getBoundingClientRect().height : 0) + 'px 0px 0px 0px' }).observe(sentinel);
    }

    // ---------- Память раскрытых блоков ----------
    var storageKey = function (name) {
        return 'psytest.case.fold.' + name;
    };
    var read = function (name) {
        try {
            return window.localStorage.getItem(storageKey(name));
        } catch (e) {
            return null;
        }
    };
    var write = function (name, value) {
        try {
            window.localStorage.setItem(storageKey(name), value);
        } catch (e) {
            /* хранилище недоступно — просто без памяти */
        }
    };
    all('details[data-remember]').forEach(function (details) {
        var name = details.getAttribute('data-remember');
        if (read(name) === '1') {
            details.open = true;
        }
        details.addEventListener('toggle', function () {
            write(name, details.open ? '1' : '0');
        });
    });

    // ---------- «Открыть» у разбора ----------
    all('[data-report-open]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            var target = document.getElementById('case-report-' + link.getAttribute('data-report-open'));
            if (!target) {
                return;
            }
            event.preventDefault();
            target.open = true;
            target.scrollIntoView({ block: 'start' });
            var summary = target.querySelector('summary');
            if (summary) {
                summary.focus({ preventScroll: true });
            }
        });
    });

    // ---------- Заметка: правка на месте ----------
    var note = document.querySelector('[data-case-note]');
    var edit = note ? note.querySelector('[data-case-note-edit]') : null;
    if (edit) {
        var input = edit.querySelector('[data-case-note-input]');
        var cancel = edit.querySelector('[data-case-note-cancel]');
        var initial = input ? input.value : '';
        note.classList.add('case-note--js');
        if (cancel) {
            cancel.hidden = false;
            cancel.addEventListener('click', function () {
                if (input) {
                    input.value = initial;
                }
                edit.open = false;
                var summary = edit.querySelector('summary');
                if (summary) {
                    summary.focus();
                }
            });
        }
        edit.addEventListener('toggle', function () {
            if (edit.open && input) {
                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);
            }
        });
        edit.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && cancel) {
                cancel.click();
            }
        });
    }
}());
