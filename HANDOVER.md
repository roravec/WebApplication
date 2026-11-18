# Handover — state of work

Read CLAUDE.md first (standing facts), then this file. Update this file whenever a task is done or a decision is made.

## Last update: 2026-11-19

### Done
1. **Framework rework** — committed by the owner as `dce2116 Major rework` (branch `rework`, 2026-11-18).
   - Tests: `tests/run.php`, DB tests against MariaDB 10.6 in docker; **27 pass on PHP 7.3.33 and 8.4.27** (2026-11-19).
   - What changed vs. the old version: README.md, section "Changes compared with the previous version".
   - 2026-11-19: routes may use host `*` with a path (`"*/panel"` = any host, path /panel); more specific path wins,
     then exact host beats `*`. (Careful: never write `*/` inside a `/* */` PHP comment — it closes the comment.)
2. **Invoicing requirements**: docs/fakturacia-specifikacia.md (Slovak; owner + accountant). Multi-company; e-invoices
   (Peppol) as a full feature per company; Omega export; owner's invoice template described.
3. **erma.sk placeholder** (owner 2026-11-19: old erma site not needed, just a placeholder for a company that develops
   electronics, real site later): `src/applications/erma/` (class `ErmaApp`, one page: hero, 4 services, contact,
   link "Pre zamestnancov" → `/panel`; SVG logo mark; company data overridable via config `apps.erma.company`).
4. **Employee section — first version** `src/applications/panel/` (class `PanelApp`, own autoloader `Panel\` → `src/`):
   - Login/logout (UserAuth, CSRF, safe `next` redirect, 0.5 s delay + log on failed login), remember-me.
   - Rights (`Panel\Rights`): 10 Prehliadanie (orders), 50 Fakturácia, 100 Správca (employees). Guard per route.
   - **Prehľad**: orders today/7/30 days, per shop, latest orders.
   - **Objednávky** from all e-shops, READ-ONLY from `ps_*` (`Panel\Shop\ShopOrders`): list with filters (shop, state,
     date range, text search), paging, detail (lines, addresses incl. IČO/DIČ fields, payments, state history, carrier).
   - **Zamestnanci** (admin): list, create, edit (rights, active, password ≥ 10 chars); can't demote/deactivate yourself.
   - **Môj účet**: change own password.
   - Faktúry, Firmy: menu items shown as "pripravuje sa".
   - Verified end-to-end on the dev server (PHP 7.3): all pages 200, rights (VIEW user gets 403 on employees),
     validation (422), anonymous 404 → login redirect, open-redirect blocked, app code 403, no PHP warnings.
5. **Logos** (owner 2026-11-19): originals in `brand/` (see brand/README.md — which version is used where, exact colours
   red #F74040, green #55F264, blue #44C5E5). erma.sk: horizontal logo with slogan in the header, mark in the footer,
   favicons; panel: mark in the sidebar, vertical logo on the login page, favicons. **Invoices will use
   `brand/erma-logo-sro.png`** (horizontal, "s.r.o."). Web copies generated with Pillow (trim + resize).
6. **VS Code workspace** `C:\Users\orras\git\ERMA.code-workspace` (outside the repos): WebApplication, Web_store, shop,
   StadiumControl, WEB_LedBoard, ftp; Intelephense set to PHP 7.3.

### Dev environment (home server)
- `~/webapplication-dev` (copy via `tar | ssh`, not a git clone), `dev/compose.yml`, web **http://192.168.0.11:8090/**:
  `/` = erma, `/panel` = employee section, `/demo` = framework demo. Dev login everywhere: **admin / admin**.
- Dev DB contains the shop tables `ps_*` imported by `dev/import-shop.sh` from `~/webstore/backups/database-*.sql.gz`
  (2 460 orders, customer e-mails anonymised, stats tables empty). `dev/seed.php` creates `<prefix>client/token/log/text`
  tables + admin user for every configured app.
- Sync after local edits: `cd C:\Users\orras\git\WebApplication && tar --exclude=.git -czf - . | ssh … 'cd ~/webapplication-dev && tar -xzf - && <strip CR from php/yml/sh/css/.htaccess>'`.

### Waiting for the owner
- Commit of everything after `dce2116` (owner commits himself).
- Answers in docs/fakturacia-specifikacia.md chapter 6: OR registration (súd/oddiel/vložka), first number + format of the
  new series, which Omega import the accountant uses (+ sample file), auto/manual invoice from order + e-mail,
  final path of the employee section (now `/panel`).
- (Not answered yet: can the hosting set PHP version per folder/domain? → PHP 8 for the framework before the shop upgrade.)

### Next tasks
1. **Invoicing in the panel** per docs/fakturacia-specifikacia.md, in this order:
   a) companies (suppliers) with VAT mode history, bank accounts, logo/stamp; b) number series (token format, yearly reset,
   **atomic** allocation — row lock in a transaction); c) customers (B2C/B2B, from orders); d) invoices + credit notes
   (draft → issued, immutable after issue, link to shop order, one invoice per order); e) PDF from HTML template
   (owner's design) + PAY by square QR; f) exports: UBL 2.1 / Peppol BIS 3.0, Omega (format from accountant), ISDOC;
   g) e-mail to customer; h) audit log.
   Technical open points: PDF library usable on PHP 7.3 shared hosting without shell (dompdf / mPDF / TCPDF, vendor
   uploaded); PAY by square needs LZMA (no `xz` binary on shared hosting → pure-PHP encoder); Peppol access point API
   per company.
2. Port hbkpravotice and bnhl (use `getLegacyDatabase()`; see README "Porting").
3. Deploy procedure to the hosting: zip + unpack script like the shop's tools; never overwrite the root `.htaccess`;
   `config/config.php` created on the server; first admin account on production (no SSH → one-time setup page or
   SQL in phpMyAdmin); verify `.htaccess` deny rules behind the hosting's nginx.
4. Real erma.sk website (later, owner will decide content/design).

### Notes
- Shop side (PrestaShop upgrade, dev copies, deploy plan): `C:\Users\orras\git\Web_store` (HANDOVER.md there).
- When the panel invoicing works: disable PrestaShop invoices + uninstall `add_faktura` (removes 4 overrides).
