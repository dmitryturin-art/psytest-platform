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

    // Кнопка «Обновить». Раньше это была ссылка на текущий адрес с якорем, а по
    // такой ссылке браузер лишь прокручивает страницу и ничего не перезапрашивает.
    var reloadButtons = document.querySelectorAll('[data-reload]');
    Array.prototype.forEach.call(reloadButtons, function (button) {
        button.addEventListener('click', function () {
            window.location.reload();
        });
    });

    /**
     * Снимок статусов: вид разбора → статус.
     *
     * @param {Array} kinds Элементы с полями kind и status.
     * @returns {Object}
     */
    var snapshot = function (kinds) {
        var map = {};
        Array.prototype.forEach.call(kinds, function (item) {
            map[item.kind] = item.status;
        });

        return map;
    };

    /**
     * Нужна ли перезагрузка страницы.
     *
     * Перезагружаемся при ЛЮБОМ изменении статуса любого вида разбора, а не
     * только когда работа закончилась целиком: понятный разбор может стать
     * готовым, пока профессиональное заключение ещё считается, и специалист
     * должен увидеть ссылку на редактор сразу, не дожидаясь второго задания.
     *
     * @param {Object} before Статусы на момент отрисовки страницы.
     * @param {Object} after Статусы, полученные опросом.
     * @returns {boolean}
     */
    var statusChanged = function (before, after) {
        var kinds = Object.keys(before).concat(Object.keys(after));

        return kinds.some(function (kind) {
            return before[kind] !== after[kind];
        });
    };

    /**
     * Есть ли ещё незавершённые задания.
     *
     * @param {Object} statuses
     * @returns {boolean}
     */
    var stillWorking = function (statuses) {
        return Object.keys(statuses).some(function (kind) {
            return statuses[kind] === 'pending' || statuses[kind] === 'running';
        });
    };

    var section = document.querySelector('[data-case-reports]');
    if (!section) return;

    var statusUrl = section.dataset.statusUrl;
    if (!statusUrl) return;

    var initial = snapshot(
        Array.prototype.map.call(
            section.querySelectorAll('.owner-case-ai-item[data-kind]'),
            function (element) {
                return { kind: element.dataset.kind, status: element.dataset.status };
            }
        )
    );

    // Опрос нужен, только пока хоть одно задание не завершено.
    if (!stillWorking(initial)) return;

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

                var current = snapshot(data.kinds);

                if (statusChanged(initial, current)) {
                    // Ссылки «Редактировать и публиковать», причина отказа и
                    // кнопка повтора строятся сервером: разметку состояния
                    // собирает шаблон, а не этот скрипт.
                    stop();
                    window.location.reload();

                    return;
                }

                // Статусы прежние: продолжаем опрос, пока есть незавершённые.
                if (!stillWorking(current)) {
                    stop();
                }
            })
            .catch(function () { /* сеть моргнула — следующий тик повторит */ });
    };

    timer = setInterval(check, POLL_INTERVAL_MS);
    check();
})();
