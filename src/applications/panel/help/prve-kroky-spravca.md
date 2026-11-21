# Nastavenie panela pre správcu (krok za krokom)

Toto robí **správca** raz, keď sa panel začína používať (alebo keď pribudne nová firma či e-shop). Postupujte
v tomto poradí — každý krok potrebuje ten predchádzajúci.

## 1. Firma

1. **Nastavenia → Firmy → + Nová firma** (alebo otvorte existujúcu).
2. Vyplňte **Údaje firmy**: obchodné meno, adresu, IČO (tlačidlo **Doplniť z registra** doplní údaje sám), DIČ a
   **zápis v obchodnom registri** — je to povinný údaj na faktúre.
3. **Režim DPH**: ak firma nie je platiteľ DPH, nechajte bez obdobia. Ak je, pridajte **+ Obdobie DPH** s dátumom,
   od ktorého platí, a IČ DPH.
4. **Bankové účty**: aspoň jeden, označený ako predvolený (tlačí sa na faktúru, robí sa z neho QR kód na platbu).
5. **Logo** a **Pečiatka a podpis** (PNG/JPG do 2 MB) — tlačia sa na faktúru.
6. **E-shopy**: zaškrtnite, objednávky ktorých e-shopov fakturuje táto firma.
7. **Uložiť firmu**. Tlačidlom **Náhľad** v časti Náhľad faktúry si pozrite, ako bude faktúra vyzerať.

Podrobne: [Nastavenia → Firmy](help:nastavenia-firmy)

## 2. Číselné rady

Dole vo firme je **Číselné rady**. Pri prvom uložení firmy sa založia rady pre faktúry a dobropisy. Otvorte rad
**Faktúra** a nastavte **Ďalšie poradové číslo** tak, aby **nadväzovalo na posledné číslo, ktoré ste vystavili
predtým** (napr. v e-shope). Podrobne: [Číselné rady](help:ciselne-rady)

## 3. E-shop

**Nastavenia → E-shopy → + Pripojiť e-shop** — pripojenie cez kľúč Webservice. Bez neho sa objednávky dajú len
čítať, nie meniť. Podrobne: [Ako napojiť e-shop](help:ako-napojit-eshop)

## 4. Účtovníčka

Vo firme v časti **Pre účtovníka (mesačný balík)** vyplňte e-mail účtovníčky a zapnite **Posielať automaticky**.
V časti **Export do Omegy** nechajte predvolené hodnoty, kým vám účtovníčka nepovie iné. Podrobne:
[Pre účtovníka](help:pre-uctovnika)

## 5. Zamestnanci a roly

1. **Nastavenia → Zamestnanci a roly → Roly** — pozrite pripravené roly (*Fakturácia a účtovníctvo*, *Expedícia*,
   *Sklad a nákup*, *Len čítanie*) a upravte ich podľa toho, kto u vás čo robí.
2. **Zamestnanci → + Nový zamestnanec**: meno, e-mail (je to prihlasovacie meno), heslo, roly, firmy a e-shopy.
3. **Kto čo smie** — skontrolujte, či nikto nemá viac, ako treba.

Podrobne: [Zamestnanci a roly](help:zamestnanci), [Roly a práva](help:roly-a-prava)

## 6. Kontrola

- Otvorte **Objednávky** — vidíte objednávky e-shopov?
- Otvorte jednu objednávku — vidíte pri adresách „Zmeniť“? (Ak nie, panel hore napíše, čo chýba.)
- Vystavte skúšobný **koncept** faktúry a pozrite **Náhľad** — nevystavujte ho, len ho potom zmažte.
