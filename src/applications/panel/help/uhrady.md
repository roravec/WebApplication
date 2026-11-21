# Úhrady — spárovanie platieb z banky

Panel spáruje platby z banky s faktúrami (prijaté platby) a s nákladmi (odchádzajúce platby). Nemusíte nič
odškrtávať ručne. Ten istý pohyb sa nedá zapísať dvakrát.

## Spôsob 1: stiahnuť z banky (Fio)

Ak má firma pri bankovom účte uložený **Fio token** (Nastavenia → Firmy → Bankové účty → API banky):
1. Vyberte účet, obdobie **od – do** a kliknite **Stiahnuť pohyby**.
2. Pokračujte kontrolou (nižšie).

> Fio dovolí sťahovať raz za 30 sekúnd. Pohyby staršie ako 90 dní treba najprv odomknúť v internetbankingu Fio
> (Nastavenia → API → zámok pri tokene, platí 10 minút). Ak Fio hlási neaktívny token, panel vypíše, čo skontrolovať.

## Spôsob 2: nahrať výpis

1. V internetbankingu si stiahnite výpis vo formáte **CAMT.053 (XML)** — vo Fio banke „SBA XML“.
2. **Výpis (XML)** → vyberte súbor → **Načítať výpis**.

## Kontrola pred zápisom

- Každý pohyb má navrhnutý doklad (podľa **variabilného symbolu**, pri nákladoch aj podľa účtu dodávateľa a sumy).
- Zaškrtnuté riadky sa zapíšu. Doklad môžete zmeniť alebo zvoliť **— nepárovať —**.
- Pri odchádzajúcej platbe bez dokladu (napr. poplatok banky) zvoľte **+ nový náklad** a kategóriu.
- Upozornenia: „Suma sa nezhoduje …“ — skontrolujte; menšia suma sa zapíše ako čiastočná úhrada.
- **Zapísať zaškrtnuté**.

Návod v skratke: [Ako spárovať platby](help:ako-sparovat-platby).
