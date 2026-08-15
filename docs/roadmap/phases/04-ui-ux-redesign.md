# Этап 04 — UI/UX и дизайн-система

Статус: **Не начат**. Research/prototype можно вести параллельно после этапа 01; реализация опирается на стабильный Module API.

## Цель

Сделать интерфейс современным, спокойным, профессиональным и удобным на телефоне, не превращая клинический продукт в развлекательную викторину.

## Контрольная точка владельца

До массовой CSS/шаблонной работы показать 2–3 направления на ключевых экранах: лендинг, каталог, прохождение, простой результат, SMIL result, checkout. Владелец выбирает направление и отдельно подтверждает, что SMIL-профиль не искажён.

## Work packages

1. Карта пользовательских путей и content hierarchy; убрать длинные вступления из критического пути, оставив раскрываемые подробности.
2. Публичный лендинг: ценностное предложение, каталог, прозрачная граница «бесплатный результат / дополнительный разбор», демонстрационные обезличенные примеры отчёта, FAQ и скромный footer-link на 23time.ru.
3. Спецификация необязательного аккаунта: пути «без регистрации» и «сохранить в аккаунт», dashboard/history wireframes, consent/deletion states. Реализация аккаунта не входит в этот этап.
4. Tokens: typography, spacing, color, elevation, radius, focus, states; WCAG contrast и reduced motion.
5. App shell/navigation без пустой sticky области; ясная позиция в процессе и возвращение к результату.
6. Questionnaire components: прогресс 1…N без off-by-one, touch targets, keyboard, autosave/recovery и validation message.
7. Result components: бесплатный итог первый, платный CTA только у capabilities, без манипулятивного текста.
8. Lazarus individual/pair UX: различимые респонденты, доступные legend/labels, mobile графики/таблицы.
9. SMIL protected polish: окружение, типографика, раскрытие шкал, print/PDF; геометрия и разрыв графика неизменны.
10. Разделить глобальный CSS и route-specific assets; Chart.js загружать только где нужен.
11. SEO policy: приватные/результатные страницы `noindex`; публичные лендинг/каталог индексируются только после content, privacy и legal review.

## Проверка

- Browser matrix минимум 390×844 и desktop; keyboard-only и screen-reader semantics.
- Visual snapshots для catalog/question/result/SMIL/print; golden snapshot SMIL до и после.
- Performance comparison: CSS/JS payload и Core Web Vitals на публичных страницах.
- User walkthrough владельца без подсказок агента.

## Exit criteria

Выбранная дизайн-система применена к ключевым flows, `UX-01..03` закрыты, критические сценарии доступны на mobile, SMIL chart прошёл visual regression и одобрение владельца.

## Покрытие аудита

`UX-01`, `UX-02`, `UX-03`, глобальная загрузка Chart.js, CSS-дубли, SEO/noindex и usability-наблюдения.
