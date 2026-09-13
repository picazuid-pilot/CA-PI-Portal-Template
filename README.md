# C.A. Public Information Work Portal — Template

A self-hosted web portal built for **Cocaine Anonymous Public Information (PI) committees**, covering the day-to-day work described in the official [C.A. World Service Conference Public Information (WSCPI) Handbook](https://pi.ca.org/wp-content/uploads/2025/12/2025-Revised-PI-Handbook.pdf): member/customer records, print materials & inventory, outreach requests, finances, a shared calendar, a document archive, and role-based access matching the committee structure suggested in the Handbook.

This repository is a **template**: it was extracted from a real, working district deployment (Region South, Netherlands) so that other districts, areas, or regions anywhere in the world can adopt it, translate it into their own language, and configure it for their own committee.

> **Live example**: this template started life as a Dutch-language deployment. Some parts of this English version are still mid-translation — see [Translation status](#translation-status) below before you deploy it.

---

## What this portal does

Organised around the roles in the WSCPI Handbook (see [`docs/roles.md`](docs/roles.md) for the full list with suggested sobriety/experience guidelines per role):

- **PI Locations** — a customer/contact database of institutions you do outreach with (hospitals, schools, treatment centres, etc.), with activity tracking, a status map, campaigns, "targets" for media outreach, and campaign ideas split into **physical** (print/press/media) and **online** (social media) workflows, each approved and carried out by the matching coordinator role.
- **Print** — regional stock/inventory (with photo/video/PDF previews), fixed literature packages, suppliers & pricing, and an order-approval workflow.
- **Planning & Agenda** — a shared calendar (with a visual month view), outreach/professional-presentation scheduling, and a simple shared to-do list.
- **Finance** — transactions, balance, reimbursement requests, resale/on-account tracking, joint-purchase splitting, CSV export, and configurable local or cloud (Google Drive / Infomaniak kDrive) attachment storage.
- **Drive Database** — a curated, downloadable-file catalogue with hover-preview thumbnails (image, video, or PDF), split into a general archive and a Secretary-managed Minutes & Agendas section.
- **Members** — self-service profiles (with photo, contact info, and an optional "coverage region" shown on a map), a full member directory, and IT-only role/account management.
- **A safe demo account** (`dummy` / `dummy`) that can browse every single page but cannot create, edit, delete, or submit anything — handy for showing the portal to your committee before rolling it out.

## Requirements

- Standard shared PHP hosting (PHP 8+, no special extensions, no database). This has been tested and runs on free hosting (InfinityFree) with real usage.
- No build step, no Composer, no Node — it's plain PHP + vanilla JavaScript. Copy the files up via FTP and it works.
- Data is stored as flat JSON files under `data/`, which is protected from direct web access via `.htaccess`. No database server needed.
- Optional, all free-tier friendly:
  - A [MapTiler](https://cloud.maptiler.com/) account (free, 100k map loads/month) for a proper street map instead of the bare demo style.
  - An email account for SMTP notifications (e.g. a free Gmail address with an [app password](https://myaccount.google.com/apppasswords)).
  - A Google Drive or Infomaniak kDrive account if you want attachments backed up off-host.

## Getting started (5-minute version)

1. **Copy** the contents of `htdocs/` to your hosting's web root.
2. **Edit `district.php`** — this is the *only* file you need to change. It has inline comments for every setting: your committee's name, colours, map location, invite code, email, and (optionally) cloud storage.
3. **Upload**, then visit `yoursite.com/login.php`, click "Create account", enter the invite code you set, and create the first account.
4. **Make yourself admin**: the very first account is not automatically an admin — open `data/users.json` on your hosting (via FTP/file manager) and add `"admin"` to that user's `roles` array. After that, all further role management happens in the portal itself (Members → Member Management).

<img width="807" height="309" alt="Screenshot from 2026-09-13 13-53-40" src="https://github.com/user-attachments/assets/f42a27b4-ac8d-4819-b6de-d2b70159acde" />

5. Explore the dashboard, try the `dummy`/`dummy` demo login to see it from a regular member's point of view, and start customising.

See [`docs/setup.md`](docs/setup.md) for a more detailed walkthrough (hosting quirks, SMTP setup, storage backends, backups), and [`docs/personalization-checklist.md`](docs/personalization-checklist.md) for an in-order checklist of everything worth personalizing for your own district (invite code, email provider setup, and — importantly — how to route the right notifications to the right committee roles).

## Repository structure

```
htdocs/                  → everything you upload to your web host
  district.php           → THE file you edit — all per-district settings
  config.php             → shared core logic (leave alone)
  api_*.php              → backend endpoints, one per module
  *.php                  → the actual pages (index.php is the dashboard)
  partials/              → shared page fragments (e.g. the top bar)
  templates/             → CSV templates for bulk-import features
  data/                  → created automatically; all your district's data lives here
docs/                    → setup guide, personalization checklist, role descriptions, translation notes
```

## Translation status

This template is being translated from its original Dutch codebase into English **file by file**, precisely because most files call shared functions defined in `config.php`, and renaming those functions requires updating every caller in the same pass to avoid breaking things silently.

| Layer | Status |
|---|---|
| `district.php` (the file you actually edit) | ✅ Fully English text, comments, and role **keys** (e.g. `chair`, `treasurer`) |
| `config.php` (shared core functions) | ✅ Comments fully English. Internal function/variable names and data-folder names are intentionally kept as their original Dutch identifiers (e.g. `heeft_rol()`, `read_json()`, `DATA_DIR . '/locaties'`) — every other file calls these by these exact names, so they're treated like the role keys: stable internal identifiers, invisible to end users, not part of the translation |
| `auth.php`, `login.php`, `reset_password.php` (was `reset_wachtwoord.php`), `partials/topbar.php` | ✅ Translated **and locally tested** (register, login, logout, demo account, forgot-password all confirmed working) |
| `index.php`, `style.css.php` | ✅ Translated — ready for you to test locally |
| `api_members.php` (was `api_leden.php`), `member_management.php` (was `ledenbeheer.php`), `members.php` (was `ledenoverzicht.php`), `profile.php` (was `profiel.php`), `committee_roles.php` (was `committee_rollen.php`) | ✅ Translated — ready for you to test locally |
| `api_locations.php` (was `api_locaties.php`), `locations.php` (was `locaties.php`), `external_request.php` (was `extern_bestellen.php`) | ✅ Translated — ready for you to test locally |
| `api_print.php` (was `api_drukwerk.php`), `print.php` (was `drukwerk.php`) | ✅ Translated — ready for you to test locally |
| `api_outreach.php` (was `api_voorlichting.php`), `outreach.php` (was `voorlichting.php`) | ✅ Translated — ready for you to test locally |
| `api_finance.php` (was `api_financien.php`), `finance.php` (was `financien.php`) | ✅ Translated **and locally tested** |
| `api_drive.php`, `drive_files.php` (was `drive_database.php`), `minutes_agendas.php` (was `notulen_agenda.php`) | ✅ Translated — ready for you to test locally |
| `lib_drive.php`, `lib_kdrive.php`, `storage.php` | ✅ Translated, including internal function names (e.g. `drive_available()`, `kdrive_upload_file()`) — every caller updated in the same pass |
| `test_drive.php`, `test_email.php`, `test_kdrive.php` (dev/debug utilities) | ✅ Translated |
| All other `.php` files (pages + API endpoints) | 🔴 Not yet translated — still the original Dutch UI text, code, and comments |

**In practice this means:** the portal is now **fully translated** — every page, API endpoint, shared library, and dev utility runs in English. See [`docs/translation-plan.md`](docs/translation-plan.md) for the file-by-file history of how this was done, and the naming conventions used (which internal identifiers changed vs. which stayed as-is).

If you'd like to help translate this template into your own language for your district, see [`CONTRIBUTING.md`](CONTRIBUTING.md).

## Roles

Role keys used internally (now `admin`, `chair`, `treasurer`, `secretary`, `coordinator_distribution`, `coordinator_outreach`, `coordinator_literature`, `media_coordinator`, `social_media_coordinator`, `liaison` — renamed to English ahead of schedule, see [Translation status](#translation-status)) never change once you're using the app with real data — the *labels* shown to your members are what you translate/customise. Full descriptions, based directly on the WSCPI Handbook, are in [`docs/roles.md`](docs/roles.md) and are also shown in-app under **Members → Committee Service Roles**.

## License

See [`LICENSE`](LICENSE). Contributions and forks for other districts, areas, and regions are actively encouraged — that's the whole point of this repository.

## Credit

Originally built for Cocaine Anonymous Public Information, Region South (Netherlands). Content and role structure follow the C.A. World Service Conference Public Information Committee's Handbook; this project is not an official CAWS product.
