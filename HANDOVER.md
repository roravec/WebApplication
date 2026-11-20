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
   - Rights: replaced on 2026-11-19 by permissions per module + per company (see item 8).
   - **Prehľad**: orders today/7/30 days, per shop, latest orders.
   - **Objednávky** from all e-shops, READ-ONLY from `ps_*` (`Panel\Shop\ShopOrders`): list with filters (shop, state,
     date range, text search), paging, detail (lines, addresses incl. IČO/DIČ fields, payments, state history, carrier).
   - **Zamestnanci** (admin): list, create, edit (rights, active, password ≥ 10 chars); can't demote/deactivate yourself.
   - **Môj účet**: change own password.
   - Verified end-to-end on the dev server (PHP 7.3): all pages 200, rights (VIEW user gets 403 on employees),
     validation (422), anonymous 404 → login redirect, open-redirect blocked, app code 403, no PHP warnings.
5. **Logos** (owner 2026-11-19): originals in `brand/` (see brand/README.md — which version is used where, exact colours
   red #F74040, green #55F264, blue #44C5E5). erma.sk: horizontal logo with slogan in the header, mark in the footer,
   favicons; panel: mark in the sidebar, vertical logo on the login page, favicons. **Invoices will use
   `brand/erma-logo-sro.png`** (horizontal, "s.r.o."). Web copies generated with Pillow (trim + resize).
6. **Panel colour theme** (owner 2026-11-19: dark by default, option to switch to light "denný režim"):
   colours are CSS tokens in `panel/css/panel.css` (`[data-theme=dark]` default, `[data-theme=light]`); the server sets
   `<html data-theme>` from cookie `panel_theme` (`PanelContext::theme()`, path = panel base path, 1 year) so there is no
   flash of the wrong theme; toggle button in the sidebar and on the login page (`panel/js/theme.js`). Per browser, not
   per user. Verified on dev: all pages, both themes, invalid cookie → dark. **New panel pages must use the tokens, no
   hard-coded colours.** (erma.sk public site is not themed.)
8. **Invoicing — step 1** (2026-11-19, not committed yet): everything in `src/applications/panel/`.
   - **DB migrations** `panel/sql/NNN_*.sql`, applied automatically on the first request (`Panel\Schema`, table
     `<prefix>schema_migration`, GET_LOCK) — no SSH needed on the hosting. `001_invoicing.sql` = all tables below.
   - **Permissions** (`Panel\Rights`): administrator = `client.rights >= 100` (everything, all companies, employees,
     new companies); others (`rights = 10`) get ticked permissions `orders.view`, `invoices.view`, `invoices.edit`,
     `companies.manage` (table `permission`) + access to chosen companies (`company_access`). Old levels converted by
     the migration (10 → orders+invoices view, 50 → + invoices.edit). New modules (tasks) add permissions there.
   - **Firmy** (`Invoicing\Companies`, `CompaniesController`): details incl. OR registration, VAT periods
     (`company_vat`: from date, payer/non-payer, IČ DPH — the status on the delivery date decides), bank accounts (IBAN
     mod-97 check, never deleted, only deactivated), logo + stamp/signature (PNG/JPG ≤ 2 MB, stored as BLOB in the DB —
     no writable folder needed), which e-shops the company invoices (`company_shop`), accent colour, slogan, due days,
     default note. A new company gets series `FA{RRRR}/{NNNNNN}` and `DB{RRRR}/{NNNN}` and its creator gets access.
   - **Číselné rady** (`Invoicing\Numbering`): tokens {RRRR} {RR} {MM} {NNN…}, VS format (empty = digits of the
     number, max 10), yearly reset, "next number" settable (first number of the new system). Allocation inside the issue
     transaction with `SELECT … FOR UPDATE` + UNIQUE(company_id, number) → no duplicates, no gaps. The format of a used
     series is locked.
   - **Odberatelia** (`Invoicing\Customers`): per company (companies don't see each other's customers), B2C/B2B,
     created from shop orders (matched by shop customer id) or from the invoice form ("uložiť do adresára"); lookup in
     the invoice form (`/customers/lookup?company=`).
   - **Faktúry + dobropisy** (`Invoicing\Invoices`, `Invoicing\Calculator`, `InvoicesController`): draft (no number,
     editable, deletable) → issue (number, supplier snapshot JSON, bank snapshot, totals recalculated with the VAT
     status on the delivery date) → issued = immutable. Amounts the EN 16931 way: line net = round(qty × price), VAT per
     rate from the sum (`breakdown`), categories S/Z/E/AE/K/G/O; non-payer → all lines category O, 0 %.
     "Pripraviť faktúru" on the order detail: draft from the shop order (lines, shipping, wrapping, discount; payer →
     prices without VAT + shop rate, non-payer → paid prices; B2B if the address has a company; IČO from `dni`,
     DIČ / IČ DPH from `vat_number`; payment mapped: gateway → card (paid), dobierka → cod, prevod → transfer, store
     pickup → cash). One invoice per order (UNIQUE shop_order_id). Credit note = draft copy with negative quantities +
     mandatory reason, own series. List with filters (company, type, status, year, text), dashboard shows drafts,
     history (`audit` table, `Panel\Audit`, shared by all modules).
   - **Print view** `/invoices/{id}/print`: A4 HTML per the owner's design (self-contained, images as data URIs → ready
     to be rendered to PDF); "KONCEPT" watermark on drafts; VAT columns + VAT recap only for VAT payers;
     "Uhradené — neuhrádzajte" for card/cash; the PAY by square box is a placeholder. Browser "Save as PDF" until step 2.
   - Framework: `Request::file($key)` for uploads (constructor got optional `$files`, `fromGlobals()` passes `$_FILES`).
     The panel rejects non-UTF-8 input with 400.
   - Tests: `tests/PanelInvoicingTest.php` (calculator, number formats, migrations + rights conversion, issuing, yearly
     reset, VAT payer from a date, immutability, credit note, allocation lock, customer separation) — **34 pass on PHP
     7.3.33 and 8.4.27**. End-to-end on dev (PHP 7.3) with curl: company with logo/stamp, bad IBAN 422, series next
     number 198 → invoice from order 2481 issued as FA2026/000198, duplicate prevented, manual invoice = owner's sample
     (800,00 €) issued as FA2026/000199, invalid line 422, credit note draft, employee with only orders.view gets 403 on
     invoices/companies/customers/employees. No PHP warnings. Screens checked in dark and light theme.
9. **Omega export** (2026-11-19): Faktúry → Export do Omegy → TXT for Omega's "Import z textového súboru", built from
   the official KROS description ImportExport 20.60 (TAB, CRLF, Windows-1250, `R00 T04` partners + `R00 T01` documents,
   `R01` header / `R02` items, credit note = type 4 with positive amounts). Per-company settings (record codes OF/OD,
   Omega series codes, decimal separator, partners on/off) in JSON `company.export_settings` (migration
   `002_omega_export.sql`); `invoice.omega_exported_at` prevents double export. Limit: the 20.60 layout has only two VAT
   rates → documents with 3 rates are refused (irrelevant for a non-payer; 2025 layout "28.00" not downloadable,
   KROS page 404). Details + column mapping: docs/omega-export.md. Tests: 36 pass on 7.3 + 8.4; real download from dev
   checked (encoding, CRLF, "only new" → nothing left).
10. **Legislation review + fixes** (2026-11-19, owner: "zákonné veci sa často menia, je to dôležité"). Principles and the
   full map of legal rules: **docs/legislativa.md** (keep it updated). Done (migration `003_legislation.sql`):
   - VAT rates = table `vat_rate` (country, rate, standard/reduced, valid_from/valid_to; SK seeded: 20/10/5 until
     2024-12-31, 23/19/5 from 2025). Default line rate = standard rate on the delivery date; datalist of valid rates in
     the form; issuing (VAT payer) refuses a rate not valid on the delivery date.
   - Printed legal texts = table `legal_text` (code, text, valid_from; codes in `Legislation::TEXT_CODES`): titles,
     credit-note reference, non-payer, reverse charge (AE), exemptions (E, K § 43, G § 47), zero rate, paid, currency.
     New: category texts are printed when the category is used. Defaults to be checked by the accountant.
   - Admin page **Legislatíva** (`/settings/legal`, `LegislationController`): edit rates + texts; refuses a save without a
     standard rate or with a text type missing (a partial form must never wipe legislation).
   - Issued invoice freezes texts (`invoice.legal_json`) and print template version (`invoice.template`); templates live
     in `views/invoices/templates/` (`invoice-2026.php`, was `invoices/print.php`), `Templates::CURRENT`; change =
     copy to a new file.
   - Omega export versioned: `OmegaExport::VERSIONS` + `create()`, company setting "Formát (verzia KROS)"; settings JSON
     gets new defaults automatically (`Companies::exportSettings`).
   - New checks: AE/K need the buyer's IČ DPH (error); warnings (non-blocking) on the draft: missing OR registration,
     VAT payer issuing later than 15 days after delivery.
   - Tests: 37 pass on PHP 7.3.33 + 8.4.27. Dev: page, refused partial save, full save round-trip, form rates
     (23/19/5, default 23), print texts checked.
11. **Invoice PDF + archive + PAY by square + compact layout** (2026-11-19):
   - Composer (first dependency): `composer.json` in the repo root, libraries in **`src/vendor`** (NOT in git, lock file
     IS), platform pinned to PHP 7.3.33: dompdf 3.1.6 (HTML → PDF), bacon/bacon-qr-code 2.0.8 (QR matrix). Install on
     the dev server: `cd ~/webapplication-dev && docker run --rm -u $(id -u):$(id -g) -v $PWD:/app -w /app -e
     COMPOSER_HOME=/tmp composer:2 install --no-dev` (post-install script copies a deny-all `.htaccess` into src/vendor).
     `src/bootstrap.php` loads `vendor/autoload.php` when present. Deploy must upload `src/vendor`; `src/var/dompdf`
     must be writable (font cache). The framework error handler ignores deprecation notices from `src/vendor`
     (bacon 2.x on PHP 8.4) — upgrade bacon to 3.x when the hosting runs PHP ≥ 8.1.
   - Template `invoice-2026` rewritten for dompdf (tables only, no grid/flex) and compacted: dates in one box, IČO/DIČ/IČ
     DPH on one line, country only when not SK, "Vystavil" + stamp beside the totals (items start ~38 % down the page
     instead of ~55 %). Font Poppins (OFL, `panel/fonts`, Slovak glyphs checked); dompdf `fontHeightRatio 0.72`
     (Poppins' tall line box). Unit prices printed with all their decimals (5,586). Same HTML for the browser preview
     (`/invoices/{id}/print`) and the PDF (`/invoices/{id}/pdf`, `?download=1`). Edited in place because no invoice was
     issued in production yet — from the first production invoice the copy-to-new-version rule applies.
   - `Panel\InvoiceRenderer` builds the HTML/PDF; `Invoicing\Pdf` (dompdf, page numbers "Strana X z Y" drawn on the
     canvas). **Archive:** right after issuing the PDF is stored in `invoice_pdf` (LONGBLOB + SHA-256, migration
     `004_invoice_pdf.sql`, INSERT IGNORE — never replaced); downloads of issued documents serve the stored file; if
     archiving fails the issue stays and the PDF is made on first download. Drafts: PDF on the fly with "KONCEPT".
   - **PAY by square** (`Invoicing\PayBySquare`, official SBA spec 1.2.0 of 2025-10-09 — beneficiary name REQUIRED):
     fields → CRC32 → LZMA (own pure-PHP literal-only encoder, no `xz` needed) → header 0000 → Base32hex; QR level L,
     30 mm (spec minimum), SVG via `Invoicing\QrSvg`, caption "PAY by square". Only for bank transfer with amount > 0.
     Verified by decoding with Python's liblzma (header, length, CRC32, all 19 fields, Slovak text); regression vector in
     the tests. **Not yet scanned with a real banking app — owner should scan one invoice.**
   - Tests: 39 pass on PHP 7.3.33 + 8.4.27. Dev: issued non-payer FA2026/000199 + VAT payer FA2027/000001 (23 % + 19 %,
     recap), draft, shop-order invoice (card, no QR), 60-line draft = 2 pages (header + footer repeat, "Strana 2 z 2");
     archived PDF identical on repeated download; vendor/ and var/ return 403, fonts 200.
12. **Payments + e-mail** (2026-11-19, migration `005_payments_email.sql`):
   - **Úhrady** (`invoice_payment`, never changes the issued document): record / delete on the invoice detail (credit
     note = refund with negative amount), state paid / partial / unpaid / overdue (`Invoices::paymentState`), badge in
     the list + filter (`?payment=unpaid|overdue|paid`), "Faktúry po splatnosti" on the dashboard. Card / cash
     documents get a payment automatically when issued (existing ones by the migration). Audit: payment, payment_delete.
   - **E-mail with the PDF** (`Panel\Mailer` = PHPMailer 6.12 via Composer): "Poslať e-mailom" on an issued document →
     form prefilled from the company templates (Firmy → E-mail s faktúrou: sender, reply-to, BCC e.g. accountant,
     subject/body with placeholders {document_acc} {number} {total} {payment_info} …, three payment texts), attaches the
     ARCHIVED PDF, log in `invoice_email` (sent/failed + error) + audit. SMTP server and credentials only in config.php
     `apps.panel.mail` (`transport smtp|mail, host, port, secure ssl|tls, user, password`; example in
     config.template.php) — **production needs the hosting's SMTP mailbox filled in**.
   - Dev: **Mailpit** service in dev/compose.yml (`axllent/mailpit:v1.21`), UI **http://192.168.0.11:8025**, SMTP
     mail:1025 — every e-mail from the dev panel lands there, nothing leaves the server.
   - Customer's due days: used for an invoice from a shop order (bank transfer) and when a customer is picked in the
     invoice form (JS).
   - Tests: 40 pass on PHP 7.3.33 + 8.4.27. Dev e2e: e-mail received by Mailpit (from, to, BCC, text, PDF 106 kB
     attachment), invalid address 422, partial → paid, invalid payment refused, list filters, dashboard, company form.
13. **Bank statement import, reminders, company data by IČO** (2026-11-19, migration `006_bank_import_reminders.sql`):
   - **Import výpisu** (Faktúry → Import výpisu, `BankImportController`, `Invoicing\BankStatement`): upload ISO 20022
     **CAMT.053** XML (SEPA / SBA standard — Fio "SBA XML", other Slovak banks "CAMT.053"; versions .001.02–.08). The
     company is found by the statement IBAN; booked entries only; VS from EndToEndId "/VS…/SS…/KS…", creditor reference
     or the message; matching to open documents by VS (leading zeros ignored, the exact remaining amount preferred);
     review page (change / unpair any row) → confirm → payments with `bank_ref` (UNIQUE → a transaction is never
     imported twice; old forms are refused by a session token). XML parsed without external entities.
     Test statement `tests/fixtures/camt053-sample.xml`. Not tried with a REAL bank file yet — owner: export one from Fio.
   - **Upomienky**: "Poslať upomienku" on an unpaid document → the same e-mail form with the company's reminder
     subject/text ({rest} = amount left), PDF attached, `invoice_email.kind = reminder`, shown in the history.
   - **Doplniť z registra** (button at IČO in the invoice, customer and company forms): public API of the Register of
     legal entities (`Invoicing\Rpo`, api.statistics.sk, CC BY 4.0 — source shown): name, address and the commercial
     register entry ("Obchodný register Okresného súdu Trenčín, oddiel Sro, vložka č. 43969/R"). DIČ / IČ DPH are not in
     RPO. `Panel\HttpClient`: certificates always verified; if the system CA store fails (old Debian 11 image lacks
     "Sectigo … Root R46" used by api.statistics.sk) it retries with the Mozilla bundle `panel/data/cacert.pem`
     (curl.se, 2026-11-05) — refresh it about yearly.
   - Tests: 42 pass on PHP 7.3.33 + 8.4.27. Dev e2e: reminder in Mailpit, import → FA2027/000001 paid, re-import shows
     "už zapísaná", replayed form refused, wrong file 422, RPO lookups (ERMA, ZM Technik, unknown IČO 404).
14. **Fio API — automatic download of movements** (2026-11-19, migration `007_bank_api.sql`): read-only Fio token per
   bank account (Firmy → bankové účty, column "API banky"; empty = unchanged, "zmazať" = remove), stored ENCRYPTED
   (`Panel\Secret`, AES-256-GCM, key from config.php `jwtSecret` — changing jwtSecret makes tokens unreadable → enter
   again). Faktúry → Import výpisu → "Stiahnuť pohyby" (period, from = last download − 3 days) → `Invoicing\Fio`
   (official doc "API Bankovnictví" 16. 10. 2025: `periods/{token}/{from}/{to}/transactions.sba_xml` = camt.053.001.02,
   1 request / 30 s, HTTP 409) → the same review + pairing as the file upload. Fio writes symbols as
   "VS:…SS:…KS:…" (since 2020) — parser handles it. Observed: an invalid token gets NO answer on /periods (timeout) —
   the message tells the user to check the token. Token never in messages/logs/pages (checked).
   **Not tried with a real token — owner: enter the Fio read-only token and click "Stiahnuť pohyby".**
15. **E-invoice UBL 2.1 / Peppol BIS Billing 3.0** (`Invoicing\Ubl`): "E-faktúra (XML)" on an issued document (or the
   reason why not, `Ubl::check`: both parties need a 10-digit DIČ, address, IČ DPH for AE/K). Invoice 380 / CreditNote
   381 (positive amounts, BillingReference to the original), endpoints `0245:DIČ` (Slovak scheme per providers — verify
   with the PASR when the access point is chosen), seller VAT id or DIČ as TAX scheme, VAT breakdown with exemption
   reasons from the Legislation texts, BR-27 (discount = negative quantity, price ≥ 0), BR-O-02 (non-payer: no VAT ids),
   units → UN/ECE Rec 20, payment means 58/48/10, BuyerReference = order or number. Generated from the frozen issued
   data. DIČ is derived from a Slovak IČ DPH (SK + DIČ) for buyers imported from the shop.
   **Validated** with the official OpenPeppol rules 2026.5 (BIS Billing 3.0.21: UBL XSD + EN 16931 + Peppol schematron)
   through the public validator peppol.helger.com (SOAP `wsdvs`, VESID `eu.peppol.bis3:invoice:2026.5` /
   `creditnote:2026.5`) — 4 SYNTHETIC documents (non-payer with discount, payer 23 % + 5 % + 19 % + discount, credit
   note, reverse charge AE): all 3 layers pass, 0 errors, 0 warnings; a deliberately broken control document was
   rejected (BR-CO-16, PEPPOL-EN16931-R003). Never send real customer data to the public validator.
   Sending via a Peppol access point (provider per company) = later, when the owner picks a provider.
   Tests: 44 pass on PHP 7.3.33 + 8.4.27.
16. **Warehouse module — step 1** (2026-11-19, owner's decisions in docs/sklad-specifikacia.md, migration
   `008_stock.sql`): menu **Sklad** (permissions `stock.view`, `stock.edit`; company access applies).
   Warehouses per company (code, name, location, kind shop / other), stock items (cards: SKU unique per company, unit,
   EAN, minimum → "málo" badge), batches `stock_lot` with the purchase price per unit (without VAT), lot code, expiry;
   documents `stock_doc` + movements `stock_move` (one row per lot touched): **príjemka** (new lots), **výdajka**
   (FIFO — oldest lots first, cost = their prices), **prevodka** (keeps price, receipt date and lot code), **inventúra**
   (counted quantity → shortage FIFO / surplus at the given or last price). Numbers from company number series (types
   receipt/issue/transfer/adjust; default series PR/VY/PV/IN{RRRR}/{NNNN} created on first use; series form lists
   them). One transaction per document with lots locked FOR UPDATE; negative stock refused; posted documents final
   (correction = opposite document). Overview: quantity, average price, value at purchase prices per warehouse.
   `Panel\Stock\Stock`, `StockController`, views/stock/*. Tests: 45 pass on 7.3 + 8.4. Dev e2e: 2 warehouses, 2 items,
   receipts, issue 120 = 227,00 € (100 × 1,85 + 20 × 2,10), transfer, inventory −4,20 €, total 308,80 €, refusals
   (too much, no price, same warehouse, duplicate code), employee without stock permission → 403.
17. **E-shop stock managed IN the shop (PrestaShop Webservice)** (2026-11-19; owner changed decision 1: the shop keeps
   its stock, the panel edits it directly there; combinations exactly as PrestaShop has them; native warehouses only
   for goods outside shops — native warehouse form no longer offers "shop" kind). Migration `009_shop_connection.sql`.
   - `Panel\Shop\PrestaShopApi`: any PrestaShop 1.7/8 (local or remote), HTTP Basic with the key (never in the URL),
     `id_shop` context, readable errors (bad key 401, missing permission). `HttpClient::request()` now does PUT/POST.
   - `Panel\Shop\ShopStock`: shops + shop groups (share_stock), rows = product without combinations or each
     combination (name + attribute values, reference, EAN), quantity / out_of_stock / location from
     `stock_availables` of the shop or its group; update = GET + PUT of the stock_available (checks it belongs to the
     shop). Diacritics-insensitive sort, name fallback to another language.
   - UI Sklad → **E-shopy** (`ShopsController`): connections (admin; URL + Webservice key, encrypted, tested before
     saving), per shop a paged list (100 rows) with search / inactive filter; only changed rows are posted (JS
     `form[data-only-changed]`, avoids max_input_vars) and saved in the shop; every change in the audit log.
   - Dev: **dev1 shop copy** got a separate webservice key "ERMA panel (dev test)" (key in `~/webstore/dev1/panel-ws-key`,
     chmod 600; resources: products/combinations/stock_availables GET+PUT, orders etc. GET, order_histories POST;
     order_payments GET added 2026-11-19 for item 19).
     The panel connection "E-shopy ERMA (dev1)" points to it. A re-copy of dev1 from production removes the key.
   - Verified: 4 shops found; Howies 316 active rows incl. 221 combinations (0,8 s), AmerickeSPZ 1 445 rows (2,2 s);
     a change saved from the panel appeared in the shop DB and was restored; bad key / bad quantity refused;
     stock.view-only user read-only + POST 403. Tests (simulated shop): 46 pass on PHP 7.3 + 8.4.
   - **Found in the production shop:** an ACTIVE webservice key WITHOUT description (account id 1) with GET access to
     customers, orders, employees, addresses … — owner should check who uses it, otherwise disable it.
18. **Product stock settings + order state, changed IN the shop** (2026-11-19). Migration `010_shop_local_db.sql`.
   - `Panel\Shop\ShopProducts`: product page (link from the shop stock list, Sklad → E-shopy → product name): minimal
     quantity, low-stock threshold + e-mail alert, wholesale price, availability texts (panel language only); per
     combination minimal quantity, low-stock threshold, wholesale price. Only changed values are sent.
   - **SAFE PUT of a product (PrestaShop 1.7.6.7, verified by checksumming all product tables):** GET the product, change
     the fields, REMOVE the read-only fields (→ HTTP 400 "not writable"; read from the shop's `?schema=synopsis`, else
     `manufacturer_name`, `quantity`, `new` — PS 9 added `new`), `associations` (otherwise PrestaShop
     re-sorts the product AND other products in the categories — a PS 1.7 bug, seen on dev1 and repaired there),
     `position_in_category`, and an empty `low_stock_threshold` (empty turns NULL into 0). Then a PUT changes only
     `ps_product`, `ps_product_shop`, `ps_product_lang` (edited fields + date_upd). Combinations: remove `quantity` +
     `associations`. The Webservice cannot set the threshold back to "not set" (NULL) → an emptied field saves 0.
   - `Panel\Shop\ShopOrderState`: order detail → "Zmeniť stav" (state list from the shop, checkbox "poslať zákazníkovi
     e-mail") = POST `order_histories` (`sendemail=0|1`) — exactly like the PrestaShop admin (hooks, e-mail of the
     state, stock/payment logic of the state). Safety: the order in the shop must have the same reference as the panel
     shows, else nothing changes. Same state = no-op. History rows made via Webservice have id_employee 0 → the panel
     audit log says who did it.
   - Which connection is "the shop whose DB the panel reads" = checkbox on the connection (`shop_connection.local_db`,
     only one). New permission **`orders.edit`** (Objednávky — zmena stavu v e-shope); without it the form is hidden and
     POST → 403.
   - Verified on dev1 (mail off): order 2507 state 1 → 3 → 33 changed in the shop (history rows, no stock / payment /
     invoice side effects), wrong state refused, read-only user 403; product 1153 edit changed only the 3 tables above,
     category position untouched; "nothing changed" save sends nothing. dev1 restored afterwards (history rows deleted,
     state and date_upd back). Bug found and fixed during e2e: a single resource comes back as `<order>` even with
     `display=`. Tests (simulated shop): 48 pass on PHP 7.3.33 + 8.4.27.
19. **Orders of e-shops reached only through the Webservice** (2026-11-19; owner: "so we don't need the shop connected
   directly through the DB" + manage such shops). Migration `011_shop_sources.sql`.
   - **Order source** (`Shop\OrderSource`): 0 = PrestaShop tables in the panel DB (`ShopOrders`, SQL, as before);
     N = shop connection N without the "this database" checkbox, read through its Webservice (`Shop\ShopOrdersApi`).
     Both give the same arrays (PrestaShop column names + `src`) → list, detail, invoice, state change, dashboard and
     company assignment work the same for both. Orders: select "e-shop" (source) top left when there is more than one.
   - Every installation numbers orders / shops / customers from 1 → invoice (`shop_connection_id` + order, UNIQUE),
     customer (`shop_connection_id` + shop customer) and company ↔ e-shop (`company_shop.connection_id`, keys
     "source:id_shop") carry the source. URLs: `/orders/{id}` (source 0, old links keep working), `/orders/{src}/{id}`.
   - **Webservice facts (dev1, PrestaShop 1.7.6.7):** MULTISTORE — lists of orders and customers contain only the
     context shop (`id_shop`; default = default shop, no "all shops") → read per shop in parallel and merged (first
     version showed only Howies: 1956 of 2460 orders); `orders/{id}`, order_details, order_histories, addresses are not
     limited; one sort field only; no total count (ids fetched); filters AND only (search = several queries merged);
     order_details has no VAT rate (computed from line totals, snapped to whole %); a customer is read via the list
     with `display` (never the password hash).
   - Speed: `HttpClient::requestMany` (curl_multi, max 8 at a time) + `ShopCache` (table shop_cache: states, shops,
     currencies, countries, carriers 1 h; unfiltered first pages 60 s, stats 5 min; cleared on connection save /
     after a state change). Dev1 over LAN: list 1.0–1.3 s cold / 0.01 s cached, detail 0.45 s, search 0.4–1.3 s,
     search "a" over 2 460 orders 3 s. Unreachable e-shop → readable error on its pages only (dashboard shows the rest).
   - Key permissions now also: order_payments, customers, addresses, countries, currencies, carriers (GET) — listed in
     the connection form. Without order_payments the detail says "payments unknown" instead of "no payment".
   - Verified on dev (connection 1 temporarily switched to Webservice mode, so the same orders came from the DB and
     from dev1): list pages 1–2, filters shop / state / date and searches (number, surname, "Ján Novák", e-mail,
     s.r.o., "a") give **identical rows** both ways; order detail identical (also an AmerickeSPZ order with a payment);
     invoice draft from the remote order identical to the one from the DB order (buyer, lines, total), separate
     customer per source, links back to the right order; company form lists both sources and keeps assignments of
     a source that was not offered; state change through the remote source + list refreshed at once. Everything
     restored afterwards (dev1 order 2507, drafts, customers, connection back to "this database").
     Tests (simulated multistore shop): 50 pass on PHP 7.3.33 + 8.4.27.
20. **VS Code workspace** `C:\Users\orras\git\ERMA.code-workspace` (outside the repos): WebApplication, Web_store, shop,
   StadiumControl, WEB_LedBoard, ftp; Intelephense set to PHP 7.3.
21. **Monthly package for the accountant** (2026-11-20, owner: "aby som nemusel nič robiť"; KROS API costs extra and
   doesn't cover Omega — docs/fakturacia-specifikacia.md 5.4). Migration `012_accounting_package.sql`
   (`accounting_package`, `invoice.accounting_package_id`), `Invoicing\AccountingPackage`, `Panel\Zip` (pure-PHP ZIP —
   no ZipArchive needed on the hosting), `AccountingController`, page **Faktúry → Pre účtovníka** (`/accounting`),
   company setting **Pre účtovníka** (e-mail, automatically yes/no, from day N; JSON `export_settings.accountant`).
   - One e-mail per company and month with a ZIP: `omega-RRRR-MM.txt` (documents not exported before; OmegaExport now
     reports `exportedIds()`), PDFs (archived) in `faktury/`, `dobropisy/`, `prehlad-dokladov-RRRR-MM.csv` +
     `uhrady-RRRR-MM.csv` (Excel: BOM, `;`, decimal comma), `NAVOD.txt`. A month without documents → short e-mail
     without attachment. Problems (document not exportable, missing PDF) are listed in the e-mail.
   - Documents: issued, issue date ≤ end of month, not in an earlier package (late ones from past months go into the
     next one). Assigned only after a successful send; a sent package keeps its list → "Poslať znova" / ZIP later =
     identical content. Download never marks anything.
   - Automatic: the hosting has no reliable cron → the **dashboard** sends due packages (first visit from day N of
     the next month); optional cron URL `/panel/cron/accounting?token=<apps.panel.cron_token>` (404 without a token).
     Double sending prevented by claiming the row (INSERT IGNORE / conditional UPDATE); a failed attempt is retried
     at most once an hour; a crashed "sending" is taken over after 30 min. Audit: `accounting`, `accounting_failed`.
   - Tests: 52 pass on PHP 7.3 + 8.4 (ZIP read back with CRC check, content, failure, send once / again, late document,
     auto day, empty month). Dev: page + company section checked (dark theme), ZIP checked with Python `zipfile`
     (testzip OK, Omega file Windows-1250), sent to Mailpit (subject, text, 159 kB ZIP). Dev company 1 now has
     accountant `uctovnik@example.sk` and the November 2026 package sent.
   - **Owner:** fill in the accountant's e-mail at the company and tick "automaticky"; the accountant should import
     the first `omega-…txt` once as a test (record / series codes in the company's Omega settings).

22. **hbkpravotice + bnhl ported into the framework** (2026-11-20, owner: "musia byť kompatibilné s naším ERMA
   WebApplication, presuň ich do applications"). `src/applications/hbkpravotice/` and `src/applications/bnhl/` = copies
   of `StadiumControl/WebServer/webapp/WebApp_*` (StadiumControl = source of truth, newer than the FTP snapshot: bnhl
   `Module_Matches` filter-before-limit + stable sort; FTP-only `Module_Table.php` unused → not taken). bnhl
   `assets/bat/` (template contact form + own PHPMailer/ReCaptcha, forms commented out, PHP in app folders is blocked
   anyway) not taken. **The originals in StadiumControl are left in place** (live still runs them until the deploy;
   deleting them is the owner's call / commit).
   - Old pages unchanged; they run through the new `WebApplication\Legacy\OldCoreApplication` (API of the oldest core
     on the framework — README "Apps of the OLDEST core") + global `User` / `ICRUD` (`framework/Legacy/oldcore/`).
     Entry classes `HbkpravoticeApp`, `BnhlApp` (`App.php`); config: both `dbPrefix hbkpravotice_`, `auth none`
     (logins in `hbkpravotice_users`, framework session), bnhl optional `stadiumControlApi` (default
     https://hbkpravotice.sk/StadiumControl — bnhl reads games / players / teams over HTTP from hbkpravotice's
     StadiumControl API; dev points to the dev copy so tests never read production). Example in config.template.php.
   - Mechanical changes (one-off script): `./core/*` includes removed (59), relative includes → `__DIR__` (61), old
     `implements IWebApp` removed (3), `#[\AllowDynamicProperties]` on 73 classes, absolute `https://bnhl.sk/WebApp_bnhl/`
     URL → `/applications/bnhl/`. Fixes: `Stats::__construct` optional-before-required (PHP 8), bnhl `/zapas/<not a
     number>` and unknown match id → 404 instead of a fatal TypeError (old bug), `Module_HeroSmall` image path absolute
     (broke on nested pages), old `User`: values escaped (SQL injection in user admin), `Update()` missing comma before
     `rights` (editing a user never worked) and new password not hashed.
   - Security: "remember me" now signed with `jwtSecret` + expiry (old core: key hard-coded in core/Application.php,
     no expiry) → existing remember-me cookies stop working once, users log in again. **The StadiumControl repo has
     the production DB host + login + password (commented) in `WebServer/webapp/Config.php` and the old JWT key in
     `core/Application.php`** — owner should remove them from that repo (and change the DB password if the repo was
     ever shared).
   - Dev: hbkpravotice_* tables (23) imported from the production backup 2026-11-18 into the dev DB; routes
     http://hbkpravotice.sk.192-168-0-11.sslip.io:8090/ and http://bnhl.sk.192-168-0-11.sslip.io:8090/ (dev/config.php).
     Checked on PHP 7.3 AND 8.4 (dev web switched temporarily): `dev/crawl-check.py` — bnhl 80 pages + 23 assets,
     hbkpravotice home + 25 assets, no errors; StadiumControl administration with a temporary user (deleted after):
     login, wrong password, all 10 sections + detail forms, remember-me in a new browser, logout; no PHP
     warnings/deprecations in the log. Tests: 56 pass on 7.3 + 8.4 (`tests/LegacyCoreTest.php`: page names as before,
     texts by appid, login / session / deleted user, remember token, User escaping + password change).
   - **Production switch** = part of the framework deploy (next tasks): production `config.php` routes
     `hbkpravotice.sk`, `www.hbkpravotice.sk` → hbkpravotice, `bnhl.sk`, `www.bnhl.sk` → bnhl + the two apps; the root
     static files (hbkpravoticetimer.html, livestream.html, … and `/fw`, `/api/device_sync.php` for the ESP32 devices)
     stay where they are; URLs of the sites don't change (`/StadiumControl?api=…` too).

23. **Apps in their own repositories + deploy package** (2026-11-20, owner: "apps should be separate repos"):
   hbkpravotice, bnhl → `StadiumControl/WebServer/apps/` (old `webapp/WebApp_hbkpravotice`, `WebApp_bnhl`,
   `WebApp_erma`, `core/`, `index.php`, `Config.php` with the DB password removed there — 511 files, all in its git
   history; the static root pages `webapp/*.html` now load `/applications/hbkpravotice/…`); erma → `Web_ERMA/app/`
   (Web_ERMA is NOT a git repo yet — its old `WebApp_erma/` differs from the StadiumControl history copy, left for the
   owner). `src/applications/{erma,hbkpravotice,bnhl}` removed here + .gitignore'd. **`apps.json`** = where each app
   lives (+ `root_files`); **`dev/apps.py`** `check` / `sync` (dev server ~/webapplication-dev/src) / `package NAME`
   (~/wa-deploy/NAME/www: framework + panel + apps + vendor + root files, index.php → index_wa.php, no config.php).
   `dev/make-prod-config.py` = production config.php with generated jwtSecret / cron_token (first install only).
   Phase A package `C:\Users\orras\ERMA-nasadenie\A-20261120\www` (897 files, 59 MB, config.php with TODO for DB +
   SMTP): built by the script, content verified equal to the hand-built one (only line endings + the fixed root pages
   differ), tested in a temp container on the dev server: hbkpravotice, bnhl, erma, panel login → dashboard; `/config/*`,
   `/vendor/*` → 403. Dev server re-synced with the script, crawl OK.

### Dev environment (home server)
- `~/webapplication-dev` (copy via `tar | ssh`, not a git clone), `dev/compose.yml`, web **http://192.168.0.11:8090/**:
  `/` = erma, `/panel` = employee section, `/demo` = framework demo. Dev login everywhere: **admin / admin**.
- Dev DB contains the shop tables `ps_*` imported by `dev/import-shop.sh` from `~/webstore/backups/database-*.sql.gz`
  (2 460 orders, customer e-mails anonymised, stats tables empty). `dev/seed.php` creates `<prefix>client/token/log/text`
  tables + admin user for every configured app.
- Dev DB has test invoicing data from 2026-11-19: company "ERMA Group s.r.o." (OR registration marked TEST, VAT payer
  from 2027-01-01, e-shops Howies Hockey + ERMA), FA2026/000198 (order 2481), draft invoice 2, user `sklad` (only
  orders). To start clean: drop the `panel_*` tables except client/token/log/text (+ delete other users) — the migration
  recreates them on the next request.
- The web container can't write `src/var/log` (folder owned by uid 1000, Apache runs as www-data) → app log is empty on dev.
- Sync after local edits: `cd C:\Users\orras\git\WebApplication && tar --exclude=.git -czf - . | ssh … 'cd ~/webapplication-dev && tar -xzf - && <strip CR from php/yml/sh/css/.htaccess>'`.

### Waiting for the owner
- Commit of everything after `dce2116` (owner commits himself).
- Final path of the employee section (now `/panel`); whether invoices are e-mailed to customers.
- NOT waiting (owner 2026-11-19): OR registration, first invoice number and series format are settings — the owner fills
  them in himself later; don't ask again. Invoice from an order is created **manually** (button) + fully manual
  invoices — both exist.
- (Not answered yet: can the hosting set PHP version per folder/domain? → PHP 8 for the framework before the shop upgrade.)

### Next tasks
0. **Deploy + maintenance plan: docs/nasadenie-a-udrzba.md** (2026-11-20): phase A framework + panel + erma +
   hbkpravotice + bnhl (switch = rename root index.php, rollback the same), B shop legal fixes (optional), C shop 8.2.9
   (needs A first: add_faktura goes), D clean-up + DB password change; maintenance rules and calendar. To build for A:
   deploy package from the dev server (with vendor, without demo/config.php), production config.php with empty
   passwords + generated jwtSecret, post-switch checklist.
1. **Invoicing — next:** sending e-invoices through a Peppol access point (provider per company, API differs per
   provider — when the owner chooses one; receiving e-invoices too); ISDOC, CSV; PAY by square logo per the SBA logo
   manual (now a text caption — the manual's links on sbaonline.sk returned 404 on 2026-11-19).
2. **Shops — next:** Omega stock export (T02/T03) for native warehouses; maybe later: more order actions in the shop
   (tracking number, messages). **PrestaShop 8.2.9 checked 2026-11-19** (shop rehearsal dev3, Web_store
   docs/upgrade-plan.md): the SQL reader (ShopOrders) and all Webservice parts (stock, product settings with the safe
   PUT, orders via Webservice incl. multistore, order state change) work unchanged; PS 8 dropped
   `ps_orders.shipping_number` (not used by the panel). **PrestaShop 9.2.1 checked 2026-11-19** (dev4, "Rehearsal 3"):
   the same works after one fix — PS 9's Webservice marks the product field `new` read-only, so `ShopProducts` now
   takes the read-only fields from the shop's schema (verified on 9.2.1 and 1.7.6.7). Facts from the dev copy of the shop DB: PrestaShop 1.7.6.7, simple stock management (no advanced
   stock / warehouses), stock per SHOP GROUP (`ps_stock_available` id_shop = 0): group 1 = AmerickeSPZ.sk + CarPlates
   (shared stock), group 2 = Howies Hockey, group 3 = ERMA; 221 combinations. The old `sklad/` app on the hosting is a
   former ModaUSA.sk consignment stock (own `stock_*` tables) — unrelated. Writing stock into PrestaShop would lift the
   "panel never writes into ps_*" rule → do it only through the PrestaShop Webservice API, never by direct SQL.
   **Invoice samples (owner's files, NOT in the repo — contain customer data):** `C:\Users\orras\Desktop\FA2026-000198.pdf`
   = the owner's target design (described in the spec, chapter 4); `FA2026-000214.pdf` = an invoice from the OLD
   PrestaShop invoicing (TCPDF, broken number `#FA2025/0001572026/000214`) — only an example of what is being replaced.
3. Port hbkpravotice and bnhl (use `getLegacyDatabase()`; see README "Porting").
4. Deploy procedure to the hosting: zip + unpack script like the shop's tools; never overwrite the root `.htaccess`;
   `config/config.php` created on the server; first admin account on production (no SSH → one-time setup page or
   SQL in phpMyAdmin); verify `.htaccess` deny rules behind the hosting's nginx.
   **Done 2026-11-20 (phase A switched):** all sites OK, deny rules work (403). Panel first gave 500 — migration 001
   reads `{p}client`, which only `dev/seed.php` created → new `sql/000_framework_tables.sql` (framework tables
   client/token/log/text; on an existing install it is skipped because MAX(version) ≥ latest) + test "migrations on an
   EMPTY database" + e2e replay on the dev server (half-applied 001 → fix → migrations 0–12, setup, login, dashboard).
   First admin: `SetupController` `/setup?key=<SetupController::key(jwtSecret)>` — only while no employee account
   exists (then 404 forever), owner picks his own password. Every new migration must work on an EMPTY database.
   **Login = e-mail** (owner 2026-11-20): `EmployeesController::normalizeEmail/emailError` (lower-case, FILTER_VALIDATE_EMAIL)
   in setup, employee form and login; the login field is plain text, so old dev accounts like `admin` still log in.
   **TODO at deploy (owner 2026-11-19) — invoices only from the panel:** in the PrestaShop admin set **Orders →
   Invoices → "Enable invoices" = No** (`PS_INVOICE` = 0) for all shops. Today (dev1 copy of 18. 11.) it is ON: the
   shop still numbers its own invoices and attaches its PDF to the state e-mails (states with "attach invoice":
   Platba akceptovaná, Odoslané na dobierku, Zaplatená a odoslaná, Prebieha príprava, Kontrola objednávky, Prevzatá
   osobne, Pripravená k vyzdvihnutiu …) → a customer would get a second invoice with another number. It is a shop
   SETTING, not a code change. After switching it off, check on a copy that the state e-mails go without an attachment.
   Also at deploy: Webservice key for the panel in the shop (permissions as in the connection form), connection in
   Sklad → E-shopy with "this database" checked.
5. Real erma.sk website (later, owner will decide content/design).
6. **Task management in the panel** (owner 2026-11-19: wanted in the future, "like Asana / Trello"; not started).
   Design notes so earlier work does not block it: projects (board) → columns/states → tasks (title, description,
   assignee(s), due date, priority, labels, checklist, comments, attachments, watchers); views list + kanban (drag &
   drop in plain JS) + "my tasks"; tasks linkable to other records (shop order, invoice, customer, company) via one
   generic link table (entity type + id); one shared audit/activity log for invoicing and tasks; e-mail notifications
   (needs employee e-mail + a mail sender shared with invoicing); attachments stored outside the web root / denied by
   .htaccess. Shared hosting = no websockets → refresh by polling (e.g. every 30 s), which is enough for a small team.

### Notes
- Shop side (PrestaShop upgrade, dev copies, deploy plan): `C:\Users\orras\git\Web_store` (HANDOVER.md there).
- When the panel invoicing works in production: disable PrestaShop invoices + uninstall `add_faktura` (removes 4 overrides).
- Print view choices made without the owner (easy to change): VAT columns hidden for a non-payer (the sample showed
  "N / 0.00"), Slovak number format `800,00 €`, VS default = digits of the number (`2026000198`; the sample had
  `000198` → set the series' VS format to `{NNNNNN}` if wanted), a "Dátum dodania" box added under "Dátum vystavenia".
