# Stavy objednávok

Stavy objednávok e-shopu (napr. „Platba akceptovaná“, „Zaplatená a odoslaná“). Ukladajú sa **priamo v e-shope**.
Otvoríte vo **Nastavenia → E-shopy → Stavy objednávok** (len správca).

## Zoznam

Pri každom stave vidno, či posiela zákazníkovi **e-mail**. Stav, ktorý má šablónu e-mailu, ale posielanie vypnuté,
je označený — pri zmene na tento stav zákazník e-mail nedostane, aj keď pri zmene zaškrtnete „poslať e-mail“.

## Úprava a nový stav

- **Názov** (slovenský; ostatné jazyky e-shopu ostávajú) a **Farba**.
- **Šablóna e-mailu** — napr. `shipped` (odoslanie s odkazom na sledovanie), `in_transit` (na ceste / na
  vyzdvihnutie), `preparation` (pripravuje sa), `payment` (platba prijatá).
- **Poslať zákazníkovi e-mail, keď objednávka prejde do tohto stavu** — bez tohto e-shop e-mail nepošle.
- **Objednávka je zaplatená / odoslaná**, **Zobraziť dodací list**, **Priložiť dodací list k e-mailu**,
  **Považovať objednávku za platnú** (štatistiky, tržby), **Skryť tento stav zákazníkovi**.
- **Uložiť do e-shopu**.

> Stav, ktorý používa modul (napr. platobná brána), meňte opatrne. Stavy sa tu nemažú. Nastavenia faktúr
> PrestaShopu panel nemení — faktúry vystavuje panel.

Návod v skratke: [Ako zapnúť e-mail pri zmene stavu](help:ako-email-pri-zmene-stavu).
