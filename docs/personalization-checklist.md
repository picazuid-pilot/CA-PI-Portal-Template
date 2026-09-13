# Personalization checklist

A practical, in-order checklist for making this template your own. Everything here is edited in **`district.php`** unless stated otherwise — that's the only file you should need to touch.

---

## 1. Required — the portal won't make sense without these

| Setting | What to do |
|---|---|
| `INVITE_CODE` | Change `'CHANGE-THIS-TO-YOUR-OWN-CODE'` to a code of your own choosing. Anyone who has it can register an account, so treat it a bit like a shared password — share it with your committee directly (in person, PI meeting, or a private message), not on a public website. |
| `DISTRICT_NAME` | Your district/area/region's name, shown throughout the portal and in emails. |
| `DISTRICT_SLUG` | A short, URL/filename-safe version of the above (lowercase, hyphens instead of spaces). Used in exported filenames. |
| `APP_URL` | The web address where you'll host this (no trailing slash). Update this again later if you move hosting. |
| `MAP_CENTER_LAT` / `MAP_CENTER_LNG` / `MAP_ZOOM` | Where the dashboard map opens by default — roughly the centre of your district. Easiest way to find these: search "[your city] latitude longitude" in Google, or open [latlong.net](https://www.latlong.net/). |

## 2. Strongly recommended

| Setting | What to do |
|---|---|
| `COLOR_PRIMARY` | Your committee's brand colour (hex code, e.g. `#00594F`). The rest of the interface derives its palette from this automatically. |
| `MAPTILER_KEY` | Without this, maps use a bare, unlabelled demo style. Create a free account at [cloud.maptiler.com](https://cloud.maptiler.com/) (100,000 map loads/month free) and paste your key here — the map immediately gets street names and place labels. |
| `GEOCODING_COUNTRY_CODE` | Restricts address search (when adding a PI Location) to your country, using a 2-letter code (`us`, `gb`, `ca`, `au`, etc.). Leave blank to search worldwide — do this if your district spans multiple countries. |

## 3. Email notifications (SMTP)

Without this, the portal still works completely — notifications just stay inside the app (the message icon) instead of also arriving by email. If you'd like email too, here's how to find the settings for common providers.

### Gmail / Google Workspace
1. Turn on 2-step verification on the Google account you'll use (Google Account → Security).
2. Create an [app password](https://myaccount.google.com/apppasswords) — this is a 16-character code, *not* your normal Gmail password.
3. Fill in:
   ```
   SMTP_HOST = smtp.gmail.com
   SMTP_PORT = 587
   SMTP_USER = your-address@gmail.com
   SMTP_PASS = the 16-character app password (remove the spaces)
   SMTP_FROM = your-address@gmail.com
   ```

### Microsoft 365 / Outlook.com
1. Fill in:
   ```
   SMTP_HOST = smtp.office365.com
   SMTP_PORT = 587
   SMTP_USER = your-address@outlook.com (or your Microsoft 365 address)
   SMTP_PASS = your password, or an app password if 2-step verification is on
   SMTP_FROM = same as SMTP_USER
   ```
2. If you have 2-step verification enabled (recommended), create an app password the same way as Gmail: Microsoft account → Security → Advanced security options → App passwords.

### Your own domain's email (hosting provider, e.g. cPanel-based hosting)
Your hosting provider's control panel (often cPanel) has an "Email Accounts" section that shows the exact SMTP host, port, username, and password for any mailbox you create there — usually something like `mail.yourdomain.com` on port `587` or `465`. If you're not sure, your hosting provider's support pages or support chat can give you these details directly; every host's own documentation is the most reliable source since these settings vary provider to provider.

### Any other provider
Search **"[your email provider] SMTP settings"** or **"[your email provider] app password"** — nearly every provider publishes a support page with the exact host/port to use. A few things worth checking if email doesn't send:
- Is 2-step verification on, and have you created an *app password* specifically (rather than reusing your normal login password)?
- Does `SMTP_PORT = 587` work, or does your provider require `465` instead (some do — try both if the first doesn't work)?
- Is outbound SMTP blocked by your **hosting** provider rather than your email provider? Some free hosts restrict outbound connections on certain ports — if nothing works, ask your host directly whether SMTP is allowed.

You can test your settings any time by visiting `test_email.php?to=your@email.com` after filling these in (see [`docs/setup.md`](setup.md)) — remove or restrict access to that file once everything works, since it isn't behind a login.

## 4. Committee service roles & email routing

This is the part that makes sure the *right person* gets notified about the *right thing* — e.g. only the Treasurer hears about a new reimbursement request, not the whole committee.

### How it actually works — two layers

**Layer 1 (the one you'll use day to day): assigning roles to members.**
Once your committee members have registered accounts, an admin goes to **Members → Member Management** and ticks which role(s) each person holds (someone can hold more than one, e.g. Chairperson *and* IT). From that moment on, notifications for that role are sent automatically to that member's own registered email address — no further setup needed. This is the only step most districts will ever need for role-based email.

**Layer 2 (a fallback, for the gap before anyone is assigned): `COORDINATOR_EMAILS` in `district.php`.**
```php
const COORDINATOR_EMAILS = [
    'coordinator_distribution' => '',
    'coordinator_outreach' => '',
    'treasurer' => '',
    'secretary' => '',
];
```
If a notification needs to go to, say, the Treasurer, but **no one currently holds that role yet** in Member Management (e.g. right after you've just set up the portal and haven't assigned roles), the system falls back to whatever address you've filled in here — so a notification is never simply lost during that gap. Fill in one general committee email address per role here (or leave blank if you'd rather notifications just wait until a real person is assigned), and update Member Management as soon as you know who holds each role. You don't need to keep this list in sync afterwards — once someone is assigned a role, their own address is used automatically instead.

### Practical order to do this in

1. Set `INVITE_CODE` and share it with your committee.
2. Each member registers their own account (the very first person to register automatically becomes District IT/admin — see [`docs/setup.md`](setup.md)).
3. IT opens **Members → Member Management** and assigns each member their role(s), based on your committee's own election/group-conscience results.
4. (Optional) Fill in `COORDINATOR_EMAILS` with general committee addresses, as a safety net for any role not yet assigned to a specific person.
5. Check **Members → Committee Service Roles** to see the full description of each role's responsibilities (based on the WSCPI Handbook) — useful to share with new members taking on a role for the first time.

Role **labels** (what members see, e.g. "PI Treasurer") can be freely translated or reworded to match your district's own terminology, directly in the `ROLES` array in `district.php`. The **keys** (e.g. `treasurer`) should not be renamed once you have real member data using them — add a new role instead if you need one that doesn't exist yet.
