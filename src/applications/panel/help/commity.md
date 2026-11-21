# Commity pri úlohách

Keď správa commitu **začína číslom úlohy**, commit sa ukáže pri úlohe (hash s odkazom do repozitára, správa, autor,
vetva, čas) a zapíše sa do jej histórie.

## Ako písať commity

Formát nastavuje správca (Nastavenia → Projekty), predvolený je `#{TASK}`. Príklady správ:

- `#LED-12 oprava časovača`
- `#LED-12 #LED-14 spoločná oprava displeja`

- Číslo musí byť **na začiatku** správy (číslo ďalej v texte sa neráta). Viac úloh = viac čísel za sebou.
- **Commit bez čísla prejde**, len sa k žiadnej úlohe nepriradí.
- Autor commitu sa spáruje so zamestnancom podľa e-mailu v gite (`git config user.email` = prihlasovací e-mail do panela).

## Nastavenie (správca, Nastavenia → Projekty)

1. **Formát** čísla úlohy, napr. `#{TASK}`, `[{TASK}]` alebo `{TASK}:` → **Uložiť formát**.
2. **Pridať repozitár**: kde je (**GitHub**, **GitLab**, alebo **Iný** — vlastný server), názov `vlastník/repozitár`
   → **Pridať a ukázať postup**. Panel ukáže presný postup a **tajný kľúč** (len raz):
   - **GitHub**: repozitár → Settings → Webhooks → Add webhook — Payload URL, Content type `application/json`,
     Secret, Just the push event. Zelená fajka = funguje.
   - **GitLab**: projekt → Settings → Webhooks — URL, Secret token, Push events.
   - **Vlastný server**: skript `dev/git-hook-post-receive` z repozitára WebApplication do `hooks/post-receive`
     + API kľúč osoby s právom *Úlohy — úpravy* ([API kľúče](help:api-kluce)).
3. V tabuľke repozitárov vidíte **posledný push** a počet commitov. **vypnúť** = commity z repozitára sa už neprijímajú.

Panel kód neukladá — len hash, správu, autora, vetvu a odkaz.
