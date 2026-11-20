# Sklad a pripojenie e-shopov — požiadavky a návrh

Stav: rozhodnutia majiteľa 19. 11. 2026. **Krok 1 (natívny sklad) hotový**, **krok 2a (pripojenie e-shopu a úprava
jeho skladu priamo v e-shope) hotový** 19. 11. 2026 (dev server, overené na kópii e-shopu dev1). Ďalej: ďalšie nastavenia
skladu produktu, zmena stavu objednávky, objednávky a faktúry pre e-shopy pripojené len cez Webservice.

## 1. Rozhodnutia majiteľa (19. 11. 2026)
1. ~~Panel je master, e-shopy berú množstvá z panela~~ — **zmenené 19. 11. 2026 (majiteľ):** sklad e-shopu ostáva
   **v e-shope**; panel ho **priamo upravuje** cez rozhranie PrestaShopu (množstvá, nastavenia skladu, kombinácie).
   Napojiť e-shop, aby bral dáta od nás, by nebolo univerzálne.
2. **Každý e-shop má vlastný sklad** (ten v e-shope). **Sklady mimo e-shopov** (veci predávané mimo e-shopov) sú
   **natívne v našom systéme** (dávky, nákupné ceny, FIFO — krok 1).
2a. **Kombinácie** (varianty) — čo najkompatibilnejšie so samotným e-shopom: produkt + kombinácia presne ako v
   PrestaShope (ich ID, atribúty, kód, EAN).
3. Sklady a ich umiestnenie sú **nastaviteľné** (nič napevno).
4. Eviduje sa **nákupná cena každej dávky** (príjem tovaru za inú cenu = iná dávka).
5. Panel sa má vedieť **pripojiť k akémukoľvek PrestaShopu** a cez neho: vidieť objednávky, meniť ich stav,
   upravovať sklad (aj nastavenia skladu v PrestaShope), vystavovať faktúry. Teda nie len k obchodom v našej
   databáze — aj k cudziemu PrestaShopu na inom serveri.

## 2. Návrh — skladový modul (krok 1)
- **Sklad** — patrí firme (účtovne), názov, kód, umiestnenie (voľný text), typ: e-shop / iný, aktívny.
- **Skladová karta** (položka) — patrí firme: kód (SKU), názov, MJ, EAN, poznámka. Množstvo sa eviduje v každom
  sklade zvlášť.
- **Dávka** — príjem tovaru: sklad, karta, dátum príjmu, prijaté množstvo, zostávajúce množstvo, **nákupná cena za MJ
  bez DPH**, voliteľne číslo šarže a dátum spotreby. Stav karty v sklade = súčet zostatkov dávok.
- **Skladové doklady** (číslované podľa číselných radov firmy, po zaúčtovaní sa nemenia, oprava = opačný doklad):
  - **príjemka** — vytvorí dávky (dodávateľ, číslo faktúry dodávateľa),
  - **výdajka** — vydá tovar metódou **FIFO** (najstaršie dávky prvé), náklad = ceny spotrebovaných dávok,
  - **prevodka** — presun medzi skladmi, dávka si zachová nákupnú cenu a dátum príjmu,
  - **inventúra** — zistený stav → rozdiel (manko z najstarších dávok, prebytok ako nová dávka).
- Záporný stav nie je dovolený (výdaj väčší ako zásoba sa odmietne).
- Ocenenie zásob = Σ zostatok dávky × jej nákupná cena (FIFO).
- Oprávnenia: Sklad — prezeranie, Sklad — pohyby; prístup len k skladom firiem, ku ktorým má zamestnanec prístup.

## 3. Návrh — pripojenie e-shopov (krok 2)
- **Pripojenie obchodu** (nastavenie v paneli): názov, typ PrestaShop, spôsob:
  - *databáza* — obchod v tej istej databáze (dnešný stav, rýchle čítanie objednávok),
  - *Webservice API* — ľubovoľný PrestaShop: URL + API kľúč (uložený zašifrovaný, ako Fio token).
  Firma, ktorá za obchod fakturuje, a **sklad, z ktorého obchod predáva**.
- **Sklad e-shopu = údaje v e-shope:** panel číta produkty, kombinácie a ich množstvá (`stock_availables`) a mení ich
  priamo v e-shope (množstvo, správanie pri vypredaní, nastavenia skladu produktu). Predaj v e-shope odpisuje
  PrestaShop sám.
- Panel **zapisuje do obchodu len cez Webservice API** (nikdy priamym SQL do jeho tabuliek): sklad, nastavenia,
  stav objednávky (nová položka `order_histories` — PrestaShop pošle aj e-maily ako pri zmene v administrácii).
- Overenie na dev kópii e-shopu (webstore-dev, PrestaShop 1.7.6.7) na domácom serveri.

**Objednávky e-shopu, ktorý nie je v databáze panela (hotové 19. 11. 2026):** pripojenie bez voľby „toto je e-shop
tejto databázy“ = panel číta jeho objednávky len cez Webservice (zoznam, filtre, hľadanie, detail, platby, história),
vystaví z nich faktúru, mení stav a e-shopy priradí firme rovnako ako pri e-shope v databáze. Každá inštalácia
PrestaShopu čísluje objednávky, obchody a zákazníkov od 1 → faktúra, zákazník a priradenie e-shopu k firme si pamätajú
aj pripojenie (0 = databáza panela). Pozor: tú istú inštaláciu nepripájať oboma spôsobmi (objednávky by boli dvakrát).

**Hotové (19. 11. 2026):** množstvá, správanie pri vypredaní a umiestnenie (zoznam skladu e-shopu); nastavenia skladu
produktu a kombinácií — minimálne množstvo, nízky stav + e-mail, nákupná cena, texty dostupnosti (stránka produktu);
zmena stavu objednávky z detailu objednávky (pripojenie s voľbou „toto je e-shop, ktorého databázu panel číta“,
kontrola čísla objednávky, voliteľne e-mail zákazníkovi, oprávnenie `orders.edit`).

## 4. Zistené v e-shopoch (dev kópia databázy, 19. 11. 2026)
PrestaShop 1.7.6.7, jednoduchá správa skladu (bez pokročilej), sklad sa zdieľa v rámci **skupiny obchodov**:
AmerickeSPZ.sk + CarPlates (spoločný), Howies Hockey, ERMA. Priečinok `sklad/` na hostingu je starý sklad ModaUSA.sk
(vlastné tabuľky `stock_*`) — nesúvisí.

**Bezpečný zápis produktu cez Webservice (PrestaShop 1.7.6.7, 8.2.9 a 9.2.1, overené na kópiách e-shopu):** pred PUT
odstrániť polia len na čítanie (→ chyba 400; zoznam z `?schema=synopsis` daného e-shopu, inak `manufacturer_name`,
`quantity`, `new` — `new` pribudlo v PrestaShop 9), `associations` (inak PrestaShop preradí poradie produktov
v kategóriách — aj iných produktov), `position_in_category` a prázdny `low_stock_threshold` (prázdny = z NULL sa stane 0).
Potom PUT zmení len upravené polia. „Nenastavený“ nízky stav sa cez Webservice vrátiť nedá — vymazané pole uloží 0.
Zmena stavu objednávky = nový záznam `order_histories` (ako v administrácii; záznam má id_employee 0, kto to bol, je v
denníku zmien panela).

**Čítanie objednávok cez Webservice (overené na dev1):** pri viacerých obchodoch v jednej inštalácii vracia zoznam
objednávok a zákazníkov len obchod z kontextu (`id_shop`, predvolene hlavný obchod; „všetky obchody“ neexistuje) →
panel číta každý obchod zvlášť (súbežne) a spojí to. Jedna objednávka, jej položky, história a adresy kontext nemajú.
Zoradiť sa dá len podľa jedného poľa, zoznam nemá celkový počet, filtre sa dajú len kombinovať (A zároveň) → hľadanie
= viac dopytov naraz. Položky nemajú sadzbu DPH → počíta sa z ich súm. Pomalé (0,3–1 s na dopyt) → súbežné dopyty a
krátka vyrovnávacia pamäť (tabuľka shop_cache).

## 5. Otvorené
- Účtovanie skladu v Omege (export pohybov — formát T02/T03 je v popise KROS ImportExport) — po kroku 1.
