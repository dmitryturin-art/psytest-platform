/**
 * Редактор промпта: markdown-режим поверх той же textarea.
 *
 * Источник правды не меняется — на сервер уходит поле `text` формы. Редактор
 * работает ТОЛЬКО в режиме markdown (переключатель в визуальный режим скрыт):
 * промпт — это текст, и визуальный режим переписал бы разметку, отступы и
 * переносы строк. Без JS (и если библиотека не загрузилась) страница работает
 * как прежде: textarea видима и редактируема.
 *
 * Предпросмотр запроса берётся с сервера по ТЕКУЩЕМУ несохранённому тексту
 * (POST …/preview): ничего не сохраняется и провайдер не вызывается.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-prompt-editor-form]');
    if (!form) return;

    var textarea = form.querySelector('[data-prompt-editor]');
    if (!textarea) return;

    var getText = function () { return textarea.value; };
    var insertAtCursor = null;
    var editor = null;
    var statsHook = function () {};

    if (window.toastui && typeof window.toastui.Editor === 'function') {
        var host = document.createElement('div');
        host.className = 'owner-markdown-editor owner-prompt-editor';
        textarea.parentNode.insertBefore(host, textarea);
        textarea.classList.add('owner-editor-textarea--enhanced');

        // Скрытое поле не получает фокус, поэтому «обязательно» проверяем сами.
        var wasRequired = textarea.required;
        textarea.required = false;

        editor = new window.toastui.Editor({
            el: host,
            initialValue: textarea.value,
            // Как в редакторе разбора: визуальный режим по умолчанию и
            // переключатель «Markdown / WYSIWYG» (решение владельца 08.10).
            initialEditType: 'wysiwyg',
            previewStyle: 'tab',
            language: 'ru-RU',
            usageStatistics: false,
            // Без автофокуса: иначе страница при открытии прыгает к редактору (07.K14).
            autofocus: false,
            height: '60vh',
            hideModeSwitch: false,
            toolbarItems: [
                ['heading', 'bold', 'italic'],
                ['hr', 'quote'],
                ['ul', 'ol'],
                ['table'],
            ],
            events: {
                change: function () {
                    textarea.value = editor.getMarkdown();
                    statsHook();
                },
            },
        });

        getText = function () { return editor.getMarkdown(); };
        insertAtCursor = function (text) {
            editor.focus();
            editor.insertText(text);
        };

        var notice = document.createElement('p');
        notice.className = 'owner-notice owner-notice--error';
        notice.setAttribute('role', 'alert');
        notice.hidden = true;
        host.parentNode.insertBefore(notice, host.nextSibling);

        form.addEventListener('submit', function (event) {
            textarea.value = editor.getMarkdown();
            notice.hidden = true;
            if (wasRequired && textarea.value.trim() === '') {
                event.preventDefault();
                notice.textContent = 'Текст промпта пуст: пустая версия не сохраняется.';
                notice.hidden = false;
                host.scrollIntoView({ block: 'nearest' });
            }
        });
    } else {
        textarea.addEventListener('input', function () { statsHook(); });
        insertAtCursor = function (text) {
            textarea.setRangeText(text, textarea.selectionStart, textarea.selectionEnd, 'end');
            textarea.focus();
            statsHook();
        };
    }

    // --- Переменные ----------------------------------------------------
    var vars = form.querySelector('[data-prompt-vars]');
    var select = form.querySelector('[data-prompt-var-select]');
    if (vars && select) {
        vars.hidden = false;
        select.addEventListener('change', function () {
            if (select.value !== '') {
                insertAtCursor('`' + select.value + '`');
                select.value = '';
            }
        });
    }

    // --- Предпросмотр и счётчики --------------------------------------
    var box = form.querySelector('[data-prompt-preview-box]');
    if (!box || !window.fetch) return;
    box.hidden = false;

    var toggle = box.querySelector('[data-prompt-preview-toggle]');
    var stats = box.querySelector('[data-prompt-stats]');
    var panel = box.querySelector('[data-prompt-preview-panel]');
    var errorBox = box.querySelector('[data-prompt-preview-error]');
    var meta = box.querySelector('[data-prompt-preview-meta]');
    var systemPre = box.querySelector('[data-prompt-preview-system]');
    var userPre = box.querySelector('[data-prompt-preview-user]');
    var csrf = form.querySelector('input[name="csrf_token"]');
    var timer = null;
    var seq = 0;
    var contextLength = null;

    // Для русского текста токен ≈ 2–3 знака; оценка грубая и так подписана.
    var approxTokens = function (chars) { return Math.ceil(chars / 2.5); };

    var renderStats = function () {
        var chars = getText().length;
        var line = 'Промпт: ' + chars + ' зн., примерно ' + approxTokens(chars) + ' токенов.';
        if (contextLength !== null) {
            line += ' Входные данные: ' + contextLength + ' зн.';
        }
        stats.textContent = line;
    };

    var load = function () {
        var mine = ++seq;
        var body = new FormData();
        body.append('text', getText());
        fetch(form.getAttribute('data-preview-url'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf ? csrf.value : '', 'Accept': 'application/json' },
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (mine !== seq) return;
            var failure = data.error || '';
            errorBox.hidden = failure === '';
            errorBox.textContent = failure;
            if (failure !== '') {
                systemPre.textContent = data.system || '';
                userPre.textContent = '';
                meta.textContent = '';
                return;
            }
            systemPre.textContent = data.system;
            userPre.textContent = data.user;
            contextLength = data.context_length;
            var parts = [];
            if (data.glossary_mode) {
                parts.push('Глоссарий СМИЛ: ' + (data.glossary_mode === 'compact' ? 'компактный' : 'полный') + '.');
            }
            parts.push('Ровно то, что уйдёт модели; ответы синтетические, провайдер не вызывается, черновик не сохраняется.');
            meta.textContent = parts.join(' ');
            renderStats();
        }).catch(function () {
            if (mine !== seq) return;
            errorBox.hidden = false;
            errorBox.textContent = 'Не удалось получить предпросмотр. Текст в редакторе не потерян.';
        });
    };

    statsHook = function () {
        renderStats();
        if (panel.hidden) return;
        clearTimeout(timer);
        timer = setTimeout(load, 600);
    };

    toggle.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        toggle.textContent = panel.hidden ? 'Предпросмотр' : 'Скрыть предпросмотр';
        if (!panel.hidden) load();
    });

    renderStats();
})();
