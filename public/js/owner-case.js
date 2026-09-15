/**
 * Карточка кейса: ожидание черновиков ИИ-разбора.
 *
 * Заказ уходит в отдельный процесс, поэтому страница возвращается мгновенно и
 * должна сама показать, что работа идёт. Опрос ходит на owner-only JSON той же
 * сессией кабинета: никаких токенов результата в запросе нет.
 */
(function () {
    'use strict';

    var POLL_INTERVAL_MS = 10000;
    // Разбор занимает минуты, но не часы: вечный опрос открытой вкладки не нужен.
    var MAX_POLLS = 120;

    var section = document.querySelector('[data-case-reports]');
    if (!section) return;

    var statusUrl = section.dataset.statusUrl;
    if (!statusUrl) return;

    var waiting = function () {
        return section.querySelectorAll(
            '.owner-case-ai-item[data-status="pending"], .owner-case-ai-item[data-status="running"]'
        ).length > 0;
    };

    if (!waiting()) return;

    var polls = 0;
    var timer = null;

    var stop = function () {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    };

    var check = function () {
        polls += 1;
        if (polls > MAX_POLLS) {
            stop();
            return;
        }

        fetch(statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data || !Array.isArray(data.kinds)) return;

                var stillWorking = data.kinds.some(function (kind) {
                    return kind.status === 'pending' || kind.status === 'running';
                });

                if (stillWorking) return;

                // Ссылки «Редактировать и публиковать», причина отказа и кнопка
                // повтора строятся сервером: разметку состояния собирает шаблон,
                // а не этот скрипт.
                stop();
                window.location.reload();
            })
            .catch(function () { /* сеть моргнула — следующий тик повторит */ });
    };

    timer = setInterval(check, POLL_INTERVAL_MS);
    check();
})();
