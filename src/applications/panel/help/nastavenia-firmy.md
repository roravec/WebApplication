# Nastavenia → Firmy

Firma, ktorá vystavuje faktúry. Panel môže mať viac firiem — každá má vlastné faktúry, odberateľov, číselné rady.
Po zmenách **Uložiť firmu** (dole).

## Časti formulára

- **Údaje firmy** — obchodné meno, adresa, IČO (**Doplniť z registra**), DIČ, **zápis v obchodnom registri**
  (povinný údaj na faktúre).
- **Kontakty a faktúra** — e-mail, telefón, web, slogan (hlavička faktúry), predvolená splatnosť, farba faktúry,
  predvolená poznámka na faktúre, **Aktívna** (dá sa fakturovať).
- **Režim DPH** — od ktorého dňa je firma platiteľ / neplatiteľ DPH (**+ Obdobie DPH**). Pre faktúru platí obdobie
  podľa dátumu dodania. Bez obdobia = neplatiteľ.
- **Bankové účty** — názov, IBAN, BIC, banka (tlačí sa na faktúru), predvolený. **API banky**: token Fio banky
  (internetbanking Fio → Nastavenia → API, len na čítanie, 64 znakov) — pohyby sa potom sťahujú vo
  **Faktúry → Úhrady**. Token sa uloží zašifrovaný a už sa nezobrazí; na zmazanie napíšte `zmazať`.
- **Logo** a **Pečiatka a podpis** — PNG/JPG do 2 MB.
- **Náhľad faktúry** — **Náhľad** / **PDF** vzorovej faktúry s uloženými údajmi (po zmene najprv uložte).
- **E-shopy** — objednávky ktorých e-shopov fakturuje táto firma.
- **Export do Omegy (účtovník)** — kódy evidencie (`OF`, `OD`), číselné rady, verzia, desatinný oddeľovač — podľa
  nastavenia Omegy u účtovníčky. Ak ich nepoznáte, nechajte predvolené.
- **Pre účtovníka (mesačný balík)** — e-mail účtovníčky, **Posielať automaticky za predchádzajúci mesiac**,
  od dňa v mesiaci. Pozri [Pre účtovníka](help:pre-uctovnika).
- **Výsledky (prehľady)** — či sa k nákupným cenám pripočíta DPH (neplatiteľ si DPH neodpočíta); **mesačný súhrn
  e-mailom** majiteľovi.
- **E-mail s faktúrou** — odosielateľ, predmet a text e-mailu s faktúrou a upomienky. Zástupné znaky ako `{number}`,
  `{total}`, `{due_date}` sa nahradia skutočnými údajmi. Kým texty nezmeníte, faktúra z objednávky ide v štýle
  e-shopu (jeho logo a názov).
- **Číselné rady** — dole; návod [Číselné rady](help:ciselne-rady).
