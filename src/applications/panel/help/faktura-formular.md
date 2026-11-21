# Nová faktúra a úprava konceptu

Formulár faktúry (aj dobropisu). Uloží sa ako **koncept** — ten sa dá meniť, kým ho nevystavíte.

## Postup

1. **Dodávateľ** — firma, ktorá faktúru vystavuje (ak ich máte viac). **Číselný rad** a **Vystavil**.
2. **Dátumy**: vystavenia, **dodania** (podľa neho sa určí DPH) a **splatnosti**.
3. **Forma úhrady** (prevod, karta, dobierka, hotovosť), **Bankový účet**, **Mena**.
   Pri karte a hotovosti sa faktúra pri vystavení rovno označí ako uhradená.
4. **Odberateľ**:
   - začnite písať do poľa **Hľadať v adresári odberateľov** (meno, IČO, e-mail) a vyberte — údaje sa vyplnia,
   - alebo vyplňte ručne; pri firme **IČO → Doplniť z registra**,
   - **Uložiť / aktualizovať v adresári odberateľov** — uloží odberateľa na nabudúce.
5. **Položky**: názov, kód, množstvo, jednotka, **cena za jednotku bez DPH** (pri neplatiteľovi DPH je to konečná
   cena), sadzba DPH (pri platiteľovi). **+ Položka** pridá riadok, **✕** ho odstráni. Súčty sa počítajú hneď.
6. **Zľava**: pod položkami zadajte číslo, zvoľte **% z položiek** alebo **€** a kliknite **Pridať zľavu** —
   pridá sa riadok so zápornou sumou. Návod: [Ako dať zľavu na faktúre](help:ako-zlava-na-fakture).
7. **Poznámka na doklade** (tlačí sa) a **Interná poznámka** (netlačí sa).
8. **Uložiť koncept** — alebo **Uložiť a vystaviť**, ak je všetko hotové.

> Po vystavení dostane faktúra číslo a **nedá sa už meniť ani zmazať** — chyba sa opravuje dobropisom.
> Panel pred vystavením skontroluje povinné údaje a ukáže, čo chýba.

## Dobropis

Dobropis vznikne z vystavenej faktúry (tlačidlo **Dobropis (oprava)** v detaile faktúry). Množstvá sú záporné,
**Dôvod opravy** je povinný a tlačí sa.
