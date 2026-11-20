# Fakturácia v ERMA systéme — požiadavky

Stav: návrh na pripomienkovanie (majiteľ, účtovník). Posledná úprava: 18. 11. 2026.

## 0. Rozhodnutia majiteľa (18. 11. 2026)

- **Robí sa nanovo a lepšie.** Súčasná fakturácia v e-shope (modul `add_faktura`) a staré číslovanie sa neriešia ani neopravujú —
  nahradí ich nový systém.
- **erma.sk = verejná stránka pre zákazníkov (frontpage).** Fakturácia a ďalšie interné veci sú v **zamestnaneckej sekcii**
  tej istej stránky (napr. `erma.sk/panel` — cesta nastaviteľná), prístup len po prihlásení.
- **Objednávky zo všetkých e-shopov sú viditeľné v ERMA systéme a faktúry sa vystavujú IBA TAM, centrálne.**
  E-shop (PrestaShop) faktúry vystavovať nebude (vypnúť faktúry v PrestaShope, odinštalovať `add_faktura` — tým zmiznú aj jeho
  4 overrides, čo uľahčí upgrade PrestaShopu).
- **Systém nie je len pre ERMA Group.** Môže ho používať viac firiem — nová firma majiteľa alebo iná firma, ktorej sa
  systém poskytne. Nič nesmie byť napevno: firmy, režim DPH (platiteľ / neplatiteľ), číselné rady, odberatelia B2C aj B2B,
  vzhľad faktúry, exporty, e-faktúra. Prvou firmou bude ERMA Group s.r.o. (dnes neplatiteľ DPH).
- Účtovník pracuje v **Omega (KROS)** — faktúry musia byť do Omegy prenosné.
- **Vystavovanie e-faktúr je plnohodnotná funkcia od začiatku** (kap. 5) — pre každú firmu, ktorá je platiteľ DPH,
  nezávisle od toho, že ERMA Group dnes platiteľ nie je.

## 1. Ciele

1. Faktúry spĺňajú slovenské predpisy (kap. 2) — pre neplatiteľa DPH dnes, pre platiteľa DPH po registrácii.
2. Jeden centrálny systém: objednávky zo všetkých e-shopov + ručné faktúry, jeden spoločný číselný rad na firmu a typ dokladu.
3. Všetko nastaviteľné, nič napevno pre ERMA Group.
4. Prenos do Omegy a e-faktúra (EN 16931 / Peppol) bez prerábania dátového modelu.

## 2. Právne požiadavky (SR)

Overiť s účtovníkom. Predpisy: zákon o účtovníctve 431/2002 Z. z. (§ 10 účtovný doklad, § 35 úschova),
Obchodný zákonník § 3a (údaje na obchodných listinách), zákon o DPH 222/2004 Z. z. (§ 71–76; od 1. 1. 2027 novela
č. 385/2025 Z. z. — e-faktúra).

### 2.1 Povinné údaje — vždy (aj neplatiteľ DPH)

| Údaj | Na vzore FA2026-000198 |
|---|---|
| Označenie dokladu a jednoznačné číslo | ✅ |
| Dodávateľ: obchodné meno, sídlo, IČO (DIČ) | ✅ |
| **Údaj o zápise v obchodnom registri** (súd, oddiel, vložka) | ❌ doplniť |
| Odberateľ: meno/obchodné meno, adresa; pri firme IČO, DIČ, IČ DPH | ✅ |
| Položky: názov, množstvo, MJ, cena; suma | ✅ |
| Dátum vyhotovenia | ✅ |
| **Dátum dodania** (uskutočnenia účtovného prípadu), ak sa líši od vyhotovenia | ❌ doplniť |
| Podpisový záznam osoby zodpovednej za účtovný prípad | ✅ Vystavil + podpis |
| Mena | ✅ EUR |
| Pri neplatiteľovi informácia „Nie sme platiteľmi DPH“ (zaužívané) | ✅ |

### 2.2 Navyše pre platiteľa DPH (§ 74 ods. 1)
IČ DPH dodávateľa; dátum dodania alebo prijatia platby vopred; jednotková cena bez DPH; zľavy; základ dane, sadzba
(23 % / 19 % / 5 %) a daň v eurách po sadzbách; texty „prenesenie daňovej povinnosti“, oslobodenie s odkazom a pod.;
lehota vystavenia 15 dní; pri platbe vopred doklad k platbe. Režim DPH firmy sa nastavuje **s dátumom platnosti**.

### 2.3 Pravidlá
- Číslovanie **bez medzier a bez duplicít** v rámci radu; pridelenie čísla atomicky (súbežné vystavenie nikdy nedá rovnaké číslo).
- **Vystavená faktúra sa nedá zmeniť ani zmazať.** Oprava = opravná faktúra / dobropis s odkazom na pôvodnú a dôvodom.
  Neuložený rozpracovaný doklad = koncept bez čísla.
- **Úschova 10 rokov:** PDF + dáta + kontrolný súčet (hash) PDF; záznam kto/kedy vystavil, odoslal, opravil.
- **Predaj do EÚ (OSS)**, keď bude firma platiteľ DPH: sadzby DPH podľa krajiny zákazníka.

## 3. Nastaviteľnosť

### 3.1 Firmy (dodávatelia)
Ľubovoľný počet. Každá: obchodné meno, sídlo, IČO, DIČ, IČ DPH, zápis v OR, režim DPH s históriou (od dátumu), bankové účty,
kontakty, logo, obrázok pečiatky/podpisu, predvolená splatnosť, šablóna faktúry, číselné rady, Peppol ID (neskôr), nastavenie
exportov. Používateľ vidí len firmy, ku ktorým má prístup. Každý e-shop je priradený k firme, ktorá zaň fakturuje.

### 3.2 Číselné rady
Pre každú firmu a typ dokladu (faktúra, dobropis, zálohová faktúra, …):
- formát z tokenov, napr. `FA{RRRR}/{NNNNNN}` → `FA2026/000198`, variabilný symbol napr. `{NNNNNN}` alebo `{RR}{NNNN}`;
- nulovanie každý rok áno/nie; nastaviteľné ďalšie číslo (prvé číslo nového systému určí majiteľ s účtovníkom).

### 3.3 Odberatelia
Adresár: fyzická osoba (B2C) / firma (B2B) — IČO, DIČ, IČ DPH, fakturačná a dodacia adresa, e-mail, splatnosť,
neskôr Peppol ID. Doplnenie údajov firmy podľa IČO z verejného registra (RPO). Zákazníci z e-shopov sa preberajú z objednávky.

### 3.4 Vzhľad faktúry
Šablóna HTML + CSS na firmu (a jazyk) → PDF. Bez programovania meniteľné: logo, farby, texty (slogan, päta, poznámky),
pečiatka, zobrazenie stĺpcov DPH, formát čísel a dátumov. Prvá šablóna = podľa vzoru (kap. 4).

### 3.5 Objednávky z e-shopov
- Zamestnanecká sekcia zobrazuje objednávky všetkých e-shopov (PrestaShop je v tej istej databáze — čítanie tabuliek `ps_*`,
  bez zápisu do e-shopu): zoznam, filter podľa e-shopu/stavu/dátumu, detail, stav úhrady a doručenia.
- Faktúra sa vystaví z objednávky (ručne alebo automaticky — nastavenie, viď otázky). Faktúra je naviazaná na objednávku,
  jedna objednávka nemôže dostať dve faktúry (okrem dobropisu).
- Zákazník dostane faktúru e-mailom (PDF), prípadne odkaz na stiahnutie z erma.sk.

## 4. Vzor vzhľadu (FA2026-000198.pdf od majiteľa)

A4 na výšku, akcentová farba červená (ERMA):
- **Hlavička:** logo vľavo (`brand/erma-logo-sro.png` — vodorovné logo „ERMA Group s.r.o.“; logo je nastavenie firmy),
  slogan v strede („Smart IoT solutions“), web/e-mail/telefón vpravo; pod tým červená čiara. Farby loga: červená #F74040,
  zelená #55F264, modrá #44C5E5.
- „Faktúra“ (veľké) vľavo, číslo (veľké) vpravo.
- Dva stĺpce s červeným záhlavím: **Dodávateľ** | **Odberateľ** — meno (červené, tučné), adresa, IČO, DIČ, IČ DPH / „Nie sme platiteľmi DPH.“
- **Platobné údaje** (červené záhlavie): názov účtu, IBAN, variabilný symbol, forma úhrady + **QR kód PAY by square**;
  vpravo boxy **Dátum vystavenia**, **Dátum splatnosti** (+ nový **Dátum dodania**).
- Poznámka kurzívou („Ceny sú uvedené v EUR. Nie sme platiteľmi DPH.“).
- Tabuľka položiek s červeným záhlavím: #, Názov položky, Počet MJ, Cena/MJ, DPH, Celkom, DPH celk., Cena celkom s DPH.
- Súčtový box (červený): Celková suma bez DPH, DPH, **Celkom k úhrade**.
- Vpravo dole: „Vystavil: meno“ + obrázok pečiatky s podpisom.
- **Päta:** červená čiara, malé logo, adresa a kontakty v strede, „Strana X z Y“ vpravo.

Doplniť: zápis v OR, dátum dodania. Zjednotiť údaje na pečiatke (Nedašovce, +421 949 00 11 02) s hlavičkou
(Pravotice, +421 908 533 100). Voliteľne: skryť stĺpce DPH pri neplatiteľovi, formát čísel `800,00 €`.

## 5. E-faktúra a Omega (zistené 18. 11. 2026, overiť)

### 5.1 E-faktúra na Slovensku
- Od **1. 1. 2027** (novela zákona o DPH č. 385/2025 Z. z.) musia **platitelia DPH** vystavovať a prijímať tuzemské faktúry
  (B2B, B2G) v elektronickom formáte.
- Formát: európska norma **EN 16931**, profil **Peppol BIS Billing 3.0**, syntax **UBL 2.1**. PDF ani sken nestačí.
- Doručenie cez sieť **Peppol** prostredníctvom certifikovaného poskytovateľa („digitálny poštár“), nie e-mailom.
- **Platiteľ DPH** musí e-faktúry vystavovať aj prijímať; **neplatiteľ DPH** ich nevystavuje, ale musí ich vedieť prijať.
  Systém preto podľa nastavenia firmy (režim DPH s dátumom platnosti) e-faktúru vystaví a odošle automaticky —
  firma-platiteľ ju má od prvého dňa, firma-neplatiteľ (napr. ERMA dnes) sa prepne v deň registrácie k DPH.
- Zdroj: Finančná správa — Najčastejšie otázky k eFaktúre (9/DPH/2025/IM, aktualizácia 10. 11. 2026).

### 5.2 Omega (KROS)
- Prijaté e-faktúry: KROS ich prijíma cez Peppol v aplikácii KROS (záložka eFaktúra) a do Omegy sa stiahnu cez
  „Stiahni doklady z KROSu“.
- Import vlastných dokladov do Omegy: zaužívaný je **textový import Omegy (TXT, Firma → Import)**; podpora **ISDOC** sa podľa
  zdrojov líši; natívny modul e-faktúry od KROS je ohlásený. → **Overiť u účtovníka / KROS**, ktorý import používa,
  a získať vzorový súbor + popis formátu.

### 5.3 Návrh
- Dátový model faktúry podľa **EN 16931** (všetky polia, ktoré norma pozná: strany, adresy, identifikátory, položky,
  kategórie DPH, platobné údaje, odkazy na objednávku a pôvodnú faktúru).
- Exporty ako samostatné moduly:
  1. **UBL 2.1 / Peppol BIS 3.0** — e-faktúra pre firmy-platiteľov DPH: vytvorenie, validácia podľa pravidiel Peppol BIS 3.0
     a odoslanie cez digitálneho poštára (Peppol access point) cez jeho API; poštár a prístupové údaje sa nastavujú
     **pre každú firmu** zvlášť (rôzne firmy môžu mať rôznych poštárov); stav doručenia sa eviduje pri faktúre;
  2. **Omega TXT** (podľa formátu od účtovníka) — prenos do účtovníctva;
  3. **ISDOC** (ak ho bude Omega/poštár chcieť);
  4. PDF pre zákazníka, CSV.
- Prijímanie e-faktúr (povinné pre platiteľov aj neplatiteľov) — každá firma cez svojho poštára alebo KROS; v systéme
  neskôr prípadne evidencia prijatých faktúr.

### 5.4 KROS API (zistené 20. 11. 2026, https://developer.kros.sk/)
- Verejné API je len pre cloudové **KROS Fakturácia** a **KROS Sklad** (`https://api-economy.kros.sk`, REST/JSON,
  Swagger na `/swagger`). **Omegu výslovne nepokrýva** (co-umoznuje.html) — do Omegy sa ide naďalej importom alebo cez
  KROS cloud („Stiahni doklady z KROSu“, overiť u účtovníka).
- Čo vie: vydané faktúry, dobropisy, zálohové faktúry, dodacie listy, objednávky — zápis aj čítanie; úhrady (párovanie
  podľa VS); prijaté doklady vrátane prijatých e-faktúr — len čítanie; PDF a prílohy; webhooky (zmena dokladu, úhrada,
  stav odoslania; podpis HMAC). Doklady sa cez API neupravujú ani nemažú; rovnaké číslo + rad = celý doklad sa prepíše.
- **E-faktúra cez KROS**: `POST /api/invoices` s `isEInvoice: true` → `POST /api/invoices/{id}/send`; KROS doručí cez
  Peppol (BIS Billing 3.0) — netreba vlastného certifikovaného poštára; odberateľ musí mať DIČ a firma musí mať v KROS
  zapnutú eFaktúru. Stav v `eInvoice.status` (pending, sent, confirmed, validationFailed, sendingFailed, deliveryFailed).
- Prístup: token pre firmu (KROS → nastavenia firmy → „API prepojenia“, alebo „Integration Consent“ bez registrácie
  u KROS); firma potrebuje účet KROS Fakturácia/Sklad a licenciu na API (cena podľa produktu, na webe neuvedená).
  Limity 10 req/s, 300/min, dávka 100 dokladov; zápisy asynchrónne (202 + výsledok webhookom). **Testovacie prostredie
  nie je** — skúšať na testovacej firme v KROS.
- **ERMA Group (majiteľ 20. 11. 2026):** účtovníčka ju zaregistrovala do Peppolu cez KROS → prijaté e-faktúry chodia do
  KROS (eFaktúra) a odtiaľ do Omegy; to pokrýva povinnosť neplatiteľa DPH (prijímať). Prístup k API v KROS zatiaľ
  nemá (bez licencie) → pre neplatiteľa nič netreba. Pri registrácii k DPH (povinnosť vystavovať e-faktúry): buď
  licencia na KROS API (odosielanie z panelu cez KROS, adresa v Peppol ostáva u KROS), alebo iný spôsob odoslania —
  overiť vtedy u KROS cenu licencie a či sa dá posielať cez iného poskytovateľa, keď je príjem zaregistrovaný v KROS.
- Hodí sa do návrhu ako jeden z „poskytovateľov e-faktúry pre firmu“ (kap. 5.3): poskytovateľ **KROS** = odoslanie
  e-faktúr cez Peppol + prenos vydaných faktúr a úhrad do KROS (→ Omega) + čítanie prijatých e-faktúr. Rozhodnutie
  majiteľa: či firma má / chce KROS Fakturácia (licencia na API).

## 6. Stav implementácie (19. 11. 2026)

Hotové v zamestnaneckej sekcii (`/panel`), zatiaľ na vývojovom serveri:
- **Firmy**: údaje, zápis v OR, režim DPH s dátumom platnosti, bankové účty, logo, pečiatka s podpisom, priradenie e-shopov.
- **Číselné rady**: formát z tokenov, variabilný symbol, nulovanie každý rok, nastaviteľné ďalšie číslo; číslo sa prideľuje
  pri vystavení bez medzier a bez duplicít.
- **Odberatelia**: adresár pre každú firmu zvlášť, preberanie z objednávok.
- **Faktúry a dobropisy**: koncept → vystavenie (potom sa nedá meniť ani zmazať), faktúra z objednávky jedným klikom
  (jedna objednávka = jedna faktúra), dobropis k faktúre s dôvodom opravy, história (kto, kedy).
- **Oprávnenia**: zamestnanec vidí len povolené časti a len firmy, ku ktorým má prístup.
- **Tlač** podľa vzoru (kap. 4); zatiaľ cez „Uložiť ako PDF“ v prehliadači.
- **Pre účtovníka** (20. 11. 2026): raz za mesiac jeden e-mail účtovníkovi so ZIP — súbor na import do Omegy, PDF
  všetkých dokladov, prehľad dokladov a úhrad (Excel), návod. Posiela sa automaticky (nastavenie firmy: e-mail, od
  ktorého dňa) alebo tlačidlom vo Faktúry → Pre účtovníka; dá sa aj stiahnuť. Každý doklad ide len v jednom balíku.

- **Export do Omegy** (textový import podľa oficiálneho popisu KROS) — docs/omega-export.md.

Ďalej: PDF súbor + archív, QR PAY by square, e-faktúra (UBL / Peppol), e-mail zákazníkovi, úhrady.

## 7. Otvorené otázky

1. ~~Zápis v OR, prvé číslo a formát radu~~ — **nastaviteľné v systéme, majiteľ doplní sám** (rozhodnutie 19. 11. 2026;
   systém sa na ne nepýta).
2. ~~Omega: aký import~~ — **vyriešené podľa oficiálneho formátu KROS** (textový import, docs/omega-export.md). Účtovník
   len overí kódy evidencie / číselných radov a desatinný oddeľovač pri prvom importe.
3. ~~Faktúra z objednávky automaticky alebo ručne?~~ — **ručne** (19. 11. 2026): import objednávky z PrestaShopu
   tlačidlom aj úplne ručná faktúra. E-mail zákazníkovi — zatiaľ neurčené.
4. Názov/cesta zamestnaneckej sekcie (`/panel`, `/zamestnanci`, `/intranet`…).
