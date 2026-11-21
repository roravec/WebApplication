# Handover — state of work

Read CLAUDE.md first (standing facts), then this file. Update this file whenever a task is done or a decision is made.

## Last update: 2026-11-21

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
   (official doc "API Bankovnictví" 16. 10. 2025: `periods/{token}/{from}/{to}/transactions.json` → `Fio::parseJson`
   → the same structure as `BankStatement::parseCamt053`; 1 request / 30 s, HTTP 409) → the same review + pairing as
   the file upload. ref = Fio "ID pohybu" (column22). **Not `sba_xml`** — see item 35. Token never in
   messages/logs/pages (checked). Tried with the real ERMA token 2026-11-21 (item 35).
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
     the next month); optional cron URL `/panel/cron/accounting?token=<apps.panel.cron_token>` (404 without a token) — also sends the monthly summaries.
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
24. **Phase A LIVE (2026-11-20)** — owner uploaded + switched; all sites OK (also erma.sk without www). config.php
   filled by Claude on the owner's request (molesk login from the hosting configs, SMTP info@erma.sk / active24 from the
   AmerickeSPZ shop settings; never printed). Backup before: ps-pack in the web root, both databases
   (`$EXTRA_DATABASES` for ermaskretail) → home server `/mnt/storage/erma/backups` (RAID1; SSD extended by the owner).
   Findings: `ermaskretail` already holds `aqua_*`, `lcs_*`, `branchmanager_*` (only ADD tables there);
   aqua.erma.sk / mobileservice.erma.sk have their own folders on the hosting. Panel fixes after the first start: item
   in Next tasks 4 (000_framework_tables.sql, /setup, login = e-mail). Web_ERMA is a git repo now.
25. **Shop go-live + A2 ready (2026-11-20)** — Web_store tools + rehearsal on a simulation of the hosting
   (Web_store `docs/upgrade-plan.md` „A′“, „C. Go-live day“; here `docs/nasadenie-a-udrzba.md` phase C). A2 is done by
   `erma-deploy.php` at the switch (copies panel_* + hbkpravotice_*, rewrites config.php database, rollback restores).
   Waiting for the owner's evening.
26. **LIVE 2026-11-20 evening: PrestaShop 9.2.0 on PHP 8.5 in `ermaskretail` + A2** (owner: "the goal is the newest
   version, 8.2.9 is only a step"). Round 1 = 8.2.9 (switched, then rolled back), round 2 = 9.2.0 from the same frozen
   data (Web_store `devserver/upgrade-run9.sh`: ps_mbo removed completely — its 2.4.1 services break the PS 9 container;
   old GoPay out; official GoPay `prestashopgopay` 1.0.9 in with the accounts copied from the old module per shop
   (`gopay-migrate.php`); its SDK needed a patch, `gopay-guzzle-patch.py` — blockreassurance / productcomments ship
   Guzzle 5). Hosting: PHP is set PER HOSTNAME (aliases kept 7.3 until support switched them; afterwards their SSL
   certificates had to be re-issued). Whole framework (panel, hbkpravotice, bnhl, erma) runs on PHP 8.5 + ermaskretail.
   Old tools admin/, sklad/, beta/ on PHP 8.5: not verified (owner checks). erma_cod 1.0.2: the order summaries show
   the COD fee + total while "Dobierka" is selected (consumer law: total price before ordering).
27. **Panel — invoice from the order page** (owner 2026-11-20: a button to generate the invoice "when I'm sure the order
   is OK", a second one to send it, and the e-mails the customer got must be visible so nothing goes twice):
   order detail → "Vystaviť faktúru" (draft from the order + issued at once; "Pripraviť koncept na úpravu" stays),
   "Poslať faktúru zákazníkovi" (company e-mail text; sent before → only via "Poslať znova" with confirmation),
   "E-maily zákazníkovi" = panel invoice e-mails + the shop's own mail log (`ps_mail`, PS_LOG_EMAILS is on; matched by
   recipient + date ≥ order; SQL source only). Invoice stamp: already in Firmy → firma (logo + pečiatka, printed beside
   "Vystavil"). Tested on dev (issue → FA2026/000200 → sent via Mailpit → second send refused).
   **E-mail in the e-shop's look** (owner: the customer must not be surprised who sends what invoice): documents from a
   shop order → HTML e-mail with the shop's e-mail logo (PS_LOGO_MAIL / PS_LOGO of that shop, fetched from its web,
   embedded as cid:shoplogo; name only when unavailable), sender name = shop name (address stays the company's),
   texts `Companies::SHOP_MAIL` while the company kept the default texts ({shop}, {shop_web} usable in own texts),
   footer "Faktúru vystavuje {company}, prevádzkovateľ e-shopu {shop}"; plain-text alternative. `ShopOrders::brand()`.
   **Invoice preview in Firmy → firma** ("Náhľad" / "PDF"): `InvoiceRenderer::sample()` — today's data, logo, stamp,
   first active account, nothing saved.
   **Next (owner 2026-11-20, all wanted, "as many easy things as possible"):** order editing (products / quantities /
   prices / carrier / addresses / note), shipping (Packeta + post: shipments, labels, tracking, bulk states), returns
   + credit notes (incl. GoPay refund), products / prices / discounts / categories for all shops. Writes ONLY through
   the PrestaShop Webservice (order line editing is limited there → may need an own PS module endpoint — decide).
28. **Správa firmy** (owner 2026-11-20: "think it through and build a polished system for running the company"):
   design + phases in **docs/sprava-firmy-specifikacia.md** (A sales & margin, B purchase prices, C received documents /
   costs, D order costs, E result & cash flow & receivables / payables & VAT-registration turnover, F dashboard).
   Phase 1 (A) done: Výsledky → Predaje a marža, permission `reports.view`, test, verified on dev. Phase 2 (B) done: Výsledky → Nákupné ceny
   (writes wholesale_price via the local connection's Webservice, queue in the session, audit), company setting
   costs.purchase_vat (non-payer + VAT). Phase 3 part C1 done: **Náklady** (`/expenses`, rights `expenses.view/edit`)
   — received documents with files (PDF/photo/XML, in the DB), supplier autofill + RPO, duplicate check, paid / unpaid,
   cancel with reason (never deleted), categories with kind (goods / order / overhead), CSV; they go into the monthly
   accountant package (`prijate/` + CSV, `accounting_package.expense_ids`). Migration 013. Test + verified on dev.
   Phase 3 part C2 done (migration 014): statement import pairs OUTGOING payments with unpaid costs (VS, else supplier
   IBAN + amount) or creates a new paid cost (bank fee); `expense.bank_ref` unique; import page now for invoices.edit OR
   expenses.edit. Recurring costs (Náklady → Opakované, `expense_recurring`, `Expenses::generateRecurring` — run on the
   Náklady list, dashboard/cron via AccountingPackage::sendDue, manual package send). Tests 60/60, verified on dev.
   Phase 4 (D) done (migration 015): Výsledky → Náklady objednávok (`order_cost_rule`: shipping by carrier id_reference,
   payment by module, packaging; fixed + %; valid from/to; most specific rule wins) → Predaje a marža show the
   contribution margin (cards, month/shop columns, CSV). `Reports\OrderCosts`. Tests 61/61, verified on dev.
   Phases 5 (E) + 6 (F) done (migration 016): Výsledky → Výsledok firmy (`Reports\BusinessResult`: profit by month vs.
   last year, by e-shop, order costs real vs. estimate), Výsledky → Peniaze (`Reports\Money`: receivables/payables aging,
   in/out by month), VAT registration turnover (`Reports\VatTurnover`, thresholds = data in Legislatíva,
   `vat_registration_rule`), dashboard "Treba urobiť" + company cards (`Reports\Todo`), monthly summary e-mail
   (`Reports\MonthlySummary`, company setting Firmy → Výsledky, `monthly_summary`; dashboard + cron URL). Tests 62/62,
   lint 7.3/8.4, all pages verified on dev, summary delivered to Mailpit. ALL modules A–F of the spec done.
29. **Order + shop management** (owner 2026-11-20/21, item 27 "next"): order detail — tracking number (+ state/e-mail),
   change of delivery / invoice address (new address + order PUT), internal notes, message to the customer in the shop's
   look (`Panel\ShopMail`), Zásielkovňa pickup point (read from `ps_shaim_zasilkovna_widget_data`), delivery note PDF;
   returns (restock via Webservice, partial credit note draft, state); Objednávky → Expedícia (bulk PDFs, tracking
   numbers, states); products: price, active, combination price impact, sales (specific_prices). Verified on dev1
   (1.7.6.7) through the panel and on dev4 (9.2.1) with a Webservice probe; **every call carries id_shop** (PS 9) —
   also fixed `ShopOrdersApi::find` for PS 9. Order lines: item 32. NOT done: Packeta shipments (after the official
   Packeta module). docs/sklad-specifikacia.md.
   Live Webservice key needs the new permissions (list in the connection form). Migration 017.
30. **Invoice look** (owner 2026-11-21: all data like the old shop invoices + closer to ermavzor): template
   `invoice-2026-2` (docs/legislativa.md), bank name per account (migration 018), legal texts delivery_note / thanks_shop.
   Pickup point NOT on the invoice (owner). ZIP of the accountant package dated the month end → re-sent = identical.
31. **PHP 8.5 only** (owner 2026-11-21: "už nemusíš testovať nič na 7.3"): dev default + web :8090 on PHP 8.5, tests
   64/64 on 8.5, `curl_close` (deprecated in 8.5) removed; hbkpravotice / bnhl / erma crawled on 8.5 without errors.
   Legal PDFs for order e-mails regenerated with Open Sans (Web_store, diacritics looked like another font).
32. **Order lines + carrier editable in the panel** (owner 2026-11-21: "v paneli sa stále nedajú upravovať objednávky";
   asked: own PS module vs. Webservice only → **Webservice only**). Order detail → "Upraviť položky a dopravu"
   (`/orders/…/lines`, `orders.edit`): quantity (0 = remove), unit price with VAT, add product (datalist from
   ShopStock::rows; empty price = shop price via the Webservice `price[...]` fields for the delivery country), carrier +
   shipping price, optional stock by the difference (`stock.edit`). `Shop\ShopOrderLines` mirrors PS 1.7.6
   AdminOrdersController edit: order_details PUT/POST (copy of an existing line)/DELETE, orders PUT totals by the
   difference (discounts / paid stay), order_carriers PUT (carrier, price, weight); refuses when the shop's order differs
   from the form; checks every answer; note + audit. Limits: order_detail_tax not recalculated (only PS invoices use
   it), % discounts not recalculated, reserved stock recomputed by PS at the next state change. Key needs
   **order_details GET+POST+PUT+DELETE** (form + `Web_store/devserver/ws-key.php` updated). PUT nulls fields missing
   from the XML → hidden `tax_rate`/`tax_name` are sent back from the SQL source.
   **Bug fixed on the way:** SQL source (`ShopOrders::find`) used `order_detail.tax_rate` as the VAT rate of invoice
   lines — PS 1.7+ never fills it (always 0) → a VAT payer's invoice from an order would have had 0 % VAT; now the rate
   comes from the prices when the stored one is 0 (same as ShopOrdersApi).
   **NOT verified yet:** written 2026-11-21 while the dev server was unreachable (ping OK, ssh / :8090 / :8081 timed
   out). **Tests 65/65 OK on 2026-11-21** (temporary local setup, see "Dev environment"). TODO: e2e on **PrestaShop 9 only** (production runs
   9.2.0 since 2026-11-20, item 26; owner 2026-11-21: 1.7.6.7 and 8.2.9 no longer matter) — dev4 (9.2.1) or a copy of
   production: qty / price / remove / add / carrier, check the order in the shop admin (totals, lines), PS 9 accepts
   order_details PUT/POST/DELETE and the orders totals PUT; re-run ws-key.php there.
33. **Invoice numbers continue the shop's + mistakes** (owner 2026-11-21: a test invoice on production got FA2026/000001
   — the series had no "next number" set; PrestaShop issued FA2026/000001–000215 in 2026, the 20. 11. backup's last =
   215 of 19. 11.). Owner sets Firmy → ERMA Group → Číselné rady → Faktúra → Ďalšie číslo = last shop number + 1 (check
   the shop admin for invoices after 19. 11.). New admin-only **"Vrátiť do konceptu"** on an issued document
   (`Invoices::unissue`, conditions in `unissueErrors`, docs/legislativa.md): FA2026/000001 was not sent → use it once
   deployed. Omega export got **"Len na skúšku"** (doesn't set omega_exported_at — otherwise a test export would keep
   the documents out of the monthly package's Omega file and block the revert). Invoice preview (browser) = A4 sheet
   with the footer at the bottom like the PDF (screen CSS only, both templates). Test "issued by mistake" passes
   (2026-11-21, temporary local setup).
   **DEPLOYED 2026-11-21 by the owner** (uploaded the changed panel files via FTP himself) and **used on production:
   FA2026/000001 went back to a draft with the button — works.** Owner's next step: Ďalšie číslo = last shop number + 1
   (216 unless the shop issued more after 19. 11.), then issue the draft again. Series must have Nulovanie = „každý
   rok“ (default) → FA2027/000001 starts by itself (counter per year, by issue date).
   **Careful:** App.php registers the routes of item 32 too (`callable` type → the panel would not start with a missing
   method), so the order-lines code ("Upraviť položky a dopravu") is most likely on the hosting as well — it is
   UNTESTED; don't use it on live orders until verified on PS 9 (item 32 TODO). The live key has no order_details
   write rights yet, so line changes would fail with "kľúč nemá oprávnenie" — but a carrier / shipping-price-only
   change would go through (order_carriers + orders PUT are allowed).
34. **"V objednávke nemôžem meniť nič"** (owner 2026-11-21, production): every change goes through the shop's Webservice
   → needs Sklad → E-shopy connection with key, Aktívne + "Toto je e-shop, ktorého tabuľky sú v databáze panela"
   (= `localConnection()`); without it the panel hid the buttons silently. Live Webservice is ON (howieshockey.sk/store/api/
   → 401 asking for the key, checked 2026-11-21; plain curl gets HTTP 466 from the hosting's proxy — use a browser UA).
   Now the order detail says why changes are not possible (`OrdersController::show` → `editBlocked`). Waiting: owner
   creates the connection (key in PS admin → Rozšírené parametre → Webservice, permissions = list in the form).
   **Discount on an order** (owner 2026-11-21 "urob aj tú zľavu"): in "Upraviť položky, dopravu a zľavu" — the order's
   total discount in € with VAT or % of the products (after the line changes), replaces the current one;
   `ShopOrderLines::planDiscount` + `totals()` (paid = products + shipping − discount; without VAT in the products'
   ratio). Webservice has no order_cart_rules → only the totals change (no named voucher in the shop admin); the
   panel's invoice from the order takes total_discounts_*. Test in "order lines…"; UNTESTED like the rest of item 32.
35. **Fio download → HTTP 500** (owner 2026-11-21, production, "token je určite dobrý"). Official doc (API Bankovnictví
   16. 10. 2025, ch. 8): **500 = token does not exist or is not active** — usable only 5 min after its strong
   authorization (all signers on a multi-signature account), validity max. 180 days unless auto-extended at each login,
   one token = one account; 422 = data older than 90 days without unlocking the history (Nastavenia → API → lock, 10 min);
   413 = more than 50 000 movements. `Fio::movements` now says exactly that; Firmy → bank account accepts only a
   64-character alphanumeric token (whitespace from pasting removed).
   **Real cause (found 2026-11-21 with the owner's token, active in internetbanking since 20. 11.):** the token was
   fine — `transactions.json` answered 200, but `transactions.sba_xml` on `/periods/` answers **HTTP 500
   `<errorCode>6420</errorCode> "Chyba aplikace"`** (SBA XML exists only for official statements `/by-id/`). Fix:
   `Fio::movements` downloads JSON and converts it (`Fio::parseJson`, test "Fio JSON (periods) …"); on an error it
   now shows Fio's own message + code, so a format error no longer looks like a bad token. Verified on dev (PHP 8.5)
   with the real token: 23 movements, incoming ones with VS/KS/SS, IBAN, name, message; outgoing ones have VS + IBAN
   (Fio sends their name/message empty). **Files to upload: src/applications/panel/src/Invoicing/Fio.php,
   src/applications/panel/src/Controller/BankImportController.php** (+ CompaniesController.php from the earlier step
   if not uploaded yet).
36. **State change sent no e-mail** (owner 2026-11-21, "Zaplatená a odoslaná"): PrestaShop sends the e-mail of a state
   only when the STATE has send_email = 1 — backup 20. 11.: state 4 "Zaplatená a odoslaná" has template `shipped` but
   send_email = 0 (also 1 "Odoslané na dobierku"; 36 "Odoslané na dobierku (notify)" sends `in_transit`). Fix = in the
   shop admin (Objednávky → Stavy → tick "Poslať e-mail zákazníkovi") or in the panel once state editing exists. The
   panel now reads send_email and marks such states "(bez e-mailu)" in every state picker; checkbox label "ak ho stav
   posiela". **Order states editable in the panel — built 2026-11-21** (owner "urob tú úpravu stavov"): Objednávky →
   "Stavy objednávok" (administrators; `/orders/states?src=`), `Shop\ShopOrderStates` + `OrderStatesController`, views
   orders/states.php + state-form.php: list (marks "šablóna nastavená, ale vypnutá"), edit / new: Slovak name + e-mail
   template (other languages stay; a new state gets them in all), colour, send_email, paid, shipped, delivery,
   pdf_delivery, logable, hidden; invoice / pdf_invoice / module_name / unremovable never touched, no delete. Key needs
   **order_states GET + PUT + POST** (form + ws-key.php). Test "order states in the shop…"; 66/66 locally. Not tried
   against a real PS 9 yet (Webservice blank schema for a new state = assumption).
37. **Discount on an invoice** (owner 2026-11-21 "pri vystavovaní faktúry neviem dať zľavu"): it always worked as a line
   with a negative price (like the invoice from an order), but the form didn't say so → invoice form now has "Zľava
   [value] [% z položiek | € (bez DPH)] Pridať zľavu" (js/panel.js `addDiscount`): negative line(s), one per VAT rate /
   category of the positive lines (% of each rate's base; a fixed amount split by the bases, rounding on the last),
   editable like any line. Not for credit notes. Panel CSS / JS now carry `?v=filemtime` (views/layout.php, login.php)
   so a browser loads changed files right after an FTP upload. JS not machine-checked (no node locally) — read through.

38. **Phase 0 of the panel plan DONE** (2026-11-21, docs/panel-architektura.md): **modules** — `src/Module.php`
   (routes, nav, settingsTabs), `src/Modules/*Module.php` (Dashboard, Orders, Eshops, Customers, Invoicing, Expenses,
   Reports, Stock, Companies, Legislation, Employees, Help, Settings); `App.php` only lists them; `src/Navigation.php`
   builds the grouped menu (Prehľad · Predaj: Objednávky, E-shopy, Odberatelia · Financie: Faktúry, Náklady, Výsledky ·
   Sklad · Nastavenia) filtered by permissions and the tabs above pages (section `"key:tab"` in `PanelContext::page`;
   guard accepts an array = any of the permissions). **Moved**: e-shop products / stock `/stock/shops/…` → `/eshops/…`;
   connections → `/settings/eshops` (+ `/new`, `/{cid}/edit`); order states `/orders/states` → `/settings/eshops/states`;
   order cost rules `/reports/order-costs` → `/settings/order-costs`; old GET URLs redirect (301). Nastavenia = tabs
   Firmy · E-shopy · Náklady objednávok · Legislatíva · Zamestnanci (`/settings` → first allowed). Faktúry tabs:
   Faktúry a dobropisy · Úhrady (výpis, Fio) · Pre účtovníka · Export do Omegy. **Pomocník**: "? Pomocník" on every page
   (`/help/for?page=<template>&section=…`), `/help` index, 37 guides in `src/applications/panel/help/*.md` (simple
   Slovak, step by step) mapped in `help/_index.php`, rendered by `src/Markdown.php` (escaped subset); a test fails
   when a page has no guide or a guide link is broken. **Developer guide** `docs/framework/README.md` (README links it).
   Verified locally: tests 67/67 (PHP 8.5.13 + MariaDB), every main page + old-URL redirects + all guides rendered on a
   local PHP server (status 200, no PHP warnings). **Deploy: upload the whole `applications/panel/` folder** (new
   `help/`, `src/Modules/`, `src/Navigation.php`, `src/Module.php`, `src/Markdown.php`, many changed files; it contains
   the untested order-lines code of item 32 too — still don't use it on live orders).
39. **Phase 1 step a — permissions DONE** (2026-11-21, docs/panel-architektura.md chapter 5 "Stav"; owner decided: roles
   apply in all companies of the person; e-shops can be limited per person, default all). Phase 1 split into steps
   1a permissions → 1b Ref + QR, links, activity, comments → 1c events, notifications, scheduler → 1d files (Nextcloud)
   → 1e numbering + PDF templates, calendar, search → 1f API keys, integrations.
   - Code: `src/Core/Access.php` (the one check: `can`, companies, e-shops; Správca = rights ≥ 100 = everything),
     `src/Core/Roles.php` (roles, a person's access array, `effective`, `beyond` = "nobody gives more than he has",
     `describeChange` for the log), `src/Core/Permissions.php` (register from `Module::permissions()` — new interface
     method, all 13 modules). `Panel\Rights` = only key constants now (+ new `settings.employees`, `settings.eshops`,
     `settings.legal`, `companies.create`, `invoices.unissue` replacing every `ADMIN_ONLY` guard / `isAdmin()` check).
     `PanelContext::access()` (was `rights()`), `allCompanies()`, `canShop()`, `shopVisible()` (e-shop scope + its
     company; e-shop without company = all-companies people only); `OrderSource::restrictShops()` in `ShopOrders` (SQL
     condition) and `ShopOrdersApi` (shops / find / search / stats, cache keys per scope), applied by PanelContext to
     every order source of a logged-in person; `ShopsController` (menu E-shopy) checks the shop of every page.
   - Migration `019_roles.sql`: `role`, `role_permission`, `client_role`, `client_scope` (all_companies, all_shops),
     `shop_access`; `permission.allow` (1 extra / 0 denied). Old per-person permissions stay as "allow" exceptions,
     companies unchanged, e-shops = all → no access lost or gained (test). 4 offered roles, nobody assigned.
   - UI Nastavenia → **Zamestnanci a roly** (tabs Zamestnanci · Roly · Kto čo smie): employee form (Správca, roles,
     exceptions table with live result `js/panel.js` syncPerms, companies all/selected, e-shops all/selected, history),
     role form (permissions grouped, sensitive badge, holders, delete with confirm, history), overview matrix.
     Read-only + refusal when the target / role has more than the editor; self-protection (own Správca, own
     settings.employees, deactivation). Every change → `audit` entity `employee` / `role`.
   - Guides: `help/zamestnanci.md` (rewritten), new `help/roly-a-prava.md`, glossary, first steps, „len správca“ texts
     replaced by the permission names. Developer guide: new `docs/framework/panel-modules.md` (module step by step,
     permissions rules, checklist).
   - Verified on dev (PHP 8.5): tests 72/72; e2e over HTTP on :8090 (29 checks: role + restricted employee sees only
     Howies Hockey orders, foreign order 404, forbidden pages 403, a non-admin manager can't give invoices.edit / all
     e-shops / put a permission he lacks into a role, can't touch Správca, can't drop his own settings.employees, his
     change is in the history, state change 403 after the deny); smoke of 32 pages for Správca + 2 limited users, no PHP
     warnings. Test accounts removed from the dev DB afterwards.
   - **Deploy: upload the whole `applications/panel/` folder** (new `src/Core/`, changed modules, controllers, views,
     `css/panel.css`, `js/panel.js`, help, `sql/019_roles.sql` — runs by itself on the first request). After deploy:
     open Nastavenia → Zamestnanci a roly, check "Kto čo smie" (everyone must have the same access as before).
40. **Phase 1 step b — records DONE** (2026-11-21, docs/panel-architektura.md chapter 4 "Stav — krok 1b"):
   - `src/Core/Refs.php` — reference `type:id`; `Module::recordTypes()` (new interface method, all modules) registers
     type, label, parts, `resolve` (title + URL, null = missing OR not allowed — access check inside), `lookup`. Types:
     invoice, order (`order:<source>:<id>`, via new `OrderSource::brief()`), expense, customer, company, stock_item,
     stock_doc, warehouse, employee, role. `Refs::find()` for linking (reference, short link, lookups).
   - `src/Core/Links.php` (table `link`, kinds relates / blocks / verifies / derived / replaces, label per side),
     `src/Core/Comments.php` (table `comment` + `comment_mention`; @mention = "@Full Name" of an active employee,
     references in the text become links only for readers who may see them, author / Správca edit, soft delete),
     `src/Core/Activity.php` (history per record + feed, filtered by `resolve`). `Audit::log(..., ?string $ref)` — derived
     for simple types, passed for orders; new actions comment / link / unlink.
   - `RecordsModule` + `RecordsController`: `/r/{ref}` (redirect), `/r/{ref}/qr` (SVG), `/r/{ref}/label` (printable
     label), `/comments` (+ edit / delete), `/links` (+ delete), `/links/lookup` (JSON), `/activity` (feed). Dashboard
     card "Čo sa deje". `PanelContext::refs() / links() / comments() / activity() / recordPanel()`.
   - Shared box `views/core/record.php` (Komentáre · Prepojenia · História + short link + QR label) on order, invoice,
     expense, stock item, stock doc, customer, company, employee, role pages (replaced their own history tables).
     `js/panel.js`: @mention suggestions, "Prepojiť" lookup. Order notes → comments (`OrderLog` keeps its API).
   - Migration `020_records.sql`: `audit.ref` (+ backfill; shop_order rows of a connection matched by its name, others
     = source 0), `link`, `comment`, `comment_mention`, old `order_note` rows copied into comments.
   - **Framework fix**: `Request::fromGlobals()` used `parse_url()`, which returns false for `/panel/r/invoice:7`
     (looks like host:port) → the request fell to the default app (erma). New `Request::pathFromUri()` + test.
   - Guides: new `help/komentare-a-prepojenia.md`, `help/co-sa-deje.md` (group "Všade"), objednavka, faktura-detail,
     prehlad, slovnik updated. Developer guide `docs/framework/panel-modules.md` chapter 4 "Records"; README common
     mistakes (parse_url, `{}` in router patterns).
   - Verified on dev (PHP 8.5): tests 76/76; e2e over HTTP (35 checks: comment with mention + reference link, link
     order ↔ invoice shown on both sides + history, duplicate refused, short link / QR / label, feed + dashboard,
     record box on expense / customer / stock pages; restricted colleague: sees the comment on her shop's order but
     not the invoice link (reference stays text), 404 on invoice short link / QR / foreign order, lookup finds no
     invoices, can't link an invisible invoice, can't edit someone else's comment, deletes her own, feed without the
     invoice); 14 detail pages without PHP warnings. Test data removed from the dev DB.
   - **Deploy: upload `framework/Http/Request.php` and the whole `applications/panel/` folder** (migration 020 runs by
     itself). Steps 1a + 1b can go together.
41. **Phase 1 steps c–f DONE — phase 1 (core) complete** (2026-11-21, docs/panel-architektura.md ch. 4 "Stav — kroky 1c–1f"):
   - **1c** `src/Core/Events.php` + optional module interface `ProvidesListeners`; emits: comment.added / comment.edited
     (RecordsController + API), link.added, invoice.issued, order.state_changed. `src/Core/Notifications.php` (table
     `notification`, `notify_pref`; notify() checks the recipient may see the record via `PanelContext::asUser`),
     listener in `SystemModule` (mentions + earlier participants of the discussion). Bell in the layout (count polled
     every minute, `/notifications`, open = mark read), e-mail mode in Môj účet (immediate / daily digest 7:00 / off).
     `src/Core/Scheduler.php` + interface `ProvidesJobs` (table `job_run`, lock, last result); jobs: notifications.email
     (every minute), notifications.digest, accounting.package + reports.monthly_summary (hourly; they decide what is
     due), expenses.recurring (daily 5:00). Triggers: `/cron?token=` (+ old `/cron/accounting`; AccountingController::cron
     removed) and the quiet tick `POST /cron/tick` from `js/panel.js` (≤ once a minute). Nastavenia → **Systém** (new
     permission `settings.system`): Plánovač · Integrácie · API kľúče. `Mailer::defaultFrom()`.
   - **1d** `src/Core/Integrations.php` (table `integration`, secrets via Panel\Secret, status), `src/Core/Nextcloud.php`
     (WebDAV + OCS shares; transport injectable for tests), `src/Core/Files.php` (`record_folder`, `file_share`; folder
     `Panel/<company>/<type label>/<type-id>`, upload / download / delete through the panel, share links: read or
     **upload-only file drop** — permissions verified after creation, a link with more rights is deleted again;
     found by the e2e: `publicUpload=true` had made the "upload" link readable for everyone). "Súbory" part of the
     record box loaded by JS (`/files/{ref}`). Resolvers return `company_id`.
   - **1e** `src/Core/Documents.php` + interface `ProvidesDocumentTypes` (series for module documents —
     `Numbering::allTypes()/tableFor()` include them; `Documents::allocate()`; company look PDF `views/pdf/document.php`),
     `src/Core/Calendar.php` + interface `ProvidesCalendar` (Invoicing: due dates of unpaid invoices; Expenses: due
     dates + recurring costs) → menu Práca → **Kalendár** (`/calendar`), **search field** on every page (`/search`,
     lookups + companies + employees).
   - **1f** `src/Core/ApiKeys.php` (tables `api_key`, `api_log`; `pk_<prefix>_<secret>`, SHA-256 only, expiry, revoke,
     120 calls/min), `ApiController` (`/api/v1/me`, `/search`, `/activity`, `/records/{ref}`, `/records/{ref}/comments`
     GET + POST; Bearer or X-Api-Key), `Access::limitTo()` + `PanelContext::asApiKey()` (never more than the author has
     at the moment). Own keys in Môj účet (permission `api.keys`), all keys in Nastavenia → Systém → API kľúče.
   - **Framework**: `Request::files()` (multiple uploads), Authorization header recovered from
     `REDIRECT_HTTP_AUTHORIZATION` / `getallheaders()` (Apache hid it → every API call was 401 in the e2e) + tests.
   - Migrations `021_notifications_jobs.sql`, `022_integrations_files.sql`, `023_api_keys.sql`.
   - Guides: `upozornenia`, `hladanie`, `kalendar`, `subory`, `nastavenia-system`, `api-kluce` (new), moj-ucet, nastavenia,
     prve-kroky, komentare-a-prepojenia updated. Developer guide `docs/framework/panel-modules.md` chapter 5 "Core
     services"; framework README (Request). Deploy doc: cron + Nextcloud setup.
   - Verified on dev (PHP 8.5): tests 83/83; e2e over HTTP 40/40 (scheduler page + run now + tick + cron 404; mention →
     bell 1 → open → read; e-mail job → Mailpit got exactly the unread one; preference; 403 for a key without
     api.keys; search + calendar; **real Nextcloud 31** in a temporary container on the dev Docker network: connect,
     upload 2 files (č in the name), list, download, duplicate → (2), upload link with password, revoke, delete, all in
     the history, 404 for an invisible record; API key: /me, record, out-of-scope 404, comment 201 + read back, search,
     bad key 401, overview with calls, revoked 401). Customer side of the links checked with curl: file-drop link =
     PUT 201, GET/DELETE 405, empty listing; read link = GET 200, PUT/DELETE 403; wrong password 401. Smoke 41 pages
     (Správca) + 41 (restricted: 17×200, 24×403) without PHP warnings. Temporary Nextcloud container + image and all
     test data removed.
   - **Deploy: upload `framework/Http/Request.php` and the whole `applications/panel/` folder** (migrations 019–023
     run by themselves; 1a–1f can go together). **Owner after deploy:** (1) cron on the hosting → URL from Nastavenia →
     Systém; (2) Nextcloud user `erma-panel` + app password → Nastavenia → Systém → Integrácie; (3) give `api.keys`
     only to people who need keys.
   - **LIVE since 2026-11-21** (owner uploaded framework + panel). Checked from outside: login 200, migrations ran (API
     answers 401 JSON, needs table api_key), `/panel/r/invoice:1` stays in the panel (parse_url fix), the Authorization
     header and X-Api-Key reach PHP through the hosting's nginx + Apache, cron URL with a wrong token 404.
     **Cron on the hosting set by the owner: every minute** (scheduler confirmed running in Nastavenia → Systém).
     **Nextcloud connected**: user `erma-panel` (created by the owner), app password created by Claude through
     `/ocs/v2.php/core/getapppassword` and entered by the owner in Integrácie — status "funguje". Passwords are not
     kept anywhere outside the panel / the owner.

42. **Phase 2 — tasks and projects DONE** (2026-11-21, docs/panel-architektura.md 6.1 + 6.11 "Stav"):
   - Owner's decisions: git on **GitHub, later GitLab**; a commit without a task number passes without a link; the task
     number is at the **start** of the commit message in a **configurable** format (setting `git.task_format`,
     `{TASK}` = LED-123, default `#{TASK}`; several numbers in a row allowed).
   - Code: `src/Tasks/Projects.php` (visibility: members incl. externals without company access, or `projects.all` +
     company; reader can't edit; owner / projects.manage manages; columns with "done"), `Tasks.php` (numbers per project
     with row lock, personal `U-n`, move + renumber, closed_at follows the done column, watchers, checklist, subtasks,
     search filtered in SQL, my tasks, reminders), `TimeEntries.php` (parse "1:30" / "1,5" / "45m", stopwatch),
     `Git.php` (format + `taskCodes()`, repos with encrypted webhook secret, GitHub HMAC / GitLab token, `receive()`
     dedupes commits, links by the format, author → employee by e-mail). Controllers `TasksController`,
     `TimeController`, `GitController`; `TasksModule` (routes, menu Práca: Moje úlohy · Projekty · Výkazy práce ·
     Kalendár, settings tab Projekty, permissions tasks.view / tasks.edit / projects.all / projects.manage /
     time.track / time.all, record types task + project, listeners task.assigned / task.status_changed /
     comment.added on tasks, job tasks.due_reminders 7:00, calendar). Kanban drag & drop in `js/panel.js` (POST
     /tasks/{id}/move, CSRF from data-csrf). `dev/git-hook-post-receive` (+ .gitattributes eol=lf).
   - `Notifications::notify` dedupe: mention + comment of one comment, or the very same text, within a minute = one notice.
   - Migration `024_tasks.sql` (project, project_member, project_column, task, task_check, task_watcher, time_entry,
     time_timer, git_repo, git_commit, task_commit; setting git.task_format; roles Vývoj, Externý spolupracovník).
   - Guides: `moje-ulohy`, `projekty`, `uloha`, `vykazy-prace`, `commity` (group Práca), nastavenia, slovnik.
   - Verified on dev (PHP 8.5): tests 86/86; e2e over HTTP 36/36 (project + duplicate key; task with assignee →
     notification; Moje úlohy; 404 for a foreign project / settings for a member; kanban AJAX move → closed + history
     + watcher notified; state select; checklist; comment → watcher notified; stopwatch + manual time; time to an
     invisible task refused; project hours; GitHub repo + ping + wrong signature 403 + signed push → commit at the
     task + history; GitLab wrong token 403 + push with the author matched; API repo + **real `git push` into a bare
     repo on the dev server through the post-receive hook** — first push, push to an existing branch, new branch);
     smoke 25 pages × 2 users without PHP warnings. Found + fixed during e2e: empty form fields on create (500), the
     hook skipped commits of a new branch (`--not --all`), dedupe swallowed a different notice. Dev data removed.
   - **Deploy: upload the whole `applications/panel/` folder** (migration 024 runs by itself). **Owner after deploy:**
     Nastavenia → Projekty → add the GitHub repositories (the panel shows the webhook URL + secret step by step);
     give people the roles *Vývoj* / projects permissions; employees' git e-mail = their panel login for author matching.

### Dev environment (home server)
- **Without the home server** (owner away, 2026-11-21): a temporary setup on the owner's PC in the Claude session's
  scratchpad (not installed, nothing in PATH, gone with the session): official PHP 8.5.13 NTS x64 zip (windows.php.net,
  SHA-256 checked) + MariaDB 11.4.14 winx64 zip (127.0.0.1:3307, utf8mb4_general_ci, db/user/pass webapp) →
  `DB_HOST="127.0.0.1;port=3307" DB_NAME=webapp DB_USER=webapp DB_PASS=webapp php tests/run.php` = **65 passed,
  1 skipped** (PDF — no composer vendor locally). Recreate the same way when needed; the home server stays the reference.
- **PHP 8.5** everywhere (= hosting): `cd ~/webapplication-dev/dev && COMPOSE_PROJECT_NAME=webapplication docker compose run --rm test`.
  Shop copies for Webservice tests: **dev4 (9.2.1, :8084) — closest to production 9.2.0, test here**; dev1 (1.7.6.7,
  :8081) = the old pre-upgrade state, still the panel's dev "local" connection (switch it to a PS 9 copy when
  convenient); key permissions set by `Web_store/devserver/ws-key.php` (keeps the existing key).
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
- Live Webservice key: add the new permissions (connection form lists them; now also order_details POST + PUT + DELETE
  for item 32) — otherwise tracking / address / sales / order lines fail with a clear "kľúč nemá oprávnenie" message.
- Firmy → bankový účet → Banka (e.g. "Fio banka, a.s.") — printed on invoices.

### Next tasks
0. **Deploy + maintenance: docs/nasadenie-a-udrzba.md.** State 2026-11-21: phase A (framework + panel + erma +
   hbkpravotice + bnhl) LIVE since 2026-11-20; **phase C + A2 (+ legal fixes B) LIVE since 2026-11-20 evening: the
   shops run PrestaShop 9.2.0 on PHP 8.5 in `ermaskretail`** (item 26; 8.2.9 was only a step, switched and rolled
   back). **PrestaShop 1.7.6.7 and 8.2.9 no longer matter (owner 2026-11-21)** — build and test shop features for
   PrestaShop 9 only. Left: phase D clean-up (old core, `/store_old/` + erma-deploy.php if still there, `molesk`
   password change), maintenance rules and calendar.
1. **Invoicing — next:** sending e-invoices through a Peppol access point (provider per company, API differs per
   provider — when the owner chooses one; receiving e-invoices too); ISDOC, CSV; PAY by square logo per the SBA logo
   manual (now a text caption — the manual's links on sbaonline.sk returned 404 on 2026-11-19).
2. **Shops — next:** Omega stock export (T02/T03) for native warehouses; maybe later: more order actions in the shop
   (tracking number, messages). [History — production is 9.2.0 now:] **PrestaShop 8.2.9 checked 2026-11-19** (shop rehearsal dev3, Web_store
   docs/upgrade-plan.md): the SQL reader (ShopOrders) and all Webservice parts (stock, product settings with the safe
   PUT, orders via Webservice incl. multistore, order state change) work unchanged; PS 8 dropped
   `ps_orders.shipping_number` (not used by the panel). **PrestaShop 9.2.1 checked 2026-11-19** (dev4, "Rehearsal 3"):
   the same works after one fix — PS 9's Webservice marks the product field `new` read-only, so `ShopProducts` now
   takes the read-only fields from the shop's schema (verified on 9.2.1 and 1.7.6.7). Facts from the dev copy of the shop DB (made on 1.7.6.7, the data came over to 9.2.0): simple stock management (no advanced
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
6. **Panel restructure + future modules — plan: docs/panel-architektura.md** (owner 2026-11-21: tasks like Asana with
   kanban + timeline, projects by the V-model with an API for test results, shared documents / collaborative writing,
   "poriadna správa práv pre všetko", "nech nerobíme špagety"; then "ultimate firemný nástroj" — electronics
   development, e-shops, maybe rental: parts + BOM from KiCad, purchasing, production + serial numbers, firmware,
   service / complaints, quotes + contracts + time tracking, rental, assets + calibration, automation, mobile scanning,
   git commits shown at tasks like codeBeamer). Shared master data: contacts, item catalog, units (serial numbers),
   numbering + PDF templates, calendar. Phases 0 restructure → 1 core (permissions …) → 2 tasks (+ commits) → 3 timeline
   → 4 documents → 5 V-model + test API → 6 contacts, quotes, simple complaints → 7 parts / BOM / purchasing →
   8 production + units → 9 service, firmware → 10 rental → 11 automation, mobile → 12 live co-editing. Added later the
   same day: **custom jobs (3D printing, engraving) as one container** ("Zákazka": customer files incl. from e-shop
   uploads and a customer upload link, proof approval, machine queue, price calculator — phase 6) and **user guides
   for everything** (Pomocník in the panel, `docs/pomocnik/`, part of every phase; phase 0 writes guides for all
   existing features). Then also: **Pošta** (company mailboxes via IMAP in pure PHP, mails auto-linked to orders /
   jobs / complaints), **Platby** (GoPay link on invoices, supplier payment orders via Fio API — confirmed in the bank,
   cash-flow forecast), **Povinnosti a termíny** (legal + company deadlines, GDPR requests, domains / SSL / licences /
   certifications), **Obsah produktov** (content once for all shops, languages, feeds); backlog chapter 10 (AI
   assistant waits for the decision on customer data). **Large files off the shared hosting**: S3-compatible object
   storage with presigned direct browser upload / download, panel keeps metadata + permissions + share links, daily
   copy to the home server RAID — superseded the same day: **files go to the owner's Nextcloud**
   (`https://home.ermagroup.net`, internet-facing behind Cloudflare: WebDAV, share API incl. upload-only links,
   chunked uploads > 100 MB; plan 4.1). Home upload 150 Mbit/s (can be raised); Office in Nextcloud not required;
   **TODO later: off-site copy of the Nextcloud data** (owner: not now, "keď sa rozrastieme" — RAID only until then).
   Open questions:
   chapter 8 of the plan (each only for its phase). Phase 0 done (item 38), **phase 1 step a (permissions) done
   (item 39), step 1b (item 40), steps 1c–1f (item 41) — phase 1 complete; **phase 2 done (item 42). Next by the plan:
   phase 3** (timeline + dependencies, calendar with holidays / vacations, recurring tasks, project templates, register
   of obligations, project budget) — the order of phases 3–14 is open question 5 (owner may prefer e.g. simple
   complaints or quotes first).
   Older notes (2026-11-19): **Task management in the panel** ("like Asana / Trello").
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
