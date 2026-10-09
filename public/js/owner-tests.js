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

        // Строка «Ответы по пунктам» в разделе «Что получает модель» следует за галочкой.
        var syncItemsText = function () {
            var items = form.querySelector('input[name="send_item_answers"]');
            if (!items) {
                return;
            }
            var on = items.checked && !items.disabled;
            all('[data-items-text]').forEach(function (text) {
                text.hidden = (text.getAttribute('data-items-text') === 'on') !== on;
            });
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
                    syncItemsText();
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

    // ---------- Инструкция респонденту (07.K15): поле на месте текста ----------
    all('[data-instruction]').forEach(function (section) {
        var view = section.querySelector('[data-instruction-view]');
        var editor = section.querySelector('[data-instruction-editor]');
        var edit = section.querySelector('[data-instruction-edit]');
        var cancel = section.querySelector('[data-instruction-cancel]');
        if (!view || !editor || !edit) {
            return;
        }
        var open = function (isOpen) {
            view.hidden = isOpen;
            editor.hidden = !isOpen;
            if (cancel) {
                cancel.hidden = !isOpen;
            }
            if (isOpen) {
                var field = editor.querySelector('textarea');
                if (field) {
                    field.focus();
                }
            } else {
                edit.focus();
            }
        };
        if (editor.hasAttribute('data-instruction-editor-closed')) {
            editor.hidden = true;
        } else if (cancel) {
            cancel.hidden = false;
        }
        edit.addEventListener('click', function () { open(true); });
        if (cancel) {
            cancel.addEventListener('click', function () { open(false); });
        }
        if (window.location.hash === '#instruction-edit') {
            open(true);
        }
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

    // ---------- Пробный разбор: опрос состояния, пока он готовится ----------
    // Без скрипта страница просит обновить её вручную (текст в самом блоке).
    var trialBlock = document.querySelector('[data-trial-poll]');
    if (trialBlock && window.fetch) {
        var statusUrl = trialBlock.getAttribute('data-trial-poll');
        var attempts = 0;
        var maxAttempts = 60; // 5 минут по 5 секунд
        var timer = window.setInterval(function () {
            attempts += 1;
            if (attempts > maxAttempts) {
                window.clearInterval(timer);
                var waiting = trialBlock.querySelector('[data-trial-waiting]');
                if (waiting) {
                    waiting.textContent = 'Разбор готовится дольше обычного. Обновите страницу через минуту.';
                }
                return;
            }
            fetch(statusUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) {
                    if (!data || data.status === 'pending') {
                        return null;
                    }
                    window.clearInterval(timer);
                    return fetch(window.location.pathname + window.location.search, { credentials: 'same-origin' })
                        .then(function (response) { return response.text(); })
                        .then(function (html) {
                            var doc = new DOMParser().parseFromString(html, 'text/html');
                            var fresh = doc.getElementById('trial-result');
                            if (fresh) {
                                trialBlock.replaceWith(fresh);
                                fresh.scrollIntoView({ block: 'start' });
                            } else {
                                window.location.reload();
                            }
                        });
                })
                .catch(function () { /* следующая попытка через 5 секунд */ });
        }, 5000);
    }
}());
