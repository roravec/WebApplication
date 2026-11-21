# Nastavenia → Náklady objednávok

Čo vás stojí objednávka okrem tovaru: **doprava** podľa dopravcu, **poplatok za platbu** (GoPay, dobierka:
% zo sumy + pevná suma) a **balné**. Výsledky z nich počítajú **príspevok na krytie** — koľko vám objednávky
naozaj zarobia.

## Postup

1. Pridajte riadok: **Druh** (doprava / platba / balné), **E-shop** (alebo všetky), **Dopravca / platba**,
   **€ za objednávku** a/alebo **% zo sumy**, **Platí od – do**, poznámka.
2. **Uložiť**. Viac prázdnych riadkov pribudne po uložení.

## Pravidlá

- Sumy ako váš náklad: bez DPH, ak ste platiteľ DPH, inak s DPH.
- **Pri zmene ceny neprepisujte starú** — starej dajte dátum „do“ a pridajte novú s „od“; staré objednávky ostanú
  so starou cenou.
- Platí najkonkrétnejšie pravidlo (e-shop + dopravca > dopravca > e-shop > všetky).
- Skutočné faktúry dopravcov a platobných brán zadávajte do **Nákladov** (kategória druhu „náklady na objednávky“) —
  Výsledok firmy ich porovná s týmto odhadom.
