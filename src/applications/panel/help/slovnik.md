# Slovník pojmov

| Pojem | Čo to znamená |
|---|---|
| **Koncept** | Rozpracovaný doklad (faktúra, dobropis). Dá sa meniť aj zmazať, ešte nemá číslo. |
| **Vystavenie** | Doklad dostane číslo a od tej chvíle sa **nedá meniť ani zmazať** (oprava len dobropisom). |
| **Dobropis** | Opravná faktúra — vracia peniaze alebo opravuje vystavenú faktúru (záporné položky). |
| **Zálohová faktúra** | Výzva na zaplatenie vopred (zatiaľ panel nevystavuje). |
| **VS — variabilný symbol** | Číslo, podľa ktorého banka a panel spárujú platbu s faktúrou. Panel ho robí z čísla faktúry. |
| **PAY by square** | QR kód na faktúre — zákazník ho naskenuje v bankovej aplikácii a platba sa vyplní sama. |
| **E-faktúra (UBL, Peppol)** | Faktúra ako dátový súbor (XML) pre účtovné programy a systém e-faktúr. |
| **Omega** | Účtovný program účtovníčky (KROS). Panel pre ňu robí súbor na import. |
| **Balík pre účtovníka** | Mesačný e-mail účtovníčke: súbor pre Omegu, PDF dokladov, prehľad úhrad. |
| **Webservice** | Rozhranie PrestaShopu, cez ktoré panel mení veci priamo v e-shope. Potrebuje kľúč. |
| **Stav objednávky** | Napr. „Platba akceptovaná“, „Zaplatená a odoslaná“. Nastavuje sa v e-shope — niektoré stavy posielajú zákazníkovi e-mail. |
| **Kombinácia** | Varianta produktu v e-shope (veľkosť, farba …) s vlastným skladom. |
| **Akcia (zľava)** | Dočasná znížená cena produktu v e-shope. |
| **Nákupná cena** | Za koľko produkt kupujete — z nej sa počíta marža. |
| **Marža / príspevok na krytie** | Koľko zarobíte na tovare; príspevok navyše odráta dopravu, poplatky za platby a balné. |
| **Správca** | Človek, ktorý v paneli smie všetko, vo všetkých firmách a e-shopoch (aj v moduloch, ktoré pribudnú). |
| **Rola** | Pomenovaná sada práv pre jednu prácu (napr. Expedícia). Zamestnanec môže mať viac rolí — [Roly a práva](help:roly-a-prava). |
| **Výnimka (práva)** | Jedno právo navyše alebo zakázané konkrétnemu človeku, popri jeho rolách. |
| **Prepojenie** | Väzba medzi dvoma záznamami (napr. objednávka ↔ doklad za dopravu) — [Komentáre, prepojenia](help:komentare-a-prepojenia). |
| **Krátky odkaz / kód záznamu** | Napr. `invoice:15` — otvorí záznam (aj z QR štítku); v komentári sa z neho stane odkaz. |
| **Kanban** | Tabuľa so stĺpcami (stavmi) a kartičkami úloh, ktoré sa presúvajú myšou — [Projekty](help:projekty). |
| **Číslo úlohy** | Kľúč projektu + poradové číslo, napr. `LED-12`; osobné úlohy `U-5`. Píše sa aj na začiatok commitov. |
| **Commit** | Uložená zmena v gite (kód, firmvér). Panel ho ukáže pri úlohe, ak správa začína číslom úlohy. |
| **Prijatý doklad (náklad)** | Faktúra alebo blok od dodávateľa — zadáva sa do Nákladov. |
| **Storno** | Zrušenie prijatého dokladu s dôvodom (nemaže sa, ostáva v histórii). |
| **Šarža / dávka** | Časť zásoby prijatá naraz za jednu cenu; vydáva sa od najstaršej (FIFO). |
| **Príjemka / výdajka / prevodka** | Skladové doklady: príjem na sklad, výdaj zo skladu, presun medzi skladmi. |
| **Číselný rad** | Pravidlo číslovania dokladov (napr. FA2026/000216). |
| **Platiteľ DPH** | Firma registrovaná pre DPH — na faktúrach účtuje DPH. ERMA Group zatiaľ nie je. |
