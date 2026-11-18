# Handover — state of work

Read CLAUDE.md first (standing facts), then this file. Update this file whenever a task is done or a decision is made.

## Last update: 2026-11-18

### Done
1. **Framework rework** on branch `rework` — committed by the owner as `dce2116 Major rework` (2026-11-18).
   Line endings normalised (CRLF; Dockerfile LF via `.gitattributes`).
   - 26 tests pass on PHP 7.3.33 and 8.4.27 (`tests/run.php`, DB tests against MariaDB 10.6 in docker).
   - End-to-end over Apache verified: demo app pages, login (CSRF, wrong password, remember-me, logout), cookie flags
     (HttpOnly, SameSite=Lax, Secure on HTTPS), 403 for config/framework/app code/logs, CSS served.
   - What changed vs. the old version: README.md, section "Changes compared with the previous version".
2. **Invoicing requirements** written: docs/fakturacia-specifikacia.md (Slovak; for owner + accountant).
   Includes owner decisions, legal requirements, configurability, the owner's invoice template (FA2026-000198.pdf
   description), e-faktúra facts (from 1.1.2027 for VAT payers, EN 16931 / Peppol BIS 3.0 / UBL 2.1; non-payers must be
   able to receive) and Omega import options (TXT import / ISDOC / KROS eFaktúra — to be confirmed).

### Waiting for the owner
- Docs changed after `dce2116` (CLAUDE.md, HANDOVER.md, docs/fakturacia-specifikacia.md: commit rule, multi-company,
  e-invoices per company) — uncommitted, for the owner to commit.
- Answers in docs/fakturacia-specifikacia.md chapter 6: OR registration (súd/oddiel/vložka), first number + format of the
  new series, which Omega import the accountant uses (+ sample file), auto/manual invoice from order + e-mail,
  path of the employee section.
- (Earlier, not answered: can the hosting panel set PHP version per folder/domain? → would allow PHP 8 for the framework now.)

### Next tasks
1. **Port erma.sk** (customer frontpage) into `src/applications/erma/`:
   start from `C:\Users\orras\git\Web_ERMA\WebApp_erma\WebApp.php` (newest) + HtmlContentBuilder/assets from
   `C:\Users\orras\git\StadiumControl\WebServer\webapp\WebApp_erma`. Remove its `require` of the old IWebApp path;
   texts come from `ermaweb_text` via `getTextFromDatabase()`; assets via `$app->asset()`.
   Dev: needs `ermaweb_*` tables in the dev DB (from the DB dump `C:\Users\orras\git\Web_store\backups\database-2026-11-18-2155.sql.gz`
   — it contains `ermaweb_*` tables; the shop dev copies import only `ps_*`).
2. **Employee section (panel app)**: login (UserAuth), list of shop orders from `ps_*` (read-only), then invoicing per spec:
   companies, number series (atomic), customers, invoices/credit notes (immutable after issue), PDF from HTML template
   (owner's design), PAY by square QR, exports (UBL/Peppol, Omega TXT, ISDOC), audit log.
   Technical open points: PDF library on PHP 7.3 shared hosting (dompdf/mPDF/TCPDF via vendor upload);
   PAY by square needs LZMA compression (no `xz` binary on shared hosting → pure-PHP encoder).
3. Port hbkpravotice and bnhl (use `getLegacyDatabase()`; see README "Porting").
4. Deploy procedure to the hosting (zip + unpack script like the shop's tools; never overwrite root `.htaccess`;
   create `config/config.php` on the server; check `.htaccess` deny rules really work behind the hosting's nginx).

### Notes
- Shop side (PrestaShop upgrade, dev copies on the home server, deploy plan): `C:\Users\orras\git\Web_store` (HANDOVER.md there).
- When the panel invoicing works: disable PrestaShop invoices + uninstall `add_faktura` on the shop (removes 4 overrides
  that block PrestaShop upgrades).
