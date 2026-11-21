# Úprava položiek, dopravy a zľavy objednávky

Zmena produktov, množstiev, cien, dopravcu, ceny dopravy a zľavy hotovej objednávky — priamo v e-shope.
Otvoríte v detaile objednávky tlačidlom **Upraviť položky, dopravu a zľavu**.

> **Pozor — nová funkcia.** Kým ju správca nepotvrdí ako overenú na ostrom e-shope, používajte ju len po dohode
> s ním. Ak si nie ste istí, urobte zmenu v administrácii e-shopu.

## Postup

1. **Položky**: zmeňte **Počet ks** (0 = položku odstrániť) alebo **Cenu/ks s DPH**.
2. **Pridať produkt**: začnite písať názov alebo kód a vyberte zo zoznamu. Prázdna cena = aktuálna cena v e-shope
   (aj s akciou). Počet kusov.
3. **Dopravca** a **Cena dopravy s DPH** (0 = zadarmo).
4. **Zľava objednávky**: suma v € s DPH alebo **% z ceny tovaru**. Zadávate **celkovú** zľavu — nahradí súčasnú.
   0 = bez zľavy.
5. **Upraviť aj sklad v e-shope o rozdiel** — odobraté kusy sa vrátia na sklad, pridané odpíšu (treba právo Sklad —
   úpravy).
6. **Uložiť do e-shopu**.

## Čo sa stane

- Panel zmení položky, súčty a dopravu v e-shope a zapíše zmenu do **interných poznámok** objednávky.
- Ak zákazník už zaplatil inú sumu, panel napíše, či treba **vrátiť rozdiel** alebo **doplatiť**.
- **Vystavená faktúra sa nezmení** — opravte ju dobropisom a novou faktúrou. Koncept faktúry upravte.

## Obmedzenia

- Percentuálna zľava sa pri neskoršej zmene položiek **sama neprepočíta** — zadajte ju znova.
- V administrácii e-shopu bude zľava v súčtoch objednávky, nie ako pomenovaný zľavový kupón.
- Ak sa objednávka v e-shope medzitým zmenila (niekto ju upravil inde), panel nič nezapíše a požiada vás o nové
  načítanie.
