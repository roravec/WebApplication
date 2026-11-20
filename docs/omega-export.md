# Export faktúr do Omegy (KROS)

Stav: hotové 19. 11. 2026 (panel → Faktúry → **Export do Omegy**). Kód: `src/applications/panel/src/Invoicing/OmegaExport.php`.

## Ako to použije účtovník
1. V paneli: Faktúry → Export do Omegy → firma, obdobie (podľa dátumu vystavenia), „len ešte neexportované“ → stiahne sa `.txt`.
2. V Omege: **Firma → Import → Import z textového súboru** → vybrať súbor. Omega po importe vytvorí vedľa súboru `.LOG`
   s výsledkom.

Pri každom doklade sa zapamätá, kedy bol exportovaný (`invoice.omega_exported_at`), takže sa omylom nenaimportuje dvakrát.

## Nastavenie pri firme (Firmy → firma → Export do Omegy)
| Nastavenie | Predvolené | Poznámka |
|---|---|---|
| Formát (verzia KROS) | 20.60 | nová verzia formátu sa pridá vedľa starej (`OmegaExport::VERSIONS`), prepne sa tu |
| Kód evidencie faktúr | `OF` | odberateľské faktúry v Omege |
| Kód evidencie dobropisov | `OD` | odoslané dobropisy |
| Kód číselného radu faktúr / dobropisov | prázdne | číselný rad v Omege, ak ho účtovník chce (max 5 znakov) |
| Desatinný oddeľovač | bodka | musí sedieť s Omegou / Windows u účtovníka |
| Pridať odberateľov-firmy do partnerov | áno | súkromné osoby sa do partnerov nepridávajú |

**Účtovník len overí** tieto hodnoty a prvý import skúsi na jednom mesiaci (výsledok je v `.LOG` súbore).

## Formát (oficiálny popis KROS)
Zdroj: KROS, „ImportExport 20.60“ —
`http://ftpkros.sk/files/documents/general/omega/2021/FAQ/ImportExport_20_60.xls` (odkazuje naň
`http://ftpkros.sk/files/documents/general/omega/2021/FAQ/Import_a_export.pdf`), hárky „Vseobecne“, „Fakturacia“, „Partneri“.

- Hodnoty oddelené **TAB**, každá veta na jednom riadku ukončenom **CRLF**, text **Windows-1250**, dátumy `DD.MM.RRRR`.
- Prvý riadok `R00`. Blok `R00 T04` = partneri (veta `R01` na partnera), blok `R00 T01` = fakturácia: `R01` hlavička
  dokladu, za ňou `R02` položky až po ďalšie `R01`/`R00`.
- Sadzby DPH na položke: `0` nulová, `N` nižšia, `V` vyššia, `X` neobsahuje DPH.

Použité stĺpce `R01` (číslo stĺpca podľa KROS): 2 číslo dokladu · 3 odberateľ · 4 IČO · 5 dátum vystavenia · 6 splatnosť ·
7 DUZP (dátum dodania) · 8–11 základ nižšia / vyššia / 0 / neobsahuje · 12–13 sadzba nižšia / vyššia · 14–15 DPH nižšia /
vyššia · 18 typ dokladu (0 faktúra, 4 dobropis) · 19 kód evidencie · 20 kód číselného radu · 25–28 ulica, PSČ, mesto, DIČ ·
33 dobropis: číslo pôvodnej faktúry · 34 číslo objednávky · 35 vystavil · 38 forma úhrady · 40–42 mena, množstvo, kurz ·
43 suma spolu · 45 poznámka (pri dobropise aj dôvod) · 47 štát · 48–49 IČ DPH (kód štátu + číslo) · 56–57 SWIFT, IBAN
dodávateľa · 58–60 IČ DPH a štát dodávateľa · 61–62 zaokrúhlenie (-2, prirodzene) · 71 variabilný symbol.

`R02`: 2 názov · 3 množstvo · 4 MJ · 5 jedn. cena bez DPH · 6 sadzba (0/N/V/X) · 10 typ položky `V` (voľná).
Partner `R01` (T04): 2 meno · 3 IČO · 4–6 adresa · 8 štát · 13 platiteľ DPH T/F · 14 e-mail · 25–26 IČ DPH · 27 DIČ · 32 občan F.

Dobropis sa exportuje ako typ 4 s **kladnými** sumami (typ dokladu určuje znamienko) — tak to robia aj iné programy,
ktoré do Omegy exportujú (Verteco Peppol konektor). Identifikátory (číslo, IČO, DIČ, VS) sa nikdy neskracujú: doklad,
ktorý sa nezmestí do dĺžky poľa, sa neexportuje a panel vypíše prečo.

## Obmedzenie — tri sadzby DPH od 2025
Formát 20.60 pozná len **dve** sadzby (nižšiu a vyššiu). Na Slovensku sú od 1. 1. 2025 sadzby 23 %, 19 % a 5 %. Doklad,
ktorý má položky s tromi rôznymi sadzbami, panel neexportuje (vypíše chybu). Pre neplatiteľa DPH (ERMA dnes) to nevadí —
všetko ide ako „neobsahuje DPH“. KROS má pre doklady od 2025 novší popis „ImportExport 28.00“, jeho stránka
(akademia.kros.sk, „Import a export údajov z iného softvéru pre doklady od roku 2025“) však 19. 11. 2026 vracala chybu 404.
Keď bude firma platiteľ DPH: získať 28.00 (od KROS alebo účtovníka — stačí aj vzorový export z jeho Omegy cez
Firma → Export → Export do textového súboru) a doplniť tretiu sadzbu.
