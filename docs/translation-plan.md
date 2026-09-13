# Translation plan

Why file-by-file, not all at once: nearly every page/API file calls shared functions defined in `config.php` (`heeft_rol()`, `read_json()`, `respond()`, …) by their exact Dutch names. Renaming a function without updating every single call site in the same pass silently breaks whatever calls it — a button just does nothing, with no error. There's no automated "rename symbol across files" tool available for this project, so each rename is a manual, verified step. Doing this gradually, one coherent group of files at a time, keeps the app in a working state after every step and makes each change reviewable.

## Status legend
✅ Done · 🟡 Partial (comments only, code identifiers still pending) · 🔴 Not started

## Foundational layer

| File | Status | Notes |
|---|---|---|
| `district.php` | ✅ | Fully English, including role keys — renamed ahead of the original schedule (see below) |
| `config.php` | 🟡 | Comments in English; role-related identifiers (colour keys, fallback role) already match the new English role keys. Other function/variable names and data-folder path strings are still Dutch (shared by every other file, so renaming those needs to happen together with all their callers) |

### Role-key rename: done early, on purpose

The original plan was to keep role keys (`voorzitter`, `penningmeester`, etc.) in Dutch until the very last, fully-coordinated step of this whole project, to avoid breaking not-yet-translated files that check roles by their exact string value.

That rename has now happened early instead — while only `district.php`, `config.php`, `auth.php`, `login.php`, `reset_password.php`, `partials/topbar.php`, `index.php`, and `style.css.php` existed, it was a small, checkable set of files to update in one pass. The keys are now:

| Old (Dutch) | New (English) |
|---|---|
| `voorzitter` | `chair` |
| `penningmeester` | `treasurer` |
| `secretaris` | `secretary` |
| `coordinator_drukwerk` | `coordinator_distribution` |
| `coordinator_voorlichting` | `coordinator_outreach` |
| `coordinator_literatuur` | `coordinator_literature` |
| `lid` | `liaison` |
| `media_coordinator` | *(unchanged)* |
| `social_media_coordinator` | *(unchanged)* |
| `admin` | *(unchanged)* |

**If you're translating a file from the list below and it checks a role by one of the old Dutch names** (e.g. `require_role(['voorzitter'])`, `heeft_rol(['coordinator_drukwerk'])`, or a default/fallback like `'lid'`), update it to the matching new key from the table above as part of that file's translation — don't reintroduce the old names.

## Planned next groups (suggested order — reorder freely)

1. **Auth & session**: `auth.php`, `login.php`, `reset_wachtwoord.php` → `reset_password.php`, `partials/topbar.php` — ✅ **Done, tested locally by the maintainer**
   Small, self-contained, and everything else depends on being logged in — good first real group.
   Notes: action names `wachtwoord_vergeten`/`wachtwoord_resetten` renamed to `forgot_password`/`reset_password` (all three callers updated in the same pass). File `reset_wachtwoord.php` renamed to `reset_password.php`. The top bar's other links (`locaties.php`) still keep their current Dutch file name for now since that page isn't translated yet — only its visible label is English. (`profiel.php` and `ledenbeheer.php`, also linked from the top bar at the time, have since been renamed too — see Group 3 below.)
2. **Dashboard & core pages**: `index.php`, `style.css.php` — ✅ **Done, awaiting your local test**
   Notes: the shared-identifier convention (status colour names, CSS class names) is kept as-is for now — see the top-of-file note in `style.css.php`. `MAP_STYLE_URL`, `MAP_CENTER_LAT/LNG`, `MAP_ZOOM`, and the new `MEMBER_REGION_RADIUS_KM` constant (renamed from `LID_REGIO_STRAAL_KM` — this one was safe to rename immediately since only `district.php` and `index.php`, both already updated, reference it) are used directly.
3. **Members**: `api_members.php` (was `api_leden.php`), `member_management.php` (was `ledenbeheer.php`), `members.php` (was `ledenoverzicht.php`), `profile.php` (was `profiel.php`), `committee_roles.php` (was `committee_rollen.php`) — ✅ **Done, awaiting your local test**
   Notes: role-key rename (see above) is fully applied here, including the colour-picker labels in `member_management.php`. `index.php` and `partials/topbar.php` (both already translated) were updated in this same pass to point to the new filenames — check both still work correctly when you test this group. `voorlichting.php` (not yet translated, Group 6) still calls the old `api_leden.php` — when that file's turn comes, update its calls to `api_members.php` as part of that translation.
4. **PI Locations**: `api_locations.php` (was `api_locaties.php`), `locations.php` (was `locaties.php`), `external_request.php` (was `extern_bestellen.php`) — ✅ **Done, awaiting your local test**
   Notes: `index.php` and `partials/topbar.php` (both already translated) were updated in this same pass to point to the new filenames. Added a new `GEOCODING_COUNTRY_CODE` setting in `district.php` — the geocoding was previously hardcoded to `&country=nl` (Netherlands-only), which would have silently broken address search for any district outside the Netherlands; leave it blank to search worldwide, or set your own 2-letter ISO country code. `external_request.php` (the public, no-login order form) still calls the not-yet-translated `api_drukwerk.php` and `api_voorlichting.php` — when those files' turn comes (Groups 5 and 6), no changes are needed here since it only calls their `action=` names, which don't need to change.
5. **Print**: `api_print.php` (was `api_drukwerk.php`), `print.php` (was `drukwerk.php`) — ✅ **Done, awaiting your local test**
   Notes: includes a stock mutation history feature (which item/quantity, why, and by whom, for every request approval, received order, and manual adjustment) — added during translation, so this template version is slightly ahead of where the original Dutch deployment was when Group 4 was translated. `index.php` and `external_request.php` (both already translated) were updated in this same pass to point to the new filenames. The euro symbol (€) was removed from price displays since this is now a multi-currency template — add your own currency formatting in `print.php`'s `euro()` helper function if you'd like one.
   Also added: optional weight tracking per stock item (weight per N units, e.g. 500g per 100 units), shown per-unit in the stock list and as a live estimated total while submitting a request, plus a total-weight column/summary for coordinators reviewing requests.
6. **Planning & Agenda / Outreach**: `api_outreach.php` (was `api_voorlichting.php`), `outreach.php` (was `voorlichting.php`) — ✅ **Done, awaiting your local test**
   Notes: covers the shared calendar (with the visual month view), outreach-presentation requests/scheduling, and the shared to-do list — all three lived in this one Dutch file originally, so they stay together here too. `index.php` and `external_request.php` (both already translated) were updated in this same pass to point to the new filenames. Calls `api_locations.php` (geocoding for the map) and `api_members.php` (colours, member picker for tasks) — both already translated in earlier groups, no changes needed there. Also added: an IT-only Settings button (12h/24h time display toggle) in the Planning & Calendar dashboard box, and renamed "Planning & Agenda" to **"Planning & Calendar"** throughout — "agenda" specifically means a single meeting's list of topics in English, not a shared calendar.
   Also added: Committee Service Roles (`committee_roles.php`) is now IT-editable with a reset-to-defaults option — content lives in `committee_roles_defaults.php` (the original Handbook text) with optional overrides in a `committee_roles_custom.json` data file, managed via the new `api_committee_roles.php`.
   Also added: a portal-wide **date order** setting (day-month-year / month-day-year / year-month-day) alongside the time format, in the same Settings panel. The setting itself moved to `api_members.php` (alongside the calendar colours, since it needs to be readable from every page) — `index.php`, `locations.php`, and `print.php` were also updated in this pass to load and apply it to every date they display.
7. **Finance**: `api_finance.php` (was `api_financien.php`), `finance.php` (was `financien.php`) — ✅ **Done, awaiting your local test**
   Notes: this was by far the largest and most complex group (nearly 3,000 lines combined) — transactions, reimbursement/order requests, resales, joint purchases ("follow the money"), shrinkage tracking, and attachment storage (local/kDrive/Google Drive). Date fields were converted from free-text `dd-mm-yyyy` inputs to standard `<input type="date">` elements — simpler, and consistent with the date-order setting used everywhere else in the app (the original Dutch version's text-input approach was hardcoded to day-month-year and wouldn't have respected that setting). All € symbols were removed from the UI for multi-currency support, matching the approach already used in Print. `lib_drive.php` and `lib_kdrive.php` are copied in **as-is (still Dutch, untranslated)** since `api_finance.php` requires them directly — the app will not run without them present, even before they're translated. Translate those two (plus `storage.php`) together in one pass later, since `test_drive.php`/`test_kdrive.php` (Group 9) also depend on them.
8. **Drive Database**: `api_drive.php` (filename unchanged), `drive_files.php` (was `drive_database.php`), `minutes_agendas.php` (was `notulen_agenda.php`) — ✅ **Done, awaiting your local test**
   Notes: the two frontend pages are nearly identical (same drag-to-reorder, hover-preview, upload logic), differing only in category (`algemeen` vs `notulen_agenda`) and who may edit (IT/Chairperson only vs. also Secretary). `index.php` was updated in this same pass to point to the new filenames.
9. **Dev/debug utilities** (optional, low priority): `test_drive.php`, `test_email.php`, `test_kdrive.php` — ✅ **Done**

## Fully translated 🎉

As of this update, every file that a member or IT servant actually interacts with is in English, including the shared libraries (`lib_drive.php`, `lib_kdrive.php`, `storage.php`) and the dev/debug utilities. Function names inside those shared libraries were renamed too (e.g. `drive_beschikbaar()` → `drive_available()`, `kdrive_upload_bestand()` → `kdrive_upload_file()`), with every caller (`api_finance.php`, `test_drive.php`, `test_kdrive.php`) updated in the same pass.

For each file, translating means:
- All UI-facing text (labels, buttons, headings, alerts, error messages)
- All code comments
- Function, variable, and (where safe) data-field names — checked against every file that references them, in the same commit/pull request
- Data folder/file-path strings that are Dutch words (e.g. `/locaties`, `/drukwerk`) — **note:** renaming these means either a one-time data migration step for anyone already running the app, or keeping the old path as a fallback read location. Decide and document this explicitly when you get here; don't rename storage paths silently.

## What should NOT be renamed

- Role keys in `district.php`'s `ROLES` array, once real member data exists referencing them — add new roles instead of renaming existing ones.
- Anything already stored in a live district's `data/` folder — this template repo has no real data in it, but if you fork it for your own district and accumulate real records, treat stored field *values* (not just role keys) with the same caution.
