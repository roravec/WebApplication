# Nastavenia → Systém

Pre správcu (právo *Nastavenia → Systém*). Tri záložky: **Plánovač**, **Integrácie**, **API kľúče**.

## Plánovač

Úlohy, ktoré panel robí sám: odoslanie mesačného balíka účtovníčke, mesačný súhrn firmy, vytvorenie dokladov
opakovaných nákladov, e-maily s upozorneniami (hneď a ranný súhrn).

- Spúšťajú sa samé, **keď niekto pracuje v paneli** (stačí mať panel otvorený), najviac raz za minútu.
- Aby bežali aj v noci a cez víkend, nastavte v správe hostingu **cron**: každých 5–15 minút otvoriť adresu, ktorá je
  na stránke (obsahuje tajný kľúč — nikomu ju neposielajte).
- V tabuľke vidíte, kedy úloha bežala naposledy a s akým výsledkom. **CHYBA** = prečítajte text (napr. nenastavená
  pošta) a opravte príčinu. **Spustiť teraz** ju spustí hneď.

## Integrácie

Napojenia na služby mimo panela. Heslá sa ukladajú zašifrované a už sa nezobrazia.

**Nextcloud (súbory záznamov)** — postup:

1. V Nextcloude (ako správca Nextcloudu) vytvorte používateľa, napr. `erma-panel`.
2. Prihláste sa ním do Nextcloudu → **Nastavenia → Zabezpečenie → Zariadenia a relácie** → názov napr. „ERMA panel“
   → **Vytvoriť nové heslo aplikácie**. Heslo skopírujte.
3. V paneli vyplňte **Adresa Nextcloudu** (napr. `https://home.ermagroup.net`), **Používateľ** a **Heslo aplikácie**,
   **Priečinok panela** nechajte `Panel`.
4. **Uložiť a overiť pripojenie** — pri Nextcloude sa ukáže **funguje** a verzia. Odteraz majú záznamy časť
   [Súbory](help:subory).

Panel zakladá priečinky `Panel/<firma>/<druh záznamu>/<záznam>` sám. Ostatné napojenia (e-shopy, Fio, e-mail) sú
v tabuľke s odkazom, kde sa nastavujú.

## API kľúče

Zoznam všetkých API kľúčov (kto ho vytvoril, na čo, posledné volania). **zrušiť** kľúč okamžite vypne.
Ako sa kľúče vytvárajú: [API kľúče](help:api-kluce).
