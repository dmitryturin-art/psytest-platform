#!/usr/bin/env python3
"""Независимые эталоны дополнительных шкал СМИЛ (партии 05.S3.1-05.S3.5).

Скрипт считает ожидаемые raw и T **по транскрипции источника**
(docs/smil-additional-scales-transcription.json), а не по runtime-данным PHP
и не по modules/smil/additional-scales-v2.json. Это и делает эталон
независимым: PHP-тест сравнивает свой расчёт с этим файлом, и совпадение
означает согласие двух независимых реализаций с источником.

Только стандартная библиотека. Запуск: python3 bin/smil-additional-reference.py
Результат: tests/fixtures/smil-additional-reference.json
"""

from __future__ import annotations

import json
import os
import random

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TRANSCRIPTION = os.path.join(ROOT, "docs", "smil-additional-scales-transcription.json")
VALID_ANSWERS = os.path.join(ROOT, "tests", "fixtures", "smil-reference-answers-valid.json")
OUTPUT = os.path.join(ROOT, "tests", "fixtures", "smil-additional-reference.json")

TOTAL_QUESTIONS = 566
SEED = 20260915

# Партия -> (номер записи транскрипции -> runtime-код). Все пять утверждены 15.09.2026.
# Списки дублируют bin/smil-build-batch.php намеренно: эталон не должен читать
# ни runtime-файл шкал, ни PHP-код.
BATCHES = {
    "05.S3.1": {
        1: "A",
        2: "LRN",
        6: "MAT",
        39: "CYN",
        41: "DPR",
        42: "DSU",
        43: "DRT",
        49: "Do",
        51: "DOV",
        53: "DRX",
        57: "DPN",
        62: "EGO",
        77: "OH",
        92: "Es",
        171: "R",
        174: "Re",
    },
    "05.S3.2": {
        37: "CNV",
        46: "GLM",
        48: "DNS",
        60: "EGC",
        73: "HDC",
        75: "HLT",
        80: "HYP",
        83: "HYS",
        84: "ARP",
        87: "SOM",
        89: "HYO",
        90: "HYL",
        93: "IMP",
        97: "ANC",
        98: "GLT",
        129: "NEU",
        131: "NOC",
        134: "NUC",
        193: "SOR",
    },
    "05.S3.3": {
        61: "EPI",
        138: "PAR",
        139: "PRS",
        140: "POI",
        141: "NAI",
        142: "PAO",
        143: "PAS",
        144: "PRC",
        146: "PPD",
        147: "FMD",
        148: "AUT",
        152: "PDO",
        153: "PDS",
        156: "SZP",
        157: "PFA",
        158: "PNE",
        170: "PSZ",
        182: "SAL",
        183: "EAL",
        187: "BSE",
    },
    "05.S3.4": {
        26: "Cn",
        36: "CMP",
        59: "EIM",
        66: "FEM",
        106: "LDR",
        109: "HPM",
        110: "Ma1",
        111: "Ma2",
        114: "MaO",
        115: "MaS",
        119: "SEN",
        121: "ALT",
        169: "PSI",
        177: "RPL",
        189: "SSF",
        194: "SDS",
        196: "SPA",
        200: "SST",
        203: "SHY",
        208: "TTD",
    },
    "05.S3.5": {
        7: "ALD",
        19: "RSP",
        22: "CAU",
        23: "Cl",
        38: "Cs",
        47: "CRM1",
        50: "Do2",
        52: "CRM2",
        55: "Ds",
        64: "IMV",
        70: "GMA",
        74: "HCO",
        81: "Hv",
        85: "Hy2",
        88: "Hy5",
        94: "In",
        95: "IQR",
        99: "CHO",
        122: "Mf4",
        135: "Or",
        162: "Pr",
        172: "RCD",
        181: "SCZ",
        205: "To",
        206: "TCH",
        209: "ULC",
        210: "LAC",
        211: "Wa",
        212: "SDF",
    },
}

# Плоский список в порядке вывода: партии 1-5, внутри партии — по номеру записи.
BATCH = [
    (number, code, batch)
    for batch, codes in BATCHES.items()
    for number, code in sorted(codes.items())
]

ANSWER_YES = 1
ANSWER_NO = 0
ANSWER_UNKNOWN = 2

T_MIN = 20
T_MAX = 100


def php_round(value: float) -> float:
    """PHP round(): половинки уходят от нуля, а не к чётному как в Python."""
    import math

    if value >= 0:
        return math.floor(value + 0.5)
    return math.ceil(value - 0.5)


def score(entry: dict, answers: dict, sex: str) -> dict:
    raw = 0
    answered = 0

    for qid in entry["true"]:
        value = answers.get(qid)
        if value is None or value == ANSWER_UNKNOWN:
            continue
        answered += 1
        if value == ANSWER_YES:
            raw += 1

    for qid in entry["false"]:
        value = answers.get(qid)
        if value is None or value == ANSWER_UNKNOWN:
            continue
        answered += 1
        if value == ANSWER_NO:
            raw += 1

    mean = entry[sex]["M"]
    sigma = entry[sex]["sigma"]
    t = 50.0 if sigma == 0 else php_round(50 + 10 * (raw - mean) / sigma)
    t = float(max(T_MIN, min(T_MAX, t)))

    return {
        "raw": raw,
        "t": t,
        "M": mean,
        "sigma": sigma,
        "max_raw": len(entry["true"]) + len(entry["false"]),
        "answered": answered,
    }


def load_valid_answers() -> dict:
    with open(VALID_ANSWERS, encoding="utf-8") as fh:
        payload = json.load(fh)
    answers = {}
    for key, value in payload.items():
        if str(key).isdigit():
            answers[int(key)] = int(value)
    return answers


def pseudo_random_answers() -> dict:
    rnd = random.Random(SEED)
    return {qid: rnd.choice([0, 1, 2]) for qid in range(1, TOTAL_QUESTIONS + 1)}


def main() -> None:
    with open(TRANSCRIPTION, encoding="utf-8") as fh:
        doc = json.load(fh)

    entries = {int(entry["number"]): entry for entry in doc["entries"]}

    answer_sets = {
        "all_true": {qid: ANSWER_YES for qid in range(1, TOTAL_QUESTIONS + 1)},
        "all_false": {qid: ANSWER_NO for qid in range(1, TOTAL_QUESTIONS + 1)},
        "reference_valid": load_valid_answers(),
        "pseudo_random_20260915": pseudo_random_answers(),
    }

    cases = {}
    for set_name, answers in answer_sets.items():
        for sex in ("male", "female"):
            case = {}
            for number, code, _batch in BATCH:
                case[code] = score(entries[number], answers, sex)
            cases[f"{set_name}_{sex}"] = case

    output = {
        "title": "Независимые эталоны дополнительных шкал СМИЛ, партии 05.S3.1-05.S3.5",
        "generated_by": "bin/smil-additional-reference.py",
        "provenance": {
            "source": doc["source"],
            "input": "docs/smil-additional-scales-transcription.json (транскрипция S2, два независимых прохода)",
            "independence": (
                "Расчёт выполнен вне PHP и вне modules/smil/additional-scales-v2.json: "
                "ключи и нормы взяты прямо из транскрипции источника."
            ),
            "entries": {str(number): code for number, code, _batch in BATCH},
            "batches": {str(number): batch for number, _code, batch in BATCH},
            "formula": (
                "raw = число ответов «верно» по списку true плюс «неверно» по списку false "
                "(«не знаю» = 2 и пропуски не считаются); "
                "T = 50 + 10 * (raw - M) / sigma по нормам пола, округление до целого "
                "(половинки от нуля, как PHP round), зажим [20, 100]."
            ),
            "answer_encoding": {"0": "неверно", "1": "верно", "2": "не знаю"},
            "answer_sets": {
                "all_true": "все 566 пунктов = «верно»",
                "all_false": "все 566 пунктов = «неверно»",
                "reference_valid": "tests/fixtures/smil-reference-answers-valid.json",
                "pseudo_random_20260915": f"random.Random({SEED}).choice([0, 1, 2]) по пунктам 1..566",
            },
        },
        # Псевдослучайный набор не воспроизводится средствами PHP, поэтому
        # сами ответы едут вместе с эталоном: PHP-тест берёт их отсюда.
        "answers": {
            "pseudo_random_20260915": {
                str(qid): value for qid, value in sorted(answer_sets["pseudo_random_20260915"].items())
            }
        },
        "cases": cases,
    }

    with open(OUTPUT, "w", encoding="utf-8") as fh:
        json.dump(output, fh, ensure_ascii=False, indent=2)
        fh.write("\n")

    print(f"Записано {len(cases)} наборов x {len(BATCH)} шкал в {os.path.relpath(OUTPUT, ROOT)}")


if __name__ == "__main__":
    main()
