# Spustenie nového e-shopu (PrestaShop 8.2.9) + presun do `ermaskretail` — postup na ostrý večer

> **Hotové 20. 11. 2026 večer — nasadený bol PrestaShop 9.2.0** (balík z Web_store `devserver/upgrade-run9.sh`, postup
> rovnaký; 8.2.9 sa prepol a vrátil späť). Návod ostáva ako vzor pre ďalšie veľké aktualizácie.

Fáza C + A2 z `nasadenie-a-udrzba.md`. Nacvičené na simulácii hostingu 20. 11. 2026 (Web_store `docs/upgrade-plan.md`,
„A′“). Celý večer ≈ 1 h, e-shopy sú počas neho v údržbe. **Do toho večera v e-shope nemeniť moduly, šablónu ani kód**
(produkty, ceny, objednávky áno).

Čo budeš potrebovať: FileZilla (FTP na hosting), prehliadač, tento návod. Claude je na príjme.

---

## 1. Starý e-shop do údržby (ty, 2 min)

1. Otvor administráciu e-shopu: `https://www.erma.sk/store/admin431…/` (tvoja obvyklá adresa), prihlás sa.
2. Hore vľavo v prepínači obchodov vyber **Všetky obchody**.
3. **Nastavenia obchodu → Všeobecné →** záložka **Údržba**.
4. **Zapnúť obchod: NIE**. Pri **IP pre údržbu** klikni **Pridať moju IP** (tvoja IP uvidí obchod aj v údržbe).
5. **Uložiť**. Skontroluj v anonymnom okne prehliadača `https://howieshockey.sk/store/` → stránka údržby.
6. Odteraz do konca: **nepoužívať panel** (`erma.sk/panel`) ani **StadiumControl** a nič neupravovať v administrácii
   e-shopu (inak prepnutie v kroku 6 odmietne — a správne).

## 2. Záloha databáz (ty, 5 min)

1. V počítači: `C:\Users\orras\git\Web_store\tools\ps-pack.php` — je pripravený z 20. 11. (SECRET aj ermaskretail
   v `$EXTRA_DATABASES` sú vyplnené, nič nemeň).
2. FileZilla: nahraj ho do **koreňa FTP** = priečinok, kde je `index.php`, `store/`, `applications/`, `config/`.
3. Prehliadač: `https://www.erma.sk/ps-pack.php?key=<SECRET z ps-pack.php>`.
4. V tabuľke **Databases** musia byť `molesk` aj `ermaskretail`.
5. Klikni **Dump databases** (NIE „Pack files“). Stránka sa obnovuje; počkaj, kým pri úlohe bude **done** (~1–2 min).
6. V časti **Downloads** stiahni `database-molesk.sql.gz` a `database-ermaskretail.sql.gz` do
   `C:\Users\orras\git\Web_store\backups\` (prepísať staré).
7. Klikni **Delete everything** → hlásenie „Deleted the archives and this script“.
8. Napíš Claudovi: **„databázy sú stiahnuté“**.

## 3. Claude pripraví balík (~15 min — ty čakáš)

Claude skopíruje zálohu na domáci server, spraví aktualizáciu na 8.2.9 na tejto čerstvej kópii a balík uloží do
`C:\Users\orras\ERMA-nasadenie\C-<dátum>\` — v ňom bude:

```
C:\Users\orras\ERMA-nasadenie\C-<dátum>\
    erma-deploy.php          ← nahrať
    _deploy_xxxxxxxxxxxxxxxx\  ← nahrať celý priečinok (aj súbor .htaccess v ňom)
    KEY.txt                  ← NENAHRÁVAŤ — je v ňom kľúč k nástroju
```

Claude ti povie, keď je hotový.

## 4. Nahratie balíka (ty, 5–15 min podľa rýchlosti internetu)

1. FileZilla: **Prenos → Typ prenosu → Binárny** (dôležité pre `.gz` súbory).
2. Nahraj do **koreňa FTP** (ten istý priečinok ako v kroku 2.2):
   - `erma-deploy.php`
   - celý priečinok `_deploy_xxxxxxxxxxxxxxxx` (~206 MB; vo FileZille skontroluj, že sa nahral aj `.htaccess` v ňom —
     ak skryté súbory nevidíš: **Server → Vynútiť zobrazenie skrytých súborov**).
3. Počkaj, kým FileZilla hlási 0 súborov vo fronte a 0 chýb.

## 5. Nástroj: rozbaliť, importovať, overiť (ty, ~5 min)

1. Otvor `KEY.txt` z balíka, skopíruj kľúč.
2. Prehliadač: `https://www.erma.sk/erma-deploy.php?key=<kľúč>`.
3. **1. Kontrola** — všetko ✔: PHP, mysqli, rozbalenie tar, starý e-shop `molesk`, nová databáza `ermaskretail`,
   pripojenie OK, **starý e-shop je v režime údržby**, voľné miesto, `store_new.tar.gz`.
   Ak je niektorý riadok ✘ → stop, napíš Claudovi.
4. **2. Rozbaliť** → stránka sa obnovuje, po ~1 min: „Rozbalené do /store_new/.“
5. **3. Importovať** (databáza e-shopu) → stránka ukazuje „Import shop: N / 119 častí…“ a sama sa obnovuje, ~2–3 min.
   Okno nezatváraj. Ak by sa zastavila, klikni **Importovať** znova — pokračuje, kde skončila.
   Koniec: „Import shop hotový (119 častí).“
6. **4. Importovať** (panel + hbkpravotice) → pár sekúnd → „Import a2 hotový.“
7. **5. Overiť počty riadkov** → „Overenie: všetkých 364 tabuliek je kompletných.“

## 6. Kontrola nového e-shopu PRED prepnutím (ty + Claude, 10 min)

Nový e-shop beží vedľa starého na `/store_new/` (je v údržbe, ty ho vidíš vďaka svojej IP):

- `https://howieshockey.sk/store_new/`
- `https://americkespz.sk/store_new/`
- `https://www.carplates.shop/store_new/`
- administrácia nového: `https://www.erma.sk/store_new/admin431…/` (to isté meno a heslo ako doteraz)

Pozri hlavnú stránku, kategóriu, produkt, košík (vlož produkt, **NEOBJEDNÁVAJ** — objednávka by bola ostrá).
Napíš Claudovi, Claude pozrie verejné stránky tiež. ERMA obchod (`www.erma.sk/store_new/`) je v údržbe zámerne.

## 7. Prepnutie (ty, 1 min)

1. V nástroji klikni **6. Prepnúť** → potvrdiť. Hlásenie: „PREPNUTÉ: /store/ = nový e-shop (8.2.9), /store_old/ =
   starý. A2: panel, hbkpravotice a bnhl bežia na databáze ermaskretail …“
   - Ak napíše **„Prepnutie ODMIETNUTÉ — po zálohe sa zmenili údaje: …“** → niečo sa v starom e-shope / paneli zmenilo
     po zálohe. Nič sa nepokazilo, priečinky sú nedotknuté. Napíš Claudovi zoznam; riešenie je zopakovať od kroku 2.
2. Hneď skontroluj:
   - `https://howieshockey.sk/store/`, `https://americkespz.sk/store/`, `https://www.carplates.shop/store/`
   - `https://www.erma.sk/panel/` — prihlásenie e-mailom, Objednávky
   - `https://hbkpravotice.sk/`, `https://bnhl.sk/`
3. Všetko OK → v nástroji **Zapnúť e-shopy (vypnúť údržbu)** → hlásenie „E-shopy zapnuté: Howies Hockey ✔,
   AmerickeSPZ.sk ✔, ERMA (údržba), CarPlates ✔“. Skontroluj v anonymnom okne, že zákazník vidí obchod.

**Ak je niečo zle:** v nástroji **Vrátiť späť** → do pár sekúnd je späť starý e-shop aj panel na `molesk`. Potom
**Zapnúť starý e-shop** (zruší údržbu starého). Nový e-shop ostane na `/store_new/` na ďalší pokus.

## 8. Po prepnutí (ty, 10 min)

1. Administrácia hostingu → **Cron / plánované úlohy** → vypnúť (alebo zmazať) úlohy, ktoré volajú
   `cron_howies_activate.php` a `cron_usspz_del.php` (patrili starému modulu, ktorý už nie je).
2. Administrácia nového e-shopu `https://www.erma.sk/store/admin431…/` → **Moduly → Správca modulov** → modul
   **„Sklad: vypnúť vypredané produkty“** → **Konfigurovať** → **Uložiť a synchronizovať všetky produkty** (raz).
3. GoPay (účet obchodníka): platby z tohto večera porovnaj so stavmi objednávok v administrácii (Objednávky) — ak
   niektorá zaplatená nie je „Platba prijatá“, nastav ju ručne.
4. Prvú zásielku Packeta skontroluj v klientskej sekcii Packety.
5. Faktúry: PrestaShop už faktúry nevystavuje (vypnuté pri aktualizácii) — vystavuješ ich v paneli.

## 9. Po pár dňoch (ty, 2 min)

Keď všetko beží: `https://www.erma.sk/erma-deploy.php?key=<kľúč>` →
1. **Zmazať /store_old/** (od tej chvíle už nejde vrátiť späť),
2. **Zmazať nástroj** (zmaže `erma-deploy.php` aj `_deploy_…` s heslom do databázy).
Potom môžeš zmazať aj `C:\Users\orras\ERMA-nasadenie\C-<dátum>\` (je v ňom heslo do databázy v `db.php`).
