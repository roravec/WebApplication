# Číselné rady

Pravidlo, podľa ktorého faktúry a dobropisy dostávajú čísla. Otvoríte vo **Nastavenia → Firmy → firma → Číselné rady**.

## Polia

- **Názov** a **Typ dokladu** (faktúra, dobropis, skladové doklady).
- **Formát čísla** — `{RRRR}` rok, `{RR}` rok dvojmiestne, `{MM}` mesiac, `{NNNNNN}` poradové číslo (počet N = počet
  číslic). Napr. `FA{RRRR}/{NNNNNN}` → FA2026/000216.
- **Variabilný symbol** — z akých číslic sa robí VS (najviac 10). Prázdne = číslice z čísla faktúry.
- **Číslovať každý rok od začiatku** — v novom roku začne rad od 1 (FA2027/000001). Odporúčané.
- **Ďalšie poradové číslo** — číslo, ktoré dostane najbližšia vystavená faktúra.
- **Predvolený rad** a **Aktívny**.

## Nadviazanie na staré číslovanie

Ak ste predtým vystavovali faktúry inde (napr. v e-shope), nastavte **Ďalšie poradové číslo = posledné staré číslo
+ 1**. Panel nedovolí vystaviť číslo, ktoré už v paneli existuje — faktúry z iných programov však nevidí, takže
správne číslo stráži len toto nastavenie.

> Keď už bol rad použitý, formát a typ sa nedajú meniť — číslovanie musí ostať jednotné.
