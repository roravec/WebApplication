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
PrestaShop 1.7.6.7 (zistené pred aktualizáciou; dáta prešli do 9.2.0 bez zmeny), jednoduchá správa skladu (bez pokročilej), sklad sa zdieľa v rámci **skupiny obchodov**:
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

**Správa objednávok a e-shopu (hotové 21. 11. 2026)** — všetko cez štandardný Webservice, overené na kópiách
PrestaShop 1.7.6.7 (dev1) aj 9.2.1 (dev4); **každé volanie posiela `id_shop`** (PrestaShop 9 inak objednávku / adresu
„nenájde“):
- *Detail objednávky:* číslo zásielky (`order_carriers` PUT; PrestaShop ho skopíruje do objednávky, e-mail o odoslaní
  s odkazom na sledovanie) + voliteľne zmena stavu; **zmena dodacej / fakturačnej adresy** = nová adresa zákazníka
  (kópia so zmenami, `addresses` POST) a objednávka sa na ňu prepne (`orders` PUT — zmení sa len adresa, položky
  a sumy nie; overené porovnaním); **interné poznámky** (len v paneli); **správa zákazníkovi** e-mailom v štýle e-shopu
  (logo, meno e-shopu, adresa firmy; v zozname „E-maily zákazníkovi“); **výdajné miesto Zásielkovne** (modul
  `shaim_zasilkovna_widget`, tabuľka `ps_shaim_zasilkovna_widget_data` podľa košíka; na faktúru nie — majiteľ 21. 11.);
  **dodací list** (PDF).
- *Vrátenie tovaru:* vybrané kusy (nie viac, ako ostáva) → naskladnenie späť do e-shopu (`stock_availables`, aktuálne
  množstvo + vrátené), **koncept dobropisu len z vrátených položiek** (+ doprava; párovanie podľa kódu a názvu na
  faktúre), zmena stavu; záznam vrátenia s problémami, ak niektorý krok zlyhal. Peniaze sa vracajú v GoPay / bankou ručne.
- *Objednávky → Expedícia* (`/shipping`): objednávky v stavoch „na vybavenie“ (predvolene zaplatené a neodoslané +
  dobierka; výber sa dá zapamätať), hromadne: dodacie listy, vychystávací zoznam (súčet kusov podľa produktu), čísla
  zásielok, zmena stavu s e-mailom (max. 100 naraz).
- *Produkty a ceny* (Sklad → E-shopy → produkt): predajná cena bez DPH (zobrazí aj s DPH podľa daňového pravidla pre SK),
  aktívny áno/nie, príplatok kombinácie; **akcie** (`specific_prices`): % alebo € (s / bez DPH), od–do, kombinácia,
  od počtu kusov — pridať / zrušiť; akcie nastavené v e-shope pre skupinu / zákazníka / krajinu sa len zobrazia.
- *Úprava položiek a dopravy* (detail → „Upraviť položky a dopravu“, `/orders/…/lines`, právo `orders.edit`; majiteľ
  21. 11. 2026: **len Webservice**, žiadny modul v e-shopoch): počet kusov (0 = odstrániť), cena/ks s DPH, pridať produkt
  (výber zo zoznamu produktov / kombinácií e-shopu; prázdna cena = aktuálna cena e-shopu vrátane akcie pre krajinu
  doručenia), dopravca a cena dopravy, **zľava objednávky** (€ s DPH alebo % z tovaru, nahrádza súčasnú — mení len
  súčty objednávky, Webservice nemá `order_cart_rules`, v administrácii e-shopu nebude ako pomenovaný kupón), voliteľne
  sklad o rozdiel (právo `stock.edit`). Webservice nič neprepočíta,
  preto panel robí to isté ako administrácia PrestaShopu 1.7.6 pri úprave objednávky (`Shop\ShopOrderLines`):
  `order_details` PUT / POST (kópia existujúceho riadku s údajmi produktu) / DELETE, súčty objednávky (`orders` PUT)
  zmenené o rozdiel — zľavy, balenie a zaplatená suma ostávajú, `order_carriers` PUT (dopravca, cena dopravy, hmotnosť).
  Poistky: objednávka musí mať v e-shope presne tie položky / súčet / dopravu, z ktorých bol formulár (inak sa nič
  nezapíše), každý zápis sa kontroluje v odpovedi e-shopu, chyby sa hlásia; zmena sa zapíše do interných poznámok.
  **Obmedzenia (prijaté majiteľom):** riadky DPH (`order_detail_tax` — len pre faktúry / dobropisy PrestaShopu, ktoré
  e-shopy nevystavujú) sa neprepočítajú a nové riadky ich nemajú; percentuálne zľavy sa neprepočítajú; rezervované
  množstvo skladu prepočíta PrestaShop pri ďalšej zmene stavu; pri e-shope cez Webservice (nie „táto databáza“) sa
  zastarané skryté polia `tax_rate` / `tax_name` riadku vynulujú (PrestaShop 1.7+ ich aj tak nevypĺňa).
- *Stavy objednávok* (Objednávky → Stavy objednávok, len správca; 21. 11. 2026): stavy sa menia priamo v e-shope cez
  `order_states` — slovenský názov, šablóna e-mailu, **posielanie e-mailu** (bez neho e-shop pri zmene stavu nič nepošle),
  farba, zaplatená / odoslaná / dodací list / platná / skrytá; nový stav. Nastavenia faktúr PrestaShopu sa nemenia.
- Odovzdanie zásielok do Packety (API + štítky) — po inštalácii oficiálneho modulu Packeta.
- Kľúč Webservice potrebuje (formulár pripojenia): GET+PUT products, combinations, stock_availables, orders,
  order_carriers; GET+POST order_histories, addresses; GET+PUT+POST order_states; GET+POST+PUT+DELETE specific_prices, order_details; GET product_options,
  product_option_values, languages, shops, shop_groups, order_payments, customers, countries,
  currencies, carriers, taxes, tax_rules, tax_rule_groups, categories.
- Kód: `Shop\ShopOrderEdit`, `Shop\ShopOrderLines` (položky + doprava), `Shop\OrderLog`, `Shop\ShopStock::addQuantity`, `Shop\ShopProducts` (ceny, akcie),
  `Controller\OrderToolsController`, `ShopsController::sale*`, `views/orders/*` (message, address, return, shipping,
  delivery-note, picking-list, lines), migrácia 017; testy „order tools…“, „order lines…“, „returns…“.

## 5. Otvorené
- Účtovanie skladu v Omege (export pohybov — formát T02/T03 je v popise KROS ImportExport) — po kroku 1.
