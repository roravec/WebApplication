# Legislatíva vo fakturácii — kde je čo a ako to zmeniť

Požiadavka majiteľa (19. 11. 2026): **zákonné veci sa často menia, musia sa dať ľahko upravovať.**
Tento súbor je zoznam všetkého, čo v systéme závisí od zákona alebo od cudzích formátov — kde to je a ako sa to mení.
Pri každej novej funkcii (e-faktúra, PDF, PAY by square …) ho doplniť.

## Zásady (platia pre všetok nový kód)
1. **Čo sa mení zákonom = dáta s dátumom platnosti**, nie kód. Mení ich správca v paneli; staré hodnoty sa nemažú, len sa
   ukončia dátumom „platí do“ a pridá sa nový riadok „platí od“.
2. **Vystavený doklad sa nikdy nezmení.** Pri vystavení si uloží kópiu všetkého, čo platilo v ten deň: údaje dodávateľa,
   režim DPH, sadzby a sumy, tlačené texty (`invoice.legal_json`) a verziu tlačovej šablóny (`invoice.template`).
3. **Cudzie formáty sú verziované** (Omega, neskôr UBL/Peppol, PAY by square): nová verzia sa pridá vedľa starej a pri
   firme sa prepne; existujúca verzia sa neupravuje.
4. **Pravidlá, ktoré musia ostať v kóde, sú na jednom mieste** (uvedené nižšie) a sú pokryté testami.
5. Ak nevieme, čo presne zákon vyžaduje, dáme rozumnú predvolenú hodnotu, ktorú účtovník/majiteľ zmení v paneli.

## 1. Mení správca v paneli (bez programátora)

| Čo | Kde v paneli | Uložené v | Ako sa uplatní |
|---|---|---|---|
| Sadzby DPH (23 / 19 / 5 % …) s platnosťou od–do, pre každú krajinu | **Legislatíva → Sadzby DPH** | `vat_rate` | ponúkajú sa vo faktúre, základná je predvolená; pri vystavení (platiteľ) sa kontroluje, že sadzba platí k **dátumu dodania** |
| Texty na doklade: „Nie sme platiteľmi DPH.“, „Prenesenie daňovej povinnosti.“, oslobodenia (§ 43, § 47 …), nadpisy „Faktúra“ / „Dobropis“, odkaz dobropisu na faktúru, „Uhradené“, mena | **Legislatíva → Texty na dokladoch** | `legal_text` | platí text s najneskorším „platí od“ ≤ dátum vystavenia; pri vystavení sa zmrazí do dokladu |
| Platiteľ / neplatiteľ DPH firmy, IČ DPH, od kedy | **Firmy → firma → Režim DPH** | `company_vat` | podľa dátumu dodania |
| Údaje firmy na doklade (OR zápis, adresa, IČO, DIČ, kontakty, logo, pečiatka) | **Firmy → firma** | `company` | zmrazia sa pri vystavení |
| Číselné rady, formát čísla a VS, nulovanie, ďalšie číslo | **Firmy → firma → Číselné rady** | `series`, `series_counter` | formát použitého radu sa už nedá zmeniť (založí sa nový rad) |
| Hranica obratu na registráciu k DPH (obdobie kalendárny rok / 12 mesiacov, hranica, okamžitá hranica; SK od 2025: 50 000 € / 62 500 €, § 4 zákona o DPH) | **Legislatíva → Registrácia k DPH** | `vat_registration_rule` | Výsledok firmy, nástenka a mesačný súhrn neplatiteľa (`Reports\VatTurnover`) podľa dátumu |
| Export do Omegy: verzia formátu KROS, kódy evidencie, číselných radov, desatinný oddeľovač | **Firmy → firma → Export do Omegy** | `company.export_settings` (JSON) | pri exporte |

Zmena v paneli sa zapíše do histórie (`audit`). Formulár Legislatívy nedovolí uložiť stav bez základnej sadzby
alebo bez niektorého typu textu (ochrana pred omylom vymazaním).

## 2. Mení programátor — ale na jednom mieste

| Čo | Kde | Prečo v kóde |
|---|---|---|
| Kontroly pred vystavením (povinné údaje, platnosť sadzby DPH, IČ DPH pri prenesení / dodaní do EÚ, dobropis s dôvodom …) a upozornenia (chýbajúci zápis v OR, 15-dňová lehota platiteľa) | `Invoices::issueErrors()`, `Invoices::issueWarnings()` | je to logika; nové pravidlo = jedna funkcia + test |
| Výpočet súm a DPH (zaokrúhlenie položky, DPH zo súčtu za sadzbu), kategórie DPH S/Z/E/AE/K/G/O | `Invoicing\Calculator` | kódy kategórií sú európska norma EN 16931, nie slovenský zákon |
| Vzhľad tlačeného dokladu | `views/invoices/templates/<verzia>.php`, aktuálna v `Invoicing\Templates::CURRENT` | zmena = **kópia** šablóny pod novým menom + prepnúť `CURRENT`; starú nikdy neupravovať (vystavené doklady ju používajú) |
| Formát pre Omegu | `Invoicing\OmegaExport` (verzia 20.60), nová verzia = podtrieda + `VERSIONS` + `create()` | štruktúra súboru KROS; popis v docs/omega-export.md |
| QR platba PAY by square | `Invoicing\PayBySquare` (špecifikácia SBA 1.2.0 z 9. 10. 2025, `PayBySquare::SPEC`) | poradie polí, kompresia a kódovanie určuje SBA; od 1.2.0 je **meno príjemcu povinné**; pri novej verzii špecifikácie upraviť túto triedu + test (overiť dekódovaním) |
| E-faktúra UBL 2.1 / Peppol BIS Billing 3.0 (EN 16931) | `Invoicing\Ubl` (`VERSION`, `CUSTOMIZATION`, `PROFILE`, `SCHEME_SK = 0245`) | nová verzia Peppol BIS = nové konštanty / podtrieda; každú zmenu overiť oficiálnymi pravidlami (validátor peppol.helger.com, len so syntetickými dátami); identifikátor 0245:DIČ overiť v PASR pri výbere poskytovateľa |
| PrestaShop Webservice | `Shop\PrestaShopApi`, `Shop\ShopStock` | REST/XML PrestaShopu 1.7/8; pri novej verzii PrestaShopu overiť na kópii e-shopu (dev1/dev2) |
| Fio API (sťahovanie pohybov) | `Invoicing\Fio` | dokumentácia Fio „API Bankovnictví“ 16. 10. 2025; pohyby za obdobie vo formáte **JSON** (`Fio::parseJson`, stĺpce podľa kap. 5.3) — formát SBA XML Fio pri `/periods/` nepodporuje (HTTP 500, kód 6420) |
| Bankový výpis CAMT.053 (ISO 20022) | `Invoicing\BankStatement` | verzie .001.02–.08; pri zmene formátu bánk upraviť parser + test (`tests/fixtures/camt053-sample.xml`) |
| Register právnických osôb (RPO) | `Invoicing\Rpo`, `HttpClient` | API ŠÚ SR, licencia CC BY 4.0 (zdroj sa zobrazuje); pri zmene API upraviť `map()` + test |
| Archív PDF vystavených dokladov | `invoice_pdf` (PDF + SHA-256), `InvoiceRenderer::archive()` | úschova 10 rokov (zákon o účtovníctve § 35); uložené PDF sa nikdy nemení |
| Jediná výnimka z „vystavený doklad sa nemení“: **omylom vystavený doklad späť do konceptu** (len správca, s dôvodom; majiteľ 21. 11. 2026 — skúšobná FA2026/000001 dostala číslo, ktoré už použil e-shop). Len ak doklad nikto mimo firmy nedostal: posledný vystavený v číselnom rade (bez medzery v rade), nebol poslaný e-mailom, nie je v balíku pre účtovníka ani v exporte do Omegy, nemá dobropis ani úhradu (okrem automatickej pri karte / hotovosti). Číslo sa uvoľní, archivované PDF sa zmaže, v audite ostane číslo, dôvod a SHA-256 PDF. Inak oprava len dobropisom | `Invoices::unissueErrors()`, `Invoices::unissue()` | zmena podmienok = tam + test „issued by mistake“ |
| Mesačný balík pre účtovníka | `Invoicing\AccountingPackage` (súbor pre Omegu cez `OmegaExport`, PDF z archívu, CSV prehľady) | sám nemá zákonné pravidlá — formát Omegy sa mení len v `OmegaExport` (verzie); do balíka idú archivované PDF, takže účtovník dostane presne vystavené doklady |
| Prijaté doklady (Náklady): odpočet DPH len platiteľ (podľa `company_vat` k dátumu dodania, inak DPH = 0), dobropis dodávateľa záporne, nič sa nemaže (storno s dôvodom, súbory v `expense_file` so SHA-256 — úschova 10 rokov, § 35 zákona o účtovníctve) | `ExpensesController::save()`, `Expenses` | jedno miesto; zmena pravidla = tam + test „received documents“ |
| Typy dokladov (faktúra, dobropis; neskôr zálohová faktúra …) | `Numbering::TYPES`, `Invoices` | nový typ dokladu má vlastné pravidlá |
| Databáza | `panel/sql/NNN_*.sql` — nová migrácia, aplikuje sa sama | |

## 3. Známe body na budúcnosť
- **Omega a tri sadzby DPH:** formát 20.60 pozná len dve sadzby; pri platiteľovi DPH treba doplniť verziu 28.00
  (docs/omega-export.md).
- **E-faktúra (EN 16931 / Peppol BIS 3.0, povinná pre platiteľov od 1. 1. 2027):** robiť ako verziovaný formát
  (rovnako ako Omega), texty a sadzby brať z Legislatívy, kódy kategórií už máme podľa EN 16931.
- **PDF pri vystavení:** hotové (19. 11. 2026) — PDF sa uloží pri vystavení s kontrolným súčtom SHA-256.
- **Šablóna `invoice-2026`** bola 19. 11. 2026 upravená priamo (ešte nebola vystavená žiadna ostrá faktúra); od prvej
  ostrej faktúry platí: zmena vzhľadu = nová kópia šablóny.
- **Šablóna `invoice-2026-2`** (21. 11. 2026, `Templates::CURRENT`): bližšie k vzoru majiteľa (ermavzor) + všetko, čo
  mali staré faktúry e-shopu: číslo účtu (z IBAN) a banka (Firmy → bankový účet → Banka), kódy produktov, telefón
  a e-mail odberateľa, krajina, pri faktúre z objednávky logo / web / e-mail / telefón e-shopu (PS_LOGO_INVOICE,
  PS_SHOP_EMAIL, PS_SHOP_PHONE) a texty `delivery_note` („slúži aj ako dodací list“) a `thanks_shop` („Ďakujeme za nákup
  na {shop}“) — oba v Legislatíve → Texty (migrácia 018). Faktúry vystavené so starou šablónou ostávajú (archív PDF).
- **E-shopy (sklad v e-shope, nastavenia produktu, stav objednávky, objednávky cez Webservice):** neobsahujú zákonné
  pravidlá — e-maily pri zmene stavu a ich texty sú v e-shope (PrestaShop), nie v paneli. Faktúra z objednávky
  vzniká rovnako bez ohľadu na to, odkiaľ sa objednávka číta (`Invoices::createFromOrder`); sadzbu DPH položky pri
  čítaní cez Webservice panel dopočíta zo súm — koncept treba pred vystavením skontrolovať (ako doteraz).
- **E-shop nesmie vystavovať vlastné faktúry** (jedna faktúra = jedno číslo z panela): pri nasadení vypnúť faktúry v
  PrestaShope (HANDOVER, úloha nasadenia).
- Predvolené texty a sadzby sú návrh — **overiť s účtovníkom** (najmä odkazy na paragrafy pri oslobodení).

## Kontrola vykonaná 19. 11. 2026
Prejdený celý kód fakturácie. Napevno v kóde boli: predvolená sadzba 23 %, texty „Nie sme platiteľmi DPH.“, „Uhradené“,
nadpisy a odkaz dobropisu v šablóne, jedna verzia formátu Omega, tlač vystavených dokladov aktuálnou šablónou;
chýbali texty pre prenesenie daňovej povinnosti a oslobodenia a kontrola platnosti sadzby k dátumu. Všetko presunuté do
dát / verziované podľa zásad vyššie (migrácia `003_legislation.sql`), pokryté testami (`tests/PanelInvoicingTest.php`).
