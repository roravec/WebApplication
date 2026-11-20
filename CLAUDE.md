# Project context for Claude — WebApplication

> **This repo is the HUB for all ERMA web services** (owner 2026-11-20): **docs/README.md** = overview of every service
> (webs, panel, e-shops, devices, old tools), where its code and docs live, hosting, databases, dev environment.
> Document cross-service things (deploy, hosting, databases, maintenance) HERE; service details stay in their repos
> (e-shops: `C:\Users\orras\git\Web_store`) and are linked from docs/README.md. Update it when a service changes.
> Current state of work / next tasks: see **HANDOVER.md**. Invoicing requirements: **docs/fakturacia-specifikacia.md**;
> where every legal rule lives and how it is changed: **docs/legislativa.md**.
> Deploy order + maintenance: **docs/nasadenie-a-udrzba.md**.
> Keep these files up to date — the owner wants everything written down so a new session loses nothing.

## What this is
The owner's own PHP framework. **One instance of WebApplication on the shared hosting** (one `index.php`, one
`framework/`, one `config/config.php` in the web root) serves **all** the owner's web apps, each in
`applications/<name>/`, chosen by domain (and optionally path): erma.sk (+ employee section erma.sk/panel),
hbkpravotice.sk, bnhl.sk, later LedBoard… (confirmed with the owner 2026-11-19). The PrestaShop shop in `/store/` is
separate software and is not part of it. Reworked in Nov 2026, see README.md for the full description.
VS Code workspace with all related repos: `C:\Users\orras\git\ERMA.code-workspace`.

- PHP **7.3 – 8.4**. Must stay **PHP 7.3 compatible**: the hosting runs 7.3 and the PrestaShop shop on the same
  hosting blocks a PHP upgrade until the shop is upgraded (see `C:\Users\orras\git\Web_store`). No typed properties,
  arrow functions, `??=`, match, union types, str_contains; no dynamic properties (8.2); explicit `?type` for null defaults (8.4).
- Composer libraries are allowed when they run on shared hosting without shell (pure PHP). `composer.json` in the repo
  root, libraries in `src/vendor` (not in git; `composer.lock` is), platform pinned to PHP 7.3.33 — install with the
  composer docker image on the dev server (command in HANDOVER item 11). Deploy = copy `src/` **including `src/vendor`**.
  Now used: dompdf (invoice PDF), bacon/bacon-qr-code (PAY by square QR), phpmailer/phpmailer (e-mail).
- E-mail: SMTP settings only in config.php (`apps.panel.mail`), never in the DB. Dev catches all mail in Mailpit
  (http://192.168.0.11:8025).
- Namespace `WebApplication\` → `src/framework/`. Apps implement global `IWebApp` (old style) or
  `WebApplication\Http\RequestHandler` (new style). `src/applications/demo/` is the reference app.

## Owner & environment
- Owner works on Windows + VS Code, repos in `C:\Users\orras\git\`. Communicates in Slovak or English.
  Prefers doing over explaining; dislikes manual steps. **Does not want hosts-file edits.**
- **The owner makes ALL commits himself. Never commit, push, amend, tag or create repositories** — leave changes in the
  working tree and tell him what changed. **Don't use `git add -N`** either: the owner commits with Eclipse/EGit, which
  kept the intent-to-add flag in the index after his commit (all files then showed as "DA"; fixed by rebuilding the index). (Exception that already happened: the shop-code repo on the home server
  `~/webstore/shop.git` was created and committed by Claude on 2026-11-18 before this rule was stated.)
- Global rule: after editing files in a git repo run `python -X utf8 ~/.claude/tools/to_checkout_eol.py <repo>` (CRLF
  checkout; `.gitattributes` keeps Dockerfile/*.sh LF).
- Home server (dev): `ssh -i ~/.ssh/id_ed25519_homeserver rasto@192.168.0.11` (Ubuntu, Docker, LAN only, standing
  permission for these projects). Don't touch the owner's other services there (Nextcloud :80, Minecraft, messenger, camstream…).
  Framework dev copy: `~/webapplication-dev` (copied by tar over ssh, not a git clone), web on **:8090**, tests via
  `cd ~/webapplication-dev/dev && COMPOSE_PROJECT_NAME=webapplication PHP_VERSION=7.3|8.4 docker compose run --rm test`.

## Hosting (production)
- Shared hosting, **FTP only**, nginx proxy in front of Apache. FTP root = web root shared by all domains.
  Local snapshot of the FTP (structure reliable, contents old): `C:\Users\orras\git\ftp` — **never delete it**.
- Root `.htaccess` is shared with the shop (`/store/`) and must not be overwritten; `/fw` and `/api/device_sync.php`
  must stay plain HTTP (ESP32 devices). The live server currently runs BOTH the old core (`core/`, `WebApp_*` folders,
  root `index.php`) and an older copy of this framework (`core_new/`, `applications/`, `config/`).
- **Databases (owner 2026-11-20): `ermaskretail` = production** for everything in full operation; **`molesk` = test**.
  Deploy first on `molesk` (as today: shop `ps_*`, `hbkpravotice_*`, old tools `admin/`, `sklad/`, `beta/` …), then
  flip over to `ermaskretail` (framework apps: phase A2 = copy `panel_*` + `hbkpravotice_*`, switch config.php; the
  upgraded shop goes into `ermaskretail` directly in phase C). `molesk` stays as the test database afterwards.
  Plan: docs/nasadenie-a-udrzba.md "Databázy".
  The ERMA system may READ `ps_*` directly (shop orders); it NEVER writes into them by SQL — changes in a shop (stock,
  order state) go only through the PrestaShop Webservice API (owner 2026-11-19, docs/sklad-specifikacia.md).

## Apps and their sources
Apps in their own repos (owner 2026-11-20): listed in **`apps.json`**; `python dev/apps.py sync` (dev server) /
`package NAME` (deploy package) assemble them into `applications/<name>`. Never commit them into this repo
(.gitignore). Only the framework, `panel` and `demo` live here.

| App | Domain | Source of truth | Notes |
|---|---|---|---|
| erma | erma.sk | `C:\Users\orras\git\Web_ERMA\app` (own repo; folder not in git yet) — **new placeholder** (owner 2026-11-19: the old erma site is not needed) | public frontpage for customers; real site later |
| panel | erma.sk/panel (path may change) | `src/applications/panel` | employee section: shop orders (read-only), employees; invoicing next |
| hbkpravotice | hbkpravotice.sk | `StadiumControl\WebServer\apps\hbkpravotice` (own repo; ported 2026-11-20, old `WebApp_*` + `core/` removed there) | ~11k lines, oldest core API → runs via `WebApplication\Legacy\OldCoreApplication` (HANDOVER 22); StadiumControl admin + JSON API |
| bnhl | bnhl.sk | `StadiumControl\WebServer\apps\bnhl` (own repo; ported 2026-11-20) | ~10k lines, uses `hbkpravotice_` tables + hbkpravotice's StadiumControl API (config `stadiumControlApi`) |
| LedBoard | ? | repo `C:\Users\orras\git\WEB_LedBoard` (spec in `Prj_LedBoard`) | website fits; server part depends on its DEC-003 (needs realtime?) |

`C:\Users\orras\git\WebApplications` = identical copy of StadiumControl apps (owner may delete).

## Decisions (owner)
- Order: 1) framework rework ✅ → 2) erma.sk placeholder ✅ → 3) **employee section** (orders ✅, invoicing step 1 ✅; step 2 = PDF,
  PAY by square, exports, e-mail) — current focus → 4) port hbkpravotice, bnhl ✅ (2026-11-20, on dev; goes live with
  the framework deploy) → later the real erma.sk site.
- Employee section (panel): **dark theme by default**, user can switch to light ("denný režim"); all pages use the colour
  tokens. **Task management (Asana/Trello-like) will be added later** — keep it in mind (generic links between records,
  shared activity log, per-module permissions). See HANDOVER.md.
- Invoicing is done **only** in the ERMA system, centrally, for all e-shops; PrestaShop stops issuing invoices
  (`add_faktura` removed). Built **from scratch** — do not investigate or repair the old shop invoicing/numbering.
- **The system is not only for ERMA Group** — other/new companies may use it. Nothing hard-coded: companies, VAT mode
  (payer / non-payer, with validity dates), number series, B2B/B2C, invoice look, exports, e-invoice provider per company.
- Company-specific values (OR registration, first invoice number, series format, Omega codes …) are **settings the owner
  fills in himself** — build them configurable and don't ask for the values (owner 2026-11-19). Formats of external
  systems (Omega, Peppol, PAY by square …): look up the official specification yourself instead of asking.
- **Legal rules change often → they must stay easy to edit** (owner 2026-11-19, "je to dôležité"). Rules in
  **docs/legislativa.md**: anything set by law = data with validity dates edited in the panel (Legislatíva, Firmy), never
  hard-coded; an issued document freezes what applied (texts, rates, supplier, template version); external formats
  (Omega, UBL/Peppol, PAY by square) are versioned side by side; rules that must be code live in one place
  (`Invoices::issueErrors/issueWarnings`, `Calculator`) with tests. Update docs/legislativa.md with every new feature.
- **Warehouse + shops** (owner 2026-11-19, details docs/sklad-specifikacia.md): a shop's stock STAYS IN THE SHOP — the
  panel reads and edits it directly in PrestaShop (quantities, stock settings, combinations exactly as PrestaShop has
  them); shops do NOT take data from us. Warehouses outside shops are NATIVE in the panel (batches with purchase price,
  FIFO). The panel must link ANY PrestaShop (also remote) and manage it: orders, order state, stock, invoices → writes
  go ONLY through the PrestaShop Webservice API, never direct SQL into ps_*. Orders are read from the shared DB
  (source 0) or — for a shop not in our DB — through its Webservice (`Shop\OrderSource`, HANDOVER item 19); we do not
  modify the shops' code, only use the standard Webservice + settings. Shops must not issue invoices (PS_INVOICE off
  at deploy).
- Invoices from shop orders are created **manually** (button on the order) + fully manual invoices (owner 2026-11-19).
- Invoices must meet Slovak law; **issuing e-invoices (EN 16931 / Peppol BIS 3.0 / UBL 2.1, sent via a Peppol access
  point configured per company) is a full feature from day one** for VAT-payer companies — even though ERMA Group
  itself is currently not a VAT payer. Exportable to the accountant's **Omega (KROS)**. Details: docs/fakturacia-specifikacia.md.
