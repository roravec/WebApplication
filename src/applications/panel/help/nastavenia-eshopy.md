# Nastavenia → E-shopy

Pripojenie e-shopov (PrestaShop) k panelu. Cez pripojenie panel mení objednávky (stav, adresy, číslo zásielky,
položky), produkty, ceny a sklad **priamo v e-shope**. Bez pripojenia sa objednávky dajú len čítať.

Pri každom pripojení:
- **Upraviť pripojenie** — adresa, kľúč, nastavenia.
- **Stavy objednávok** — [návod](help:stavy-objednavok). Stavy patria inštalácii PrestaShopu, sú spoločné pre
  všetky jej obchody.

## Nové pripojenie

Postup krok za krokom: [Ako napojiť e-shop](help:ako-napojit-eshop).

- **Názov** — ako sa pripojenie volá v paneli.
- **Adresa e-shopu** — kde beží PrestaShop (aj s podpriečinkom, napr. `https://howieshockey.sk/store/`).
- **Kľúč Webservice** — z administrácie e-shopu; uloží sa zašifrovaný. Pri úprave nechajte prázdne, ak ho nemeníte.
- **Aktívne**.
- **E-shop je v tej istej databáze ako panel** — pri našich e-shopoch na tom istom hostingu **zaškrtnúť**.
  Nezaškrtnuté len pri e-shope na inom hostingu s vlastnou databázou (inak by sa objednávky zobrazili dvakrát).
- **Overiť a uložiť** — panel sa hneď skúsi s e-shopom spojiť a vypíše jeho obchody.
