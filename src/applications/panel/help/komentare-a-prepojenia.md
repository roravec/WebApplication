# Komentáre, prepojenia, história a QR štítok

Dole na stránke objednávky, faktúry, prijatého dokladu, skladovej karty, skladového dokladu, odberateľa, firmy,
zamestnanca a roly sú vždy rovnaké časti: **Komentáre**, **Prepojenia**, **História** a krátky odkaz s QR štítkom.
Vidia ich len zamestnanci — zákazník nikdy.

## Komentáre

1. Do poľa **Napíšte komentár** napíšte text, napr. „Zákazník volal, pošleme v piatok.“
2. **Pridať komentár**.

- **Upozorniť kolegu**: napíšte **@** a začiatok mena — panel ponúkne mená kolegov, kliknite na správne
  (napr. „@Ján Novák pozri prosím adresu“). Meno je
  zvýraznené a kolega dostane upozornenie (zvonček, e-mail) — [Upozornenia](help:upozornenia).
- **Odkaz na iný záznam**: napíšte jeho kód, napr. `invoice:15` alebo `order:0:2481` — v komentári sa z neho stane
  odkaz s názvom záznamu (kód nájdete dole na stránke záznamu pri **Krátky odkaz**). Kto k záznamu nemá prístup,
  uvidí len kód.
- **Upraviť / zmazať** môže komentár len ten, kto ho napísal (a správca): **upraviť** pod komentárom. Zmazaný komentár
  ostane ako „komentár zmazaný“, aby diskusia dávala zmysel.

> Interné poznámky objednávok sú odteraz komentáre — staré poznámky sa preniesli, nič sa nestratilo. V Expedícii
> ich objednávka má značku 📝.

## Prepojenia

Spojí dva súvisiace záznamy, napr. objednávku s prijatým dokladom za dopravu, faktúru s odberateľom, dobropis
s pôvodnou faktúrou.

1. V časti **Prepojenia** vyberte druh: **súvisí s**, **blokuje**, **overuje**, **vzniklo zo záznamu**, **nahrádza**.
2. Do poľa napíšte **číslo faktúry, objednávky (kód), dokladu alebo názov** (odberateľ, skladová karta) — po dvoch
   znakoch panel ponúkne záznamy, kliknite na správny. Môžete vložiť aj krátky odkaz záznamu (…/r/…).
3. **Prepojiť**.

Prepojenie sa ukáže na oboch záznamoch (na druhom opačne, napr. „blokuje“ ↔ „je blokované záznamom“) a zapíše sa do
histórie oboch. **✕** prepojenie zruší. Záznamy, ku ktorým nemáte prístup, v prepojeniach nevidíte.

| Hláška | Čo s tým |
|---|---|
| „Záznam … sa nenašiel (alebo k nemu nemáte prístup)“ | Skontrolujte číslo, alebo vyberte zo zoznamu, ktorý sa ukáže pri písaní. |
| „Podľa … sa našlo viac záznamov“ | Napíšte celé číslo, alebo vyberte zo zoznamu. |
| „Toto prepojenie už existuje.“ | Záznamy sú už prepojené — nič netreba. |

## História

Kto, kedy a čo so záznamom urobil — vystavenie, úhrady, zmeny stavu, komentáre, prepojenia… Zapisuje sa samo,
nedá sa meniť. Všetko naprieč panelom (čo smiete vidieť) je v [Čo sa deje](help:co-sa-deje).

## Krátky odkaz a QR štítok

- **Krátky odkaz** (napr. `…/panel/r/invoice:15`) — pošlite ho kolegovi; otvorí záznam po prihlásení a len tomu, kto
  k nemu má prístup.
- **QR štítok na vytlačenie** — otvorí stránku so štítkom (QR kód + názov). Vytlačte ho (Ctrl+P) na tlačiarni štítkov
  alebo na papier a nalepte (krabica so súčiastkami, zariadenie, šanón). Naskenovanie mobilom otvorí záznam v paneli.
