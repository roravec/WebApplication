# Ako napojiť e-shop

Bez napojenia sa objednávky dajú v paneli len prezerať. Robí to správca, trvá to asi 10 minút.

## 1. Kľúč v e-shope

1. Administrácia e-shopu (PrestaShop) → **Rozšírené parametre → Webservice**.
2. Webservice musí byť **zapnutý**.
3. **Pridať nový kľúč**: popis napr. „ERMA panel“, **Vygenerovať** kľúč, stav **Zapnutý**.
4. Zaškrtnite oprávnenia presne podľa zoznamu vo formulári pripojenia v paneli (pri poli **Kľúč Webservice**) —
   inak niektoré zmeny skončia hláškou „kľúč nemá oprávnenie“.
5. Pri viacerých obchodoch povoľte kľúč pre všetky obchody. **Uložiť** a kľúč (32 znakov) skopírujte.

## 2. Pripojenie v paneli

1. **Nastavenia → E-shopy → + Pripojiť e-shop**.
2. **Názov**, **Adresa e-shopu** (napr. `https://howieshockey.sk/store/`), vložte **Kľúč Webservice**.
3. Zaškrtnite **Aktívne** a pri našich e-shopoch aj **E-shop je v tej istej databáze ako panel**.
4. **Overiť a uložiť** — panel vypíše obchody, ktoré našiel.

## 3. Kontrola

Otvorte objednávku — pri adresách musí byť **Zmeniť** a v Histórii stavov zmena stavu.
