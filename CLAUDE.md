# Project context for Claude — WebApplication

> Current state of work / next tasks: see **HANDOVER.md**. Invoicing requirements: **docs/fakturacia-specifikacia.md**.
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
- No external dependencies so far (deploy = copy `src/`). Composer libraries are allowed when needed (e.g. PDF), as long as
  `vendor/` can be deployed by upload.
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
- Database `molesk` is shared: PrestaShop `ps_*`, `hbkpravotice_*` (also used by bnhl), `ermaweb_*`, `branchmanager_*`.
  The ERMA system may READ `ps_*` (shop orders); it never writes into the shop's tables.

## Apps and their sources
| App | Domain | Source of truth | Notes |
|---|---|---|---|
| erma | erma.sk | `src/applications/erma` — **new placeholder** (owner 2026-11-19: the old erma site is not needed) | public frontpage for customers; real site later |
| panel | erma.sk/panel (path may change) | `src/applications/panel` | employee section: shop orders (read-only), employees; invoicing next |
| hbkpravotice | hbkpravotice.sk | `C:\Users\orras\git\StadiumControl\WebServer\webapp\WebApp_hbkpravotice` | ~11k lines, old core API (mysqli) → use LegacyDatabase |
| bnhl | bnhl.sk | `...\StadiumControl\WebServer\webapp\WebApp_bnhl` | ~10k lines, uses `hbkpravotice_` tables |
| LedBoard | ? | repo `C:\Users\orras\git\WEB_LedBoard` (spec in `Prj_LedBoard`) | website fits; server part depends on its DEC-003 (needs realtime?) |

`C:\Users\orras\git\WebApplications` = identical copy of StadiumControl apps (owner may delete).

## Decisions (owner)
- Order: 1) framework rework ✅ → 2) erma.sk placeholder ✅ → 3) **employee section** (orders ✅, invoicing next) — current
  focus → 4) port hbkpravotice, bnhl → later the real erma.sk site.
- Invoicing is done **only** in the ERMA system, centrally, for all e-shops; PrestaShop stops issuing invoices
  (`add_faktura` removed). Built **from scratch** — do not investigate or repair the old shop invoicing/numbering.
- **The system is not only for ERMA Group** — other/new companies may use it. Nothing hard-coded: companies, VAT mode
  (payer / non-payer, with validity dates), number series, B2B/B2C, invoice look, exports, e-invoice provider per company.
- Invoices must meet Slovak law; **issuing e-invoices (EN 16931 / Peppol BIS 3.0 / UBL 2.1, sent via a Peppol access
  point configured per company) is a full feature from day one** for VAT-payer companies — even though ERMA Group
  itself is currently not a VAT payer. Exportable to the accountant's **Omega (KROS)**. Details: docs/fakturacia-specifikacia.md.
