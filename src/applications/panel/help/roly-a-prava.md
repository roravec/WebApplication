# Roly a práva

Ako panel rozhoduje, čo kto smie. Nastavuje sa v **Nastavenia → Zamestnanci a roly**.

## Ako to funguje

Človek smie niečo urobiť, len ak mu to dovolia **obe** veci:

1. **Čo smie robiť** — práva, napr. *Objednávky — prezeranie*, *Faktúry — vystavovanie*.
   Dostane ich z **rolí** + **výnimiek**:
   - **rola** = pomenovaná sada práv pre jednu prácu (*Expedícia*, *Fakturácia a účtovníctvo*, *Sklad a nákup*,
     *Len čítanie* …). Človek môže mať viac rolí — práva sa sčítajú.
   - **výnimka „povoliť navyše“** = právo, ktoré mu žiadna rola nedáva, ale on ho potrebuje.
   - **výnimka „zakázať“** = právo, ktoré mu rola dáva, ale on ho mať nemá. Zákaz má vždy prednosť.
2. **Kde** — **firmy** (faktúry, náklady, sklad, výsledky len týchto firiem) a **e-shopy** (objednávky, expedícia,
   produkty a sklad v e-shope len týchto e-shopov).

**Správca** smie všetko — aj v moduloch, ktoré do panela pribudnú neskôr — vo všetkých firmách a e-shopoch.

Čo nie je dovolené, je zakázané: kto nemá právo, danú položku v menu ani tlačidlo nevidí a stránka ho nepustí
(„Prístup zamietnutý“). Zmeny platia hneď.

## Roly

Záložka **Roly**:

1. **+ Nová rola** (alebo kliknite na existujúcu).
2. **Názov** (napr. *Brigádnik expedícia*) a **popis** — pre koho je.
3. Zaškrtnite **práva roly**. Pri každom je napísané, čo dovoľuje; **citlivé** = peniaze, nastavenia alebo nevratné
   kroky.
4. **Uložiť**. Zmena roly platí hneď pre všetkých, ktorí ju majú (zoznam je dole na stránke roly).

**Zmazať rolu** — odoberie sa všetkým, ktorí ju mali, a prídu o práva, ktoré im dávala (ak ich nemajú z inej roly
alebo výnimky). Panel sa pred zmazaním opýta.

Pri novej inštalácii sú pripravené roly *Fakturácia a účtovníctvo*, *Expedícia*, *Sklad a nákup* a *Len čítanie* —
upravte si ich podľa seba.

## Kto čo smie

Záložka **Kto čo smie** = jedna tabuľka: v stĺpcoch aktívni zamestnanci, v riadkoch ich roly, firmy, e-shopy a všetky
práva (✓ = smie). Hodí sa na kontrolu, či niekto nemá viac, ako treba. Kliknutím na meno otvoríte jeho nastavenie.

## Pravidlá, ktoré panel stráži

- Kto nie je správca, **nedá nikomu viac**, ako má sám (práva, firmy, e-shopy), a **nezmení človeka, ktorý má viac
  práv** ako on (napr. správcu). Rolu, ktorá dáva práva, ktoré sám nemá, nemôže meniť ani priradiť.
- **Správcu** môže určiť len správca.
- Nikto si **nevezme sám sebe** práva správcu, právo *Zamestnanci a roly* ani nevypne svoj účet — aby sa panel
  nezamkol.
- Každá zmena práv (pri človeku aj pri role) sa zapíše do **Histórie** — kto, kedy, čo pridal a čo odobral.

## Príklady

| Kto | Ako nastaviť |
|---|---|
| Účtovníčka | rola *Fakturácia a účtovníctvo*, firmy: len jej firma |
| Brigádnik na balenie jedného e-shopu | rola *Expedícia*, e-shopy: len ten e-shop |
| Skladník, ktorý nesmie meniť stavy objednávok | rola *Sklad a nákup* (stavy objednávok nemá) |
| Expedícia, ktorá smie aj vystaviť faktúru z objednávky | rola *Expedícia* + výnimka „povoliť navyše“ *Faktúry — vystavovanie* |
| Niekto, kto má len pozerať | rola *Len čítanie* |

Pozor pri e-shopoch so **spoločným skladom** (napr. dva e-shopy v jednej skupine): kto mení sklad jedného z nich,
mení ho aj druhému — tak to má nastavené PrestaShop.
