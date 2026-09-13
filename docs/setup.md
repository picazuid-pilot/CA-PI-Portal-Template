# Setup guide

## 1. Hosting

Any standard shared PHP hosting works, including free ones. This template has been run in production on **InfinityFree**, which has a few quirks worth knowing about (all already worked around in the code, but useful if you hit something unexpected):

- PHP cannot write to the server's default session folder → sessions are stored in `data/sessions/` instead (already handled in `config.php`).
- No cron jobs and no outbound cURL to some external services → anything relying on scheduled jobs or certain third-party APIs may need a different host, or you trigger it manually / on page load.
- `sys_get_temp_dir()` is not writable → avoid relying on the system temp folder for anything (already avoided in this codebase).

If you're on a host without these limitations, everything still works exactly the same — those workarounds are harmless either way.

## 2. Upload the files

Upload everything inside `htdocs/` to your web root (e.g. via FTP, or your host's file manager). The `data/` folder will be created automatically the first time any page runs, with an `.htaccess` file that blocks direct web access to it.

## 3. Configure `district.php`

Every setting is documented inline in the file itself. At minimum, set:

- `INVITE_CODE` — anyone who has this can register an account. Change it from the placeholder.
- `DISTRICT_NAME`, `DISTRICT_SLUG`, `APP_URL`
- `COLOR_PRIMARY` — your committee's brand colour; the rest of the UI derives its palette from this automatically.
- `MAP_CENTER_LAT` / `MAP_CENTER_LNG` / `MAP_ZOOM` — where the dashboard map opens by default.

Everything else (MapTiler, SMTP, cloud storage) is optional and clearly marked as such in the file — the app works without any of it, just with a plainer map and no outgoing email.

## 4. Create your first account, then make it admin

1. Go to `yoursite.com/register.php`, enter your invite code, and register normally.
2. The first account is **not** automatically an admin. Open `data/users.json` via FTP/file manager, find your user, and add `"admin"` to their `"roles"` array, e.g.:
   ```json
   "roles": ["admin"]
   ```
3. Log out and back in. You'll now see the Member Management screen, where you can assign roles to everyone else going forward — no more manual file editing needed after this one-time bootstrap step.

## 5. Optional: map tiles (MapTiler)

Without a MapTiler key, maps use MapLibre's bare demo style (functional, but no street names or place labels). To improve this:

1. Create a free account at [cloud.maptiler.com](https://cloud.maptiler.com/) (100,000 map loads/month free).
2. Copy your API key into `MAPTILER_KEY` in `district.php`.

## 6. Optional: email notifications (SMTP)

Without SMTP configured, all notifications still appear inside the portal (the message icon), just not by email.

For Gmail specifically:
1. Turn on 2-step verification on the Google account you'll use.
2. Create an [app password](https://myaccount.google.com/apppasswords).
3. Fill in `SMTP_HOST`, `SMTP_USER`, `SMTP_PASS` (the 16-character app password, no spaces), and `SMTP_FROM` in `district.php`.

Any other SMTP provider works too — just fill in the matching host/port.

## 7. Optional: attachment storage backend

Receipts and invoices attached to financial transactions can be stored:

- **`local`** (default) — directly on your hosting. Simplest, no setup. A configurable size limit (`LOCAL_ATTACHMENTS_LIMIT_MB`) stops uploads once you're close to running out of hosting storage, so nothing breaks unexpectedly.
- **`kdrive`** — Infomaniak kDrive via WebDAV. Just a username/password (or app password with 2FA).
- **`drive`** — Google Drive via a Service Account. Requires a Google **Workspace** account (personal Gmail accounts don't give Service Accounts their own storage quota) and a `google_service_account.json` key file placed in `data/`.

If your chosen backend isn't fully configured, or has a temporary outage, uploads automatically fall back to local storage — an upload should never fail outright.

## 8. Try the demo account

Log in with username `dummy`, password `dummy` to see the portal from a "look but don't touch" perspective — every page is browsable, but no create/edit/delete/submit action will go through. Useful for showing your committee the portal before rolling it out, without risking test data ending up in your real records.

## 9. Backups

Since everything is flat JSON files under `data/`, a full backup is just copying that folder. Do this regularly (most hosts offer scheduled backups, or you can download it manually via FTP).
