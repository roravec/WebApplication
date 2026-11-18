# Project context for Claude — WebApplication

> Current state of work / next tasks: see **HANDOVER.md**. Invoicing requirements: **docs/fakturacia-specifikacia.md**.
> Keep these files up to date — the owner wants everything written down so a new session loses nothing.

## What this is
The owner's own PHP framework. One installation on shared hosting serves several web apps, chosen by domain
(and optionally path). Reworked in Nov 2026 (branch `rework`), see README.md for the full description.

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
- Global rule: after editing files in a git repo run `python -X utf8 ~/.claude/tools/to_checkout_eol.py <repo>` (CRLF
  checkout; `.gitattributes` keeps Dockerfile/*.sh LF). Commit/push only when the owner asks.
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
| erma | erma.sk | `C:\Users\orras\git\Web_ERMA\WebApp_erma\WebApp.php` (newest, already for new framework) + rest of `StadiumControl\WebServer\webapp\WebApp_erma` | **Public frontpage for customers** + employee section |
| panel (new) | erma.sk/panel (path TBD) | — | employee section: shop orders, central invoicing, later more |
| hbkpravotice | hbkpravotice.sk | `C:\Users\orras\git\StadiumControl\WebServer\webapp\WebApp_hbkpravotice` | ~11k lines, old core API (mysqli) → use LegacyDatabase |
| bnhl | bnhl.sk | `...\StadiumControl\WebServer\webapp\WebApp_bnhl` | ~10k lines, uses `hbkpravotice_` tables |
| LedBoard | ? | repo `C:\Users\orras\git\WEB_LedBoard` (spec in `Prj_LedBoard`) | website fits; server part depends on its DEC-003 (needs realtime?) |

`C:\Users\orras\git\WebApplications` = identical copy of StadiumControl apps (owner may delete).

## Decisions (owner)
- Order: 1) framework rework ✅ (uncommitted) → 2) port erma.sk (frontpage) → 3) employee section with shop orders
  + central invoicing → 4) port hbkpravotice, bnhl.
- Invoicing is done **only** in the ERMA system, centrally, for all e-shops; PrestaShop stops issuing invoices
  (`add_faktura` removed). Built **from scratch** — do not investigate or repair the old shop invoicing/numbering.
- Invoices must meet Slovak law, be ready for **e-faktúra (EN 16931 / Peppol BIS 3.0 / UBL 2.1)**, exportable to the
  accountant's **Omega (KROS)**, and everything configurable (multiple companies, number series, B2B/B2C, invoice look).
  ERMA Group s.r.o. is currently **not a VAT payer**. Details: docs/fakturacia-specifikacia.md.
