# API kľúče

API kľúč dovolí **programu** pracovať s panelom za vás — testovací prípravok, ktorý zapíše výsledok k záznamu,
CI (GitHub Actions), skript. Treba právo *API — vlastné API kľúče*.

## Vytvoriť kľúč

1. **Môj účet → API kľúče → Nový kľúč**.
2. **Názov** — čo ho bude používať (napr. „Testovací prípravok LedBoard“).
3. **Platnosť** — 30, 90, 365 dní alebo bez obmedzenia (radšej s obmedzením).
4. **Práva kľúča** — len z vašich práv. Bez zaškrtnutia smie kľúč čítať záznamy, ktoré vidíte, a pridávať k nim
   komentáre.
5. **Vytvoriť kľúč** → kľúč (`pk_…`) sa ukáže **iba raz**. Skopírujte ho do programu (do nastavení / tajných
   premenných CI), nikam inam.

## Pravidlá

- Kľúč pracuje **ako vy**, ale len s právami, ktoré ste mu dali — a nikdy s viac, ako máte práve teraz. Keď o právo
  prídete alebo je váš účet vypnutý, kľúč ho stratí tiež.
- Každé volanie sa zapisuje; správca ich vidí v **Nastavenia → Systém → API kľúče**.
- Najviac 120 volaní za minútu.
- Kľúč, ktorý už netreba alebo mohol uniknúť, **zrušte** (Môj účet → zrušiť). Prestane fungovať okamžite.

## Pre programátora

Adresa: `https://<panel>/api/v1/…`, hlavička `Authorization: Bearer pk_…`, odpovede JSON.

| Volanie | Čo vráti |
|---|---|
| `GET /api/v1/me` | komu kľúč patrí a aké má práva |
| `GET /api/v1/search?q=FA2026` | záznamy podľa čísla / názvu |
| `GET /api/v1/records/invoice:15` | názov a odkaz záznamu |
| `GET /api/v1/records/invoice:15/comments` | komentáre |
| `POST /api/v1/records/invoice:15/comments` s `{"body": "text"}` | pridá komentár (napr. výsledok testu) |
| `GET /api/v1/activity` | posledné zmeny (`?before=<next>` = staršie) |

Chyby: `401` neplatný / zrušený kľúč, `404` záznam neexistuje alebo k nemu kľúč nemá prístup, `422` chýbajúce údaje,
`429` priveľa volaní. Ďalšie volania (výsledky testov, firmvér) pribudnú s modulmi.
