# IPIP-NEO-120: перевод PsyTest на проверку владельцу

Статус: **черновик перевода PsyTest (08.10.2026), не валидирован на русской выборке.** Пакет 09.O1.
Оригинал — public domain: <https://ipip.ori.org/30FacetNEO-PI-RItems.htm> (пункты и ключи, открыто 08.10.2026);
порядок и номера пунктов — Johnson, J. A. (2014), *Journal of Research in Personality*, 51, 78–89, табл. 1.
Источник данных в коде — `modules/ipip-neo-120/questions.json`; таблица ниже собрана из него и совпадает с ним.

## Как читать

- «Ключ»: `+` — согласие повышает балл грани, `−` — обратный пункт (балл = 6 − ответ).
- «Обратный перевод» — перевод русского текста назад на английский для сверки смысла; его делал тот же переводчик
  (не независимый третий человек), поэтому он проверяет только явные смысловые сдвиги.
- Формулировки по возможности не зависят от пола: настоящее время глагола вместо «готов(а)», «занят(а)», «привязан(а)».
- Варианты ответа: 1 «Совершенно неверно», 2 «Скорее неверно», 3 «Ни верно, ни неверно», 4 «Скорее верно», 5 «Совершенно верно»
  (оригинал: Very Inaccurate … Very Accurate — насколько утверждение верно описывает человека).

## Названия доменов и граней

| Код | Оригинал (Johnson, 2014) | Русское название |
|---|---|---|
| N | Neuroticism | **Нейротизм** |
| N1 | Anxiety | Тревожность |
| N2 | Anger | Гневливость |
| N3 | Depression | Депрессивность |
| N4 | Self-Consciousness | Застенчивость |
| N5 | Immoderation | Неумеренность |
| N6 | Vulnerability | Уязвимость |
| E | Extraversion | **Экстраверсия** |
| E1 | Friendliness | Дружелюбие |
| E2 | Gregariousness | Общительность |
| E3 | Assertiveness | Напористость |
| E4 | Activity Level | Активность |
| E5 | Excitement-Seeking | Поиск впечатлений |
| E6 | Cheerfulness | Жизнерадостность |
| O | Openness to Experience | **Открытость опыту** |
| O1 | Imagination | Воображение |
| O2 | Artistic Interests | Интерес к искусству |
| O3 | Emotionality | Эмоциональность |
| O4 | Adventurousness | Склонность к новому |
| O5 | Intellect | Интеллектуальная любознательность |
| O6 | Liberalism | Либерализм |
| A | Agreeableness | **Доброжелательность** |
| A1 | Trust | Доверие |
| A2 | Morality | Честность |
| A3 | Altruism | Альтруизм |
| A4 | Cooperation | Уступчивость |
| A5 | Modesty | Скромность |
| A6 | Sympathy | Сочувствие |
| C | Conscientiousness | **Добросовестность** |
| C1 | Self-Efficacy | Самоэффективность |
| C2 | Orderliness | Организованность |
| C3 | Dutifulness | Чувство долга |
| C4 | Achievement-Striving | Стремление к достижениям |
| C5 | Self-Discipline | Самодисциплина |
| C6 | Cautiousness | Осмотрительность |

### Почему такие названия

- Домены — как в русских версиях NEO и BFI-2 (Калугин и др., 2021): Нейротизм (в BFI-2 RU — «Негативная эмоциональность»), Экстраверсия, Открытость опыту, Доброжелательность, Добросовестность.
- Тревожность, Депрессивность, Общительность, Доверие, Сочувствие, Организованность — те же слова, что у аспектов BFI-2 RU.
- N5 Immoderation — «Неумеренность», а не «Импульсивность» (так грань называется в NEO PI-R). Johnson сменил название намеренно: пункты о тяге и срывах, а «импульсивность» в русском читается как C6 «Осмотрительность» с обратным знаком.
- E3 Assertiveness — «Напористость». В BFI-2 RU этот аспект назван «Настойчивость», но по-русски настойчивость — это упорство (ближе к Добросовестности), а пункты грани — «беру руководство», «веду за собой».
- A2 Morality — «Честность», а не «Прямота» (NEO PI-R, Straightforwardness): пункты о том, чтобы не использовать и не обманывать других, а не об откровенности. «Нравственность» звучала бы как оценка человека.
- A4 Cooperation — «Уступчивость» (как Compliance в NEO PI-R): пункты о ссорах, крике и мести; низкий балл — конфликтность.
- O2 Artistic Interests — «Интерес к искусству» (в BFI-2 RU близкий аспект — «Эстетичность»; выбрано более простое слово).
- O6 Liberalism — «Либерализм» дословно. Два пункта этой грани (28, 88) — о голосовании за «либеральных/консервативных» кандидатов в смысле США; это самая спорная грань для русской версии, её стоит читать осторожно.
- C1 Self-Efficacy — «Самоэффективность» (термин Бандуры, принятый в русской психологии), а не «Компетентность» из NEO PI-R.

## Пункты

| № | Грань | Ключ | Оригинал (EN) | Перевод (RU) | Обратный перевод | Примечание |
|---|---|---|---|---|---|---|
| 1 | N1 Тревожность | + | Worry about things. | Беспокоюсь о разных вещах. | I worry about various things. |  |
| 2 | E1 Дружелюбие | + | Make friends easily. | Легко завожу друзей. | I make friends easily. |  |
| 3 | O1 Воображение | + | Have a vivid imagination. | У меня яркое воображение. | I have a vivid imagination. |  |
| 4 | A1 Доверие | + | Trust others. | Доверяю другим людям. | I trust other people. | «Доверие» — как аспект BFI-2 RU (Калугин и др., 2021). |
| 5 | C1 Самоэффективность | + | Complete tasks successfully. | Успешно справляюсь с задачами. | I cope with tasks successfully. |  |
| 6 | N2 Гневливость | + | Get angry easily. | Легко злюсь. | I get angry easily. |  |
| 7 | E2 Общительность | + | Love large parties. | Люблю большие вечеринки. | I love large parties. |  |
| 8 | O2 Интерес к искусству | + | Believe in the importance of art. | Считаю искусство важным. | I consider art important. | Калька «верю в важность искусства» звучит неестественно; смысл сохранён. |
| 9 | A2 Честность | − | Use others for my own ends. | Использую других в своих целях. | I use others for my own purposes. |  |
| 10 | C2 Организованность | + | Like to tidy up. | Люблю наводить порядок. | I like to tidy up. |  |
| 11 | N3 Депрессивность | + | Often feel blue. | Часто грущу. | I often feel sad. | Идиома feel blue передана нейтральным «грущу»; «хандрю» оставлено для п. 71. |
| 12 | E3 Напористость | + | Take charge. | Беру руководство на себя. | I take charge. |  |
| 13 | O3 Эмоциональность | + | Experience my emotions intensely. | Остро переживаю свои эмоции. | I experience my emotions acutely. |  |
| 14 | A3 Альтруизм | + | Love to help others. | Люблю помогать другим. | I love to help others. |  |
| 15 | C3 Чувство долга | + | Keep my promises. | Выполняю свои обещания. | I keep my promises. |  |
| 16 | N4 Застенчивость | + | Find it difficult to approach others. | Мне трудно идти на контакт с людьми. | I find it hard to make contact with people. | Без «первым/первой», чтобы формулировка не зависела от пола. |
| 17 | E4 Активность | + | Am always busy. | Я всегда при деле. | I am always occupied. | «Всегда занят(а)» зависит от пола; выбрано нейтральное «при деле». |
| 18 | O4 Склонность к новому | + | Prefer variety to routine. | Предпочитаю разнообразие рутине. | I prefer variety to routine. |  |
| 19 | A4 Уступчивость | − | Love a good fight. | Получаю удовольствие от хорошей стычки. | I enjoy a good clash. | Идиома love a good fight: удовольствие от конфликта, словесного или физического; «стычка» охватывает оба. |
| 20 | C4 Стремление к достижениям | + | Work hard. | Усердно работаю. | I work diligently. |  |
| 21 | N5 Неумеренность | + | Go on binges. | Случается, что я срываюсь и ни в чём не знаю меры. | Sometimes I lose control and know no limits in anything. | Однословного русского эквивалента binge нет; передан смысл эпизода неумеренности (еда, алкоголь, покупки) без перечисления. |
| 22 | E5 Поиск впечатлений | + | Love excitement. | Люблю острые ощущения. | I love thrills. |  |
| 23 | O5 Интеллектуальная любознательность | + | Love to read challenging material. | Люблю читать сложные тексты. | I love reading difficult texts. |  |
| 24 | A5 Скромность | − | Believe that I am better than others. | Считаю, что я лучше других. | I believe that I am better than others. |  |
| 25 | C5 Самодисциплина | + | Am always prepared. | У меня всё всегда подготовлено заранее. | I always have everything prepared in advance. | «Всегда готов(а)» зависит от пола; смысл передан безличной конструкцией. |
| 26 | N6 Уязвимость | + | Panic easily. | Легко поддаюсь панике. | I give in to panic easily. |  |
| 27 | E6 Жизнерадостность | + | Radiate joy. | Излучаю радость. | I radiate joy. |  |
| 28 | O6 Либерализм | + | Tend to vote for liberal political candidates. | Обычно голосую за либеральных кандидатов. | I usually vote for liberal candidates. | Пункт опирается на политический словарь США (liberal / conservative); в российском контексте смысл грани «Либерализм» требует проверки. |
| 29 | A6 Сочувствие | + | Sympathize with the homeless. | Сочувствую бездомным. | I sympathize with the homeless. | «Сочувствие» — как аспект BFI-2 RU. |
| 30 | C6 Осмотрительность | − | Jump into things without thinking. | Берусь за дела не подумав. | I take things on without thinking. |  |
| 31 | N1 Тревожность | + | Fear for the worst. | Опасаюсь худшего. | I fear the worst. |  |
| 32 | E1 Дружелюбие | + | Feel comfortable around people. | Мне комфортно среди людей. | I feel comfortable among people. |  |
| 33 | O1 Воображение | + | Enjoy wild flights of fantasy. | Мне нравятся необузданные полёты фантазии. | I enjoy unbridled flights of fantasy. |  |
| 34 | A1 Доверие | + | Believe that others have good intentions. | Считаю, что у других людей добрые намерения. | I believe that other people have good intentions. |  |
| 35 | C1 Самоэффективность | + | Excel in what I do. | Добиваюсь отличных результатов в своём деле. | I achieve excellent results in what I do. |  |
| 36 | N2 Гневливость | + | Get irritated easily. | Легко раздражаюсь. | I get irritated easily. |  |
| 37 | E2 Общительность | + | Talk to a lot of different people at parties. | На вечеринках разговариваю со множеством разных людей. | At parties I talk to many different people. |  |
| 38 | O2 Интерес к искусству | + | See beauty in things that others might not notice. | Вижу красоту в том, чего другие могут не заметить. | I see beauty in what others might not notice. |  |
| 39 | A2 Честность | − | Cheat to get ahead. | Жульничаю, чтобы обойти других. | I cheat to get ahead of others. |  |
| 40 | C2 Организованность | − | Often forget to put things back in their proper place. | Часто забываю положить вещи на место. | I often forget to put things back in their place. |  |
| 41 | N3 Депрессивность | + | Dislike myself. | Я себе не нравлюсь. | I don't like myself. |  |
| 42 | E3 Напористость | + | Try to lead others. | Стараюсь вести за собой других. | I try to lead others. |  |
| 43 | O3 Эмоциональность | + | Feel others' emotions. | Чувствую эмоции других людей. | I feel other people's emotions. |  |
| 44 | A3 Альтруизм | + | Am concerned about others. | Мне не безразличны другие люди. | I care about other people. | Concerned about передано как «не безразличны», а не «беспокоюсь», чтобы не смешивать с N1 «Тревожность». |
| 45 | C3 Чувство долга | + | Tell the truth. | Говорю правду. | I tell the truth. |  |
| 46 | N4 Застенчивость | + | Am afraid to draw attention to myself. | Боюсь привлекать к себе внимание. | I am afraid to draw attention to myself. |  |
| 47 | E4 Активность | + | Am always on the go. | Я всегда в движении. | I am always on the move. |  |
| 48 | O4 Склонность к новому | − | Prefer to stick with things that I know. | Предпочитаю держаться того, что мне знакомо. | I prefer to stick to what I know. |  |
| 49 | A4 Уступчивость | − | Yell at people. | Кричу на людей. | I yell at people. |  |
| 50 | C4 Стремление к достижениям | + | Do more than what's expected of me. | Делаю больше, чем от меня ждут. | I do more than is expected of me. |  |
| 51 | N5 Неумеренность | − | Rarely overindulge. | Редко позволяю себе лишнее. | I rarely allow myself too much. |  |
| 52 | E5 Поиск впечатлений | + | Seek adventure. | Ищу приключений. | I seek adventure. |  |
| 53 | O5 Интеллектуальная любознательность | − | Avoid philosophical discussions. | Избегаю философских разговоров. | I avoid philosophical conversations. |  |
| 54 | A5 Скромность | − | Think highly of myself. | Высоко себя ставлю. | I rate myself highly. | Разведено с п. 84 («высокого мнения о себе»): в оригинале пункты тоже близки. |
| 55 | C5 Самодисциплина | + | Carry out my plans. | Осуществляю свои планы. | I carry out my plans. |  |
| 56 | N6 Уязвимость | + | Become overwhelmed by events. | Меня захлёстывают события. | I get overwhelmed by events. |  |
| 57 | E6 Жизнерадостность | + | Have a lot of fun. | Много веселюсь. | I have a lot of fun. |  |
| 58 | O6 Либерализм | + | Believe that there is no absolute right and wrong. | Считаю, что нет ничего абсолютно правильного или неправильного. | I believe there is nothing absolutely right or wrong. | В статье Johnson (2014, табл. 1) — «right or wrong», на ipip.ori.org — «right and wrong»; смысл один. |
| 59 | A6 Сочувствие | + | Feel sympathy for those who are worse off than myself. | Сочувствую тем, кому живётся хуже, чем мне. | I feel sympathy for those whose life is worse than mine. |  |
| 60 | C6 Осмотрительность | − | Make rash decisions. | Принимаю опрометчивые решения. | I make rash decisions. |  |
| 61 | N1 Тревожность | + | Am afraid of many things. | Многого боюсь. | I am afraid of many things. |  |
| 62 | E1 Дружелюбие | − | Avoid contacts with others. | Избегаю общения с другими. | I avoid contact with others. |  |
| 63 | O1 Воображение | + | Love to daydream. | Люблю помечтать. | I love to daydream. |  |
| 64 | A1 Доверие | + | Trust what people say. | Верю тому, что говорят люди. | I believe what people say. |  |
| 65 | C1 Самоэффективность | + | Handle tasks smoothly. | Справляюсь с задачами гладко, без заминок. | I handle tasks smoothly, without hitches. |  |
| 66 | N2 Гневливость | + | Lose my temper. | Выхожу из себя. | I lose my temper. |  |
| 67 | E2 Общительность | − | Prefer to be alone. | Предпочитаю быть в одиночестве. | I prefer to be alone. |  |
| 68 | O2 Интерес к искусству | − | Do not like poetry. | Не люблю стихи. | I don't like poetry. |  |
| 69 | A2 Честность | − | Take advantage of others. | Пользуюсь другими людьми. | I take advantage of other people. |  |
| 70 | C2 Организованность | − | Leave a mess in my room. | Оставляю беспорядок в своей комнате. | I leave a mess in my room. |  |
| 71 | N3 Депрессивность | + | Am often down in the dumps. | Часто хандрю. | I often mope. | Идиома down in the dumps; «хандрю» отличает пункт от п. 11 «Часто грущу». |
| 72 | E3 Напористость | + | Take control of things. | Беру ситуацию под свой контроль. | I take control of the situation. |  |
| 73 | O3 Эмоциональность | − | Rarely notice my emotional reactions. | Редко замечаю свои эмоциональные реакции. | I rarely notice my emotional reactions. |  |
| 74 | A3 Альтруизм | − | Am indifferent to the feelings of others. | Чувства других мне безразличны. | The feelings of others are indifferent to me. |  |
| 75 | C3 Чувство долга | − | Break rules. | Нарушаю правила. | I break rules. |  |
| 76 | N4 Застенчивость | + | Only feel comfortable with friends. | Мне комфортно только с друзьями. | I feel comfortable only with friends. |  |
| 77 | E4 Активность | + | Do a lot in my spare time. | В свободное время успеваю много всего. | In my spare time I manage to do a lot. |  |
| 78 | O4 Склонность к новому | − | Dislike changes. | Не люблю перемен. | I dislike changes. |  |
| 79 | A4 Уступчивость | − | Insult people. | Оскорбляю людей. | I insult people. |  |
| 80 | C4 Стремление к достижениям | − | Do just enough work to get by. | Делаю по работе только необходимый минимум. | At work I do only the necessary minimum. |  |
| 81 | N5 Неумеренность | − | Easily resist temptations. | Мне легко устоять перед соблазнами. | It is easy for me to resist temptations. |  |
| 82 | E5 Поиск впечатлений | + | Enjoy being reckless. | Мне нравится совершать безрассудные поступки. | I like doing reckless things. |  |
| 83 | O5 Интеллектуальная любознательность | − | Have difficulty understanding abstract ideas. | Мне трудно понимать абстрактные идеи. | I find it hard to understand abstract ideas. |  |
| 84 | A5 Скромность | − | Have a high opinion of myself. | Я высокого мнения о себе. | I have a high opinion of myself. |  |
| 85 | C5 Самодисциплина | − | Waste my time. | Трачу время впустую. | I waste my time. |  |
| 86 | N6 Уязвимость | + | Feel that I'm unable to deal with things. | Чувствую, что не могу справиться с происходящим. | I feel that I cannot cope with what is happening. |  |
| 87 | E6 Жизнерадостность | + | Love life. | Люблю жизнь. | I love life. |  |
| 88 | O6 Либерализм | − | Tend to vote for conservative political candidates. | Обычно голосую за консервативных кандидатов. | I usually vote for conservative candidates. | См. п. 28: политический словарь США. |
| 89 | A6 Сочувствие | − | Am not interested in other people's problems. | Меня не интересуют чужие проблемы. | Other people's problems don't interest me. |  |
| 90 | C6 Осмотрительность | − | Rush into things. | Бросаюсь в дела очертя голову. | I rush headlong into things. |  |
| 91 | N1 Тревожность | + | Get stressed out easily. | Легко впадаю в стресс. | I get stressed easily. |  |
| 92 | E1 Дружелюбие | − | Keep others at a distance. | Держу людей на расстоянии. | I keep people at a distance. |  |
| 93 | O1 Воображение | + | Like to get lost in thought. | Люблю погружаться в свои мысли. | I like to immerse myself in my thoughts. |  |
| 94 | A1 Доверие | − | Distrust people. | Не доверяю людям. | I don't trust people. |  |
| 95 | C1 Самоэффективность | + | Know how to get things done. | Знаю, как довести дело до конца. | I know how to get a job done. |  |
| 96 | N2 Гневливость | − | Am not easily annoyed. | Меня мало что раздражает. | Few things annoy me. | Разведено с п. 6 и 36 («злюсь», «раздражаюсь»), чтобы обратный пункт не читался как отрицание соседнего. |
| 97 | E2 Общительность | − | Avoid crowds. | Избегаю толпы. | I avoid crowds. |  |
| 98 | O2 Интерес к искусству | − | Do not enjoy going to art museums. | Не люблю ходить в художественные музеи. | I don't like going to art museums. |  |
| 99 | A2 Честность | − | Obstruct others' plans. | Препятствую чужим планам. | I obstruct other people's plans. |  |
| 100 | C2 Организованность | − | Leave my belongings around. | Разбрасываю свои вещи. | I leave my things scattered around. |  |
| 101 | N3 Депрессивность | − | Feel comfortable with myself. | Я в ладу с собой. | I am at peace with myself. | «Мне хорошо с самим/самой собой» зависит от пола; выбрано нейтральное «в ладу с собой». |
| 102 | E3 Напористость | − | Wait for others to lead the way. | Жду, когда другие возьмут инициативу на себя. | I wait for others to take the initiative. |  |
| 103 | O3 Эмоциональность | − | Don't understand people who get emotional. | Не понимаю тех, кто даёт волю эмоциям. | I don't understand those who give vent to their emotions. |  |
| 104 | A3 Альтруизм | − | Take no time for others. | Не нахожу времени для других. | I find no time for others. |  |
| 105 | C3 Чувство долга | − | Break my promises. | Нарушаю свои обещания. | I break my promises. |  |
| 106 | N4 Застенчивость | − | Am not bothered by difficult social situations. | Меня не смущают трудные ситуации в общении. | Difficult social situations don't embarrass me. |  |
| 107 | E4 Активность | − | Like to take it easy. | Люблю жить в спокойном, неспешном темпе. | I like to live at a calm, unhurried pace. |  |
| 108 | O4 Склонность к новому | − | Am attached to conventional ways. | Держусь привычного и общепринятого. | I hold on to the habitual and conventional. | «Привязан(а)» зависит от пола; выбран глагол. |
| 109 | A4 Уступчивость | − | Get back at others. | Отплачиваю другим той же монетой. | I pay others back in kind. |  |
| 110 | C4 Стремление к достижениям | − | Put little time and effort into my work. | Вкладываю в работу мало времени и сил. | I put little time and effort into my work. |  |
| 111 | N5 Неумеренность | − | Am able to control my cravings. | Умею контролировать свою тягу к чему-либо. | I can control my cravings for things. |  |
| 112 | E5 Поиск впечатлений | + | Act wild and crazy. | Веду себя сумасбродно и необузданно. | I behave in a wild, unrestrained way. |  |
| 113 | O5 Интеллектуальная любознательность | − | Am not interested in theoretical discussions. | Меня не интересуют теоретические рассуждения. | Theoretical discussions don't interest me. |  |
| 114 | A5 Скромность | − | Boast about my virtues. | Хвастаюсь своими достоинствами. | I boast about my virtues. |  |
| 115 | C5 Самодисциплина | − | Have difficulty starting tasks. | Мне трудно приступить к делу. | I find it hard to get started on a task. |  |
| 116 | N6 Уязвимость | − | Remain calm under pressure. | Сохраняю спокойствие под давлением. | I stay calm under pressure. |  |
| 117 | E6 Жизнерадостность | + | Look at the bright side of life. | Смотрю на жизнь с оптимизмом. | I look at life with optimism. | Калька «смотрю на светлую сторону жизни» в русском не принята; использован устойчивый оборот. |
| 118 | O6 Либерализм | − | Believe that we should be tough on crime. | Считаю, что с преступностью нужно бороться жёстко. | I believe crime should be fought harshly. | См. п. 28: пункт из политического словаря США. |
| 119 | A6 Сочувствие | − | Try not to think about the needy. | Стараюсь не думать о нуждающихся. | I try not to think about people in need. |  |
| 120 | C6 Осмотрительность | − | Act without thinking. | Действую, не подумав. | I act without thinking. |  |
