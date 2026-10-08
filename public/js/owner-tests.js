/**
 * Раздел «Методики» (07.K14).
 *
 * Скрипт только улучшает страницы. Без него:
 * - галочки ИИ-разбора сохраняются кнопкой «Сохранить» (обычный POST);
 * - строка списка открывается ссылкой с названием или кнопкой «Открыть»;
 * - панели «Опубликовать…» и «Пробный разбор» — нативные <details>.
 *
 * Со скриптом:
 * - form[data-autosave] уходит сразу при щелчке по галочке (fetch с CSRF и
 *   одноразовым ключом формы; сервер отвечает JSON с новым ключом), рядом на
 *   2 секунды появляется «Сохранено»; если сервер создал заготовки промптов —
 *   страница перерисовывается, чтобы показать их карточки;
 * - щелчок по строке списка открывает методику;
 * - панели [data-action-pop]: открыта одна, Escape и щелчок мимо закрывают;
 *   адрес с #prompt-publish сразу открывает панель публикации.
 */
(function () {
    'use strict';

    var all = function (selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    };

    // ---------- Автосохранение галочек методики ----------
    all('form[data-autosave]').forEach(function (form) {
        if (!window.fetch || !window.FormData) {
            return;
        }
        var submit = form.querySelector('[data-autosave-submit]');
        var status = form.querySelector('[data-autosave-status]');
        var keyField = form.querySelector('[data-autosave-key]');
        var csrf = form.querySelector('input[name="csrf_token"]');
        var hideTimer = null;
        var busy = false;
        var again = false;

        if (submit) {
            submit.hidden = true;
        }
        form.classList.add('test-ai-form--auto');

        var say = function (text, tone, sticky) {
            if (!status) {
                return;
            }
            clearTimeout(hideTimer);
            status.textContent = text;
            status.className = 'autosave-status' + (tone ? ' autosave-status--' + tone : '');
            if (!sticky) {
                hideTimer = setTimeout(function () {
                    status.textContent = '';
                    status.className = 'autosave-status';
                }, 2000);
            }
        };

        // Запросы идут по одному: второй щелчок ждёт ответа на первый и уходит
        // с новым одноразовым ключом.
        var send = function () {
            if (busy) {
                again = true;
                return;
            }
            busy = true;
            say('Сохраняем…', 'busy', true);
            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf ? csrf.value : '' },
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (data.form_key && keyField) {
                    keyField.value = data.form_key;
                }
                if (data.reload) {
                    window.location.assign(data.reload);
                    window.location.reload();
                    return;
                }
                if (data.type === 'error') {
                    say(data.message || 'Не сохранено. Обновите страницу и повторите.', 'error', true);
                } else {
                    say(data.message || 'Сохранено.', 'done', false);
                }
            }).catch(function () {
                say('Не сохранено: нет связи с сервером. Повторите щелчок или нажмите «Сохранить».', 'error', true);
                if (submit) {
                    submit.hidden = false;
                }
            }).then(function () {
                busy = false;
                if (again) {
                    again = false;
                    send();
                }
            });
        };

        all('input[type="checkbox"]', form).forEach(function (box) {
            // owner-forms.js синхронизирует зависимую галочку на том же событии;
            // отправка — после него, чтобы в форму попало согласованное состояние.
            box.addEventListener('change', function () {
                setTimeout(send, 0);
            });
        });
    });

    // ---------- Строка списка открывает методику ----------
    all('[data-row-links] tbody tr[data-href]').forEach(function (row) {
        row.classList.add('is-linked');
        row.addEventListener('click', function (event) {
            if (event.target.closest('a, button, input, label, summary')) {
                return;
            }
            if (window.getSelection && String(window.getSelection()) !== '') {
                return;
            }
            window.location.assign(row.getAttribute('data-href'));
        });
    });

    // ---------- Панели «Опубликовать…» / «Пробный разбор» ----------
    var pops = all('details[data-action-pop]');
    var close = function (pop, returnFocus) {
        if (!pop.open) {
            return;
        }
        pop.open = false;
        if (returnFocus) {
            var toggle = pop.querySelector('summary');
            if (toggle) {
                toggle.focus();
            }
        }
    };
    pops.forEach(function (pop) {
        pop.addEventListener('toggle', function () {
            if (!pop.open) {
                return;
            }
            pops.forEach(function (other) {
                if (other !== pop) {
                    close(other, false);
                }
            });
            var first = pop.querySelector('.action-pop__panel input, .action-pop__panel button');
            if (first) {
                first.focus();
            }
        });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            pops.forEach(function (pop) {
                close(pop, true);
            });
        }
    });
    document.addEventListener('click', function (event) {
        pops.forEach(function (pop) {
            if (pop.open && !pop.contains(event.target)) {
                close(pop, false);
            }
        });
    });
    var openFromHash = function () {
        var id = window.location.hash.slice(1);
        var target = id ? document.getElementById(id) : null;
        if (target && target.matches('details[data-action-pop]')) {
            target.open = true;
            // Панель в шапке: дерево «История версий» раскрывать не нужно.
        } else if (target && target.closest('details') && !target.closest('details').open) {
            target.closest('details').open = true;
        }
        if (id === 'prompt-history') {
            var history = document.querySelector('details[data-history]');
            if (history) {
                history.open = true;
            }
        }
    };
    openFromHash();
    window.addEventListener('hashchange', openFromHash);

    // ---------- Результат предпросмотра или пробного разбора — в поле зрения ----------
    var page = document.querySelector('[data-scroll-to]');
    if (page) {
        var output = document.getElementById(page.getAttribute('data-scroll-to'));
        if (output && !window.location.hash) {
            output.scrollIntoView({ block: 'start' });
        }
    }
}());
