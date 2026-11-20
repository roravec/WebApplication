# Správa firmy v paneli — špecifikácia

Majiteľ 20. 11. 2026: „Premysli to poriadne a sprav prepracovaný systém na správu firmy a celkovo veci okolo nej.“
Nadväzuje na fakturáciu (`fakturacia-specifikacia.md`), sklad (`sklad-specifikacia.md`) a legislatívu
(`legislativa.md`). Toto je **manažérsky pohľad pre majiteľa**. Oficiálne účtovníctvo ostáva u účtovníčky (Omega);
panel jej údaje posiela (mesačný balík) a nikdy ich nemení.

## Zásady

- **Jedno miesto, žiadne prepisovanie.** Tržby sa berú z objednávok e-shopov a faktúr panela, náklady z prijatých
  dokladov a bankových výpisov, nákupné ceny z e-shopu alebo zo skladu panela. Nič sa nezadáva dvakrát.
- **Viac firiem.** Všetko sa počíta za firmu a v rámci nej za e-shop (stredisko). Podľa práva `companies` vidí
  zamestnanec len svoje firmy.
- **Režim DPH firmy rozhoduje.** Platiteľ: tržby aj náklady bez DPH (DPH nie je jeho príjem ani náklad).
  Neplatiteľ: tržby s DPH (celá suma je príjem) a náklady s DPH (DPH si neodpočíta). Režim sa berie z obdobia
  v Firmy → DPH k dátumu dokladu, takže prechod na platiteľa v strede roka sa počíta správne.
- **Odhad je vždy označený.** Keď chýba skutočný údaj (nákupná cena položky, cena dopravy), použije sa nastavený
  odhad a v prehľade je to vidieť (napr. „bez nákupnej ceny: 12 položiek“).
- **Všetko sa dá exportovať** (CSV pre Excel) a každé číslo sa dá rozkliknúť až na objednávku alebo doklad.
- **Zákonné lehoty:** prijaté doklady sa archivujú 10 rokov ako vystavené faktúry; v paneli sa nedajú zmazať,
  iba stornovať s dôvodom (história zmien v audite).
- **Zápis do e-shopov iba cez Webservice** (napr. zmena nákupnej ceny produktu), nikdy priamo SQL.

## Moduly

| # | Modul | Čo robí | Stav |
|---|---|---|---|
| A | **Predaje a marža** | tržby, zľavy, doprava, nákup tovaru, hrubá marža po mesiacoch, e-shopoch, produktoch, kategóriách; top produkty; priemerná objednávka; produkty bez nákupnej ceny | **fáza 1** |
| B | **Nákupné ceny** | zoznam produktov bez nákupnej ceny (predané za 12 mesiacov / kedykoľvek / všetky), doplnenie po jednom, „doplniť do prázdnych“, import „kód;cena“ z Excelu → do e-shopu cez Webservice (vo všetkých obchodoch produktu); spätne sa použije ako *odhad*; nastavenie firmy „pripočítať DPH k nákupným cenám“ pre neplatiteľa | **hotové** |
| C | **Prijaté doklady (náklady)** | nahratie PDF/fotky, dodávateľ (z registra RPO podľa IČO), dátumy, sumy, DPH, kategória, firma + e-shop, splatnosť, úhrada (párovanie s Fio výpisom), opakované náklady (nájom, predplatné), archív 10 rokov, idú aj v mesačnom balíku účtovníčke | **hotové** (C1 doklady, súbory, storno, kategórie, balík účtovníčke; C2 úhrady z výpisu, opakované náklady) |
| D | **Náklady objednávky** | nastaviteľné sadzby: doprava podľa dopravcu, poplatok platobnej brány (% + pevná suma) podľa platby, balné na objednávku; neskôr skutočné sumy z faktúr Packety a výpisov GoPay | **hotové** (pravidlá + príspevok na krytie; skutočné faktúry = Náklady, porovnanie vo fáze 5) |
| E | **Výsledok a peniaze** | výsledok mesiaca (tržby − tovar − náklady objednávok − réžia = zisk) za firmu a e-shop, porovnanie s minulým mesiacom a rokom; cash flow z výpisov; pohľadávky (neuhradené vystavené) a záväzky (neuhradené prijaté) so splatnosťou; sledovanie obratu pre registráciu DPH | **hotové** |
| F | **Nástenka** | kľúčové čísla mesiaca, čo treba urobiť (neuhradené po splatnosti, doklady bez kategórie, produkty bez nákupnej ceny, objednávky bez faktúry), mesačný súhrn e-mailom majiteľovi | **hotové** |
| G | Neskôr | úlohy (Asana/Trello), rozpočty a ciele, hodnota zásob, mzdy | — |

## Definície výpočtov (modul A, E)

- **Tržby z tovaru** = súčet riadkov objednávok (po vrátení: počet − vrátené) — bez DPH pri platiteľovi, s DPH pri
  neplatiteľovi. Neplatné objednávky (zrušené, neuhradené platby kartou) sa nerátajú (`valid = 0`).
- **Zľavy** = zľavy objednávky (kupóny, pravidlá košíka). **Doprava** = poplatok za dopravu, ktorý zaplatil zákazník.
- **Nákup tovaru** = počet × nákupná cena **z času objednávky** (PrestaShop ju ukladá k riadku objednávky). Ak je
  nulová: aktuálna nákupná cena produktu/kombinácie (označené *odhad*); ak ani tá nie je: 0 a riadok je *bez nákupnej
  ceny*. Pri neplatiteľovi podľa nastavenia firmy (Firmy → Výsledky): tak, ako je zadaná, alebo + základná sadzba DPH
  platná v deň objednávky (Legislatíva → sadzby).
- **Hrubá marža** = tržby z tovaru − zľavy − nákup tovaru; **% marže** = hrubá marža / (tržby − zľavy).
- **Príspevok na krytie** (fáza 4) = hrubá marža + doprava − náklady na dopravu − poplatky platieb − balné.
- **Zisk** (fáza 5) = príspevok na krytie (všetkých e-shopov) + ostatné tržby (faktúry mimo e-shopov) − réžia
  (prijaté doklady podľa dátumu dodania, rozpočítané na mesiac).

## Dátový model (nové tabuľky panela)

- fáza 2: žiadna nová tabuľka — doplnená cena ide rovno do e-shopu a prehľad ju pri starších objednávkach použije ako
  odhad (aktuálna nákupná cena produktu / kombinácie). Nastavenie firmy `export_settings.costs.purchase_vat`.
- fáza 3: `expense` (firma, e-shop/stredisko, dodávateľ, číslo dokladu, dátumy vystavenia / dodania / splatnosti,
  mena, sumy bez DPH / DPH / spolu, kategória, stav, súbor, poznámka, opakovanie), `expense_file` (blob + sha256),
  `expense_category` (názov + druh **tovar / náklady na objednávky / réžia** — určuje miesto vo výsledku; 12 predvolených,
  nemažú sa, len vypínajú), `accounting_package.expense_ids` (čo išlo účtovníčke). Hotové v C1 (migrácia 013).
  C2 (migrácia 014): `expense.bank_ref` (pohyb v banke, ktorým bol doklad uhradený — unikátny, dá sa použiť raz),
  `expense_recurring` (opakované náklady: šablóna dokladu, interval 1/3/6/12 mesiacov, deň v mesiaci, ďalší dátum, koniec).
- fáza 4 (migrácia 015): `order_cost_rule` (e-shop alebo všetky, druh doprava / platba / balné, dopravca = `id_reference`
  z `ps_carrier` — nemení sa pri úprave dopravcu v PrestaShope, platba = `ps_orders.module`, pevná suma + % zo sumy
  objednávky, platnosť od–do, poznámka).

## Práva

Nové: **Prehľady — predaje a marža** (`reports.view`), **Náklady — prezeranie** (`expenses.view`), **Náklady —
zadávanie a úhrady** (`expenses.edit`). Správca má všetko.

## Stav

- 2026-11-20: návrh. **Fáza 1 (modul A) hotová:** menu **Výsledky → Predaje a marža** (`/reports/sales`, právo
  „Výsledky — predaje, marža, prehľady firmy“): obdobie (predvolené posledných 12 mesiacov, tlačidlá Tento mesiac /
  Tento rok), výber e-shopov (len firiem, ku ktorým má zamestnanec prístup), pohľad podľa mesiaca / e-shopu /
  kategórie / produktu, súhrn (tržby po zľavách, hrubá marža a %, doprava, priemerná objednávka, presnosť), graf po
  mesiacoch, tabuľka, najziskovejšie produkty, predané bez nákupnej ceny, CSV pre Excel. Kód: `src/Reports/SalesReport.php`
  (čítanie tabuliek e-shopu tejto databázy; e-shopy len cez Webservice zatiaľ nie), `Controller/ReportsController.php`,
  `views/reports/sales.php`; test „sales & margin report“. Overené na dev (99 objednávok: Howies marža 27 %,
  AmerickeSPZ 16 položiek bez nákupnej ceny).
- **Fáza 2 (modul B) hotová:** Výsledky → Nákupné ceny (`/reports/purchase-prices`; zobraziť = `reports.view`, ukladať =
  `stock.edit` + pripojenie e-shopu „táto databáza“ s Webservice kľúčom). Ukladanie po dávkach (~20 s na požiadavku,
  stránka pokračuje sama), každá zmena v audite. Overené: zápis do e-shopu dev1 (produkt aj obchod), import s neznámym
  kódom odmietnutý, test „sales & margin report“ rozšírený (zoznam, filtre, +DPH).
- **Fáza 3, časť C1 hotová:** menu **Náklady** (`/expenses`; zobraziť = `expenses.view`, zadávať = `expenses.edit`):
  - zoznam s filtrami (firma, kategória, stredisko, stav vrátane *po splatnosti*, dátum dodania, hľadanie), súčty
    (spolu, základ, DPH, neuhradené), označenie dokladov bez súboru, CSV;
  - formulár: dodávateľ (výber z už použitých dodávateľov doplní IČO, adresu, IBAN, kategóriu, úhradu; alebo z registra
    RPO podľa IČO), typ (faktúra, pokladničný blok, dobropis, iný), číslo, VS, dátumy vystavenia / **dodania** (mesiac
    nákladu) / splatnosti, suma spolu + odpočítateľná DPH, mena, spôsob úhrady (kartou a v hotovosti = uhradené),
    kategória, stredisko (e-shop), súbor (PDF, fotka z mobilu, XML e-faktúra; max. 10 MB, typ sa overí podľa obsahu),
    „Uložiť a zadať ďalší“;
  - kontrola duplicity (ten istý dodávateľ + číslo dokladu) — uloží sa až po potvrdení „Je to iný doklad“;
  - detail: náhľad PDF/fotky, ďalšie súbory, „Označiť ako uhradené“ (dátum + poznámka), zrušenie úhrady, **storno
    s dôvodom** (nič sa nemaže — archív 10 rokov), „Ďalší od tohto dodávateľa“, história zmien;
  - Náklady → Kategórie: premenovanie, druh, vypnutie, nové;
  - **mesačný balík účtovníčke** obsahuje aj prijaté doklady: priečinok `prijate/` so súbormi + `prijate-doklady-RRRR-MM.csv`;
    doklad zadaný neskôr za starší mesiac ide v najbližšom balíku; doklad bez súboru = upozornenie v balíku.
  - Pravidlo DPH: neplatiteľ (Firmy → Režim DPH k dátumu dodania) si DPH neodpočítava → uloží sa 0, náklad = celá suma.
    Dobropis od dodávateľa sa zadáva ako na doklade a uloží sa záporne (znižuje náklady).
  - Kód: `src/Expenses/Expenses.php`, `Controller/ExpensesController.php`, `views/expenses/*`, `sql/013_expenses.sql`,
    `Invoicing/AccountingPackage::withExpenses`; test „received documents (Náklady)“; overené na dev (uloženie s PDF,
    duplicita, validácia, úhrada, storno, CSV, stránka Pre účtovníka).
- **Fáza 3, časť C2 hotová:**
  - **Import výpisu** (Faktúry alebo Náklady → Import výpisu; CAMT.053 alebo stiahnutie z Fio): odchádzajúce platby sa
    spárujú s neuhradenými nákladmi podľa **VS** (prednostne s rovnakou sumou), inak podľa **IBAN dodávateľa + sumy**;
    pohyb bez dokladu (poplatok banky, platba kartou) sa dá zapísať ako **nový náklad** s kategóriou (uhradený, bez
    súboru — v zozname „bez súboru“). Ten istý pohyb sa nedá použiť dvakrát; zrušenie úhrady ho uvoľní. Prijaté platby
    sa párujú s faktúrami ako doteraz. Kto má len `expenses.edit`, vidí len odchádzajúce, len `invoices.edit` len prijaté.
  - **Opakované náklady** (Náklady → Opakované, alebo „Nastaviť opakovanie“ na doklade): mesačne / štvrťročne /
    polročne / ročne, deň v mesiaci (posledný deň ostáva posledným), splatnosť v dňoch, koniec. Panel v deň dokladu sám
    vytvorí neuhradený náklad bez čísla (kartou = uhradený) s poznámkou „doplňte číslo dokladu a súbor“; zmeškané
    obdobia dobehne (max. 24 naraz). Spúšťa sa pri otvorení Nákladov, nástenky (spolu s automatickým balíkom
    účtovníčke — najprv náklady, potom balík), cron URL a pri ručnom odoslaní balíka. Bezpečné pri súbežných
    požiadavkách (najprv sa posunie dátum, potom vznikne doklad).
  - Kód: `Expenses::matchPayment/payFromBank/createFromBank/generateRecurring/addMonths`, `BankImportController`,
    `ExpensesController::recurring*`, `views/expenses/recurring*.php`, `views/invoices/bank-review.php`; test
    „received documents C2“; overené na dev.
- **Fáza 4 (modul D) hotová:** Výsledky → **Náklady objednávok** (`/reports/order-costs`; zobraziť = `reports.view`,
  meniť = `expenses.edit`; pravidlo pre „všetky e-shopy“ len správca): tabuľka pravidiel — druh, e-shop, dopravca /
  platba (zoznamy z e-shopu: aktívni dopravcovia, potom zrušení; platby použité za 2 roky), € za objednávku, % zo sumy,
  platí od–do, poznámka, zmazanie. Pre každý druh platí najkonkrétnejšie platné pravidlo (e-shop + dopravca/platba >
  dopravca/platba > e-shop > všetky; pri zhode novšie „od“). Zmena ceny = staré pravidlo „do“, nové „od“.
  **Predaje a marža** potom ukazujú **príspevok na krytie** = hrubá marža + doprava zaplatená zákazníkom − doprava −
  poplatky za platby − balné (karta so súhrnom, stĺpce pri pohľade podľa mesiaca / e-shopu, súčet pri produktoch
  a kategóriách, CSV, počet objednávok bez ceny dopravy s odkazom na doplnenie). Bez pravidiel sa príspevok nepočíta
  (karta vyzve na nastavenie). Kód: `src/Reports/OrderCosts.php`, `SalesReport::compute(…, $orderCosts)`,
  `ReportsController::orderCosts*`, `views/reports/order-costs.php`; test „order costs (module D)“; overené na dev
  (1 179 objednávok, CSV).
- **Fáza 5 (modul E) hotová** (migrácia 016):
  - **Výsledky → Výsledok firmy** (`/reports/result`, firma + rok; menu Výsledky sem vedie): karty zisk / tržby / náklady
    s porovnaním s minulým rokom za rovnaké obdobie; tabuľka po mesiacoch: tržby e-shopov (tovar po zľavách + doprava),
    ostatné tržby (vystavené faktúry a dobropisy, ktoré nie sú k objednávkam e-shopov tejto databázy — tie sú už v
    tržbách e-shopu), predaný tovar (nákupné ceny predaných kusov), náklady objednávok (**skutočné** = prijaté doklady
    druhu „náklady na objednávky“, ak za mesiac nejaké sú, inak **odhad** z pravidiel D — označené), réžia (doklady
    druhu réžia, podľa dátumu dodania), zisk, zisk minulý rok; nákup tovaru z dokladov len informatívne (zásoba, nie
    náklad). E-shopy: tržby, marža, príspevok, náklady so strediskom e-shopu, výsledok e-shopu. Odhad vs. skutočnosť
    nákladov objednávok po mesiacoch. Upozornenie na doklady v inej mene (nesčítavajú sa) a na tovar bez nákupnej ceny.
    Kód `Reports\BusinessResult`.
  - **Výsledky → Peniaze** (`/reports/money`): pohľadávky (neuhradené vystavené, dobropisy na vrátenie záporne) a záväzky
    (neuhradené prijaté) podľa splatnosti (po splatnosti > 60 / 31–60 / 1–30, do 7 dní, 8–30, neskôr), zoznam po
    splatnosti a splatných do 30 dní, príjmy a výdavky po mesiacoch (zapísané úhrady faktúr / uhradené náklady) s grafom.
    Kód `Reports\Money`.
  - **Obrat pre registráciu k DPH** (neplatiteľ): hranice sú **údaje v Legislatíve** (`vat_registration_rule`: obdobie
    kalendárny rok / 12 mesiacov, hranica, okamžitá hranica, platnosť; SK: do 2024 49 790 € za 12 mesiacov, od 2025
    50 000 € za kalendárny rok a 62 500 € okamžite). Obrat = tržby e-shopov + ostatné vystavené doklady (EUR);
    úroveň ok / pozor (≥ 80 % alebo odhad za rok ≥ hranica) / nad hranicou / nad okamžitou hranicou s textom, čo robiť.
    Kód `Reports\VatTurnover`; karta vo Výsledku firmy, na nástenke a v súhrne.
- **Fáza 6 (modul F) hotová:**
  - **Nástenka**: „Treba urobiť“ (obrat k DPH blízko hranice, predané bez nákupnej ceny, neuhradené prijaté po
    splatnosti, prijaté faktúry bez čísla, doklady bez súboru, objednávky za 60 dní bez faktúry — každé len s právom na
    to) a karta každej firmy (max. 4): zisk a tržby tohto mesiaca vs. minulý rok k rovnakému dňu, obrat k DPH, odkazy.
    Kód `Reports\Todo`, `DashboardController::business()`.
  - **Mesačný súhrn e-mailom** (Firmy → Výsledky: e-mail + zapnúť): po skončení mesiaca tržby, náklady, zisk vs. minulý
    rok, od začiatku roka, pohľadávky / záväzky, obrat k DPH, čo treba urobiť, odkaz. Spúšťa nástenka a cron URL
    (`/cron/accounting`) ako balík účtovníčke; tabuľka `monthly_summary` (raz za firmu a mesiac, chyba sa skúsi znova
    po hodine). Kód `Reports\MonthlySummary`.
  - Test „company result, money, VAT registration turnover, todo, monthly summary text“; overené na dev (všetky stránky,
    súhrn doručený do Mailpitu).
- **Všetky moduly A–F sú hotové.** Neskôr (G): úlohy (Asana/Trello), rozpočty a ciele, hodnota zásob, mzdy; skutočné
  sumy Packety / GoPay automaticky (API / výpisy) namiesto ručného zadávania dokladov.
