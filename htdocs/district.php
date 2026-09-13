<?php
/**
 * ============================================================
 *  DISTRICT CONFIGURATION — PI WORK PORTAL (C.A. Public Information)
 * ============================================================
 * This is the ONLY file a District IT servant needs to edit to adopt this
 * template for a new district. Copy the whole "htdocs" folder to your own
 * hosting/domain, fill in the values below, and you're done.
 *
 * Nothing here needs to stay secret except SMTP_PASS — never put real
 * secrets in a file that could be downloaded as plain text. This
 * particular file is safe because it lives in the web root and is always
 * executed by PHP rather than served as text, but make sure
 * 'display_errors' is OFF in production regardless.
 *
 * NOTE ON IDENTIFIERS: the array keys below (e.g. 'chair',
 * 'treasurer') are internal identifiers used throughout the codebase and
 * stored in your data files. Don't rename them once you've started using
 * the app with real members — add new roles instead, or you'll need a
 * one-time data migration for anyone who already has that role assigned.
 * The human-readable label (the text after "=>") is what members
 * actually see, and can be freely translated into your own language.
 * These keys were renamed to English ahead of the original translation
 * schedule (see docs/translation-plan.md) — it was low-risk to do this
 * early, while only a handful of files existed in this repo yet.
 */

// ---- Access to this district -----------------------------------
// Anyone who knows this code can create an account — this keeps the
// portal closed to your own team without an admin having to manually
// approve every single signup. Change this to something of your own.
define('INVITE_CODE', 'CHANGE-THIS-TO-YOUR-OWN-CODE'); // Change the CHANGE-THIS-TO-YOUR-OWN-CODE to whatever invite code you can use to invite other PI members to register to the portal

// ---- Identity of this district -----------------------------------
define('DISTRICT_NAME', 'Example District');
define('DISTRICT_SLUG', 'example-district');      // used in file names, no spaces
define('APP_URL', 'https://example-district.your-domain.org'); // change https://example-district.your-domain.org to your own domain name

// ---- Branding --------------------------------------------------------
// Primary colour plus an automatically derived palette (lighter/darker).
// If a district wants a different primary colour, only COLOR_PRIMARY
// needs to change — the rest of the UI reads this constant via
// style.css.php.
define('COLOR_PRIMARY', '#00594F');
define('FONT_FAMILY', "'Open Sans', sans-serif"); // Our suggested font in the PI Brand Guide is Open Sans but you can change this to your preference

// ---- Map (MapLibre) ---------------------------------------------
// Starting position of the map on the dashboard (e.g. the centre of your
// district/region).
define('MAP_CENTER_LAT', 51.5074);   // example: London > These are the latitude and longitude of your district or country. You can find these on googlemaps in the url or just search for an webapp like https://www.latlong.net/
define('MAP_CENTER_LNG', -0.1278);
define('MAP_ZOOM', 9);



// Radius of the transparent "coverage area" circle around a PI member's
// region point on the members map (in kilometres). Change it here and it
// applies everywhere.
define('MEMBER_REGION_RADIUS_KM', 25); // This shows the radius of a member. You can add his home town meeting as his or her region. It circles the area around a members home town meeting or living environment.

// A free MapTiler account gives a much nicer base map (street names,
// place names, better colours) than the bare MapLibre demo style. Go to
// https://cloud.maptiler.com/, create a free account (up to 100,000 map
// loads/month free), and paste the API key below. Leave blank to fall
// back automatically to the free demo map — nothing breaks if you skip
// this for now.
define('MAPTILER_KEY', ''); // You have to search for the API key when you make a free account
define('MAP_STYLE_URL', MAPTILER_KEY !== ''
    ? 'https://api.maptiler.com/maps/streets-v2/style.json?key=' . MAPTILER_KEY // You can change the design for the map by picking one on Maptiler and replacing "streets-v2" in the url
    : 'https://demotiles.maplibre.org/style.json'
);

// Restricts address search/geocoding (used when adding a PI Location or
// bulk-importing) to a single country, using a 2-letter ISO country code
// (e.g. 'us', 'gb', 'nl', 'ca', 'au'). Leave blank ('') to search
// worldwide instead — do this if your district covers multiple countries
// or you'd rather not restrict results at all.
define('GEOCODING_COUNTRY_CODE', '');

// Restricts address lookups (PI Locations, member coverage regions) to a
// single country, using a two-letter ISO code (e.g. 'nl', 'us', 'gb').
// Leave blank ('') to search worldwide instead — useful if your district
// covers more than one country, or you'd rather not restrict results at all.
define('GEOCODE_COUNTRY', 'nl');

// ---- Email notifications (optional) -----------------------------
// Leave blank ('') = no email, notifications only appear inside the
// portal itself. Fill in = the portal will also try to send an email on
// notifications (new request, approval, etc.).
//
// Example for Gmail: NEVER use your regular Gmail password for
// SMTP_PASS. Turn on 2-step verification on the Google account first,
// then create an "app password" via myaccount.google.com/apppasswords,
// and use that 16-character code below (remove the spaces).
define('SMTP_HOST', 'smtp.gmail.com');   // blank ('') = email off, in-app notification only but it's recommended to have a group committee PI email address which you can use here to set up as mailing account for the WebPortals functionality > This will send emails automatically to your team when requests are being made
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-district-email@gmail.com');
define('SMTP_PASS', 'FILL-IN-YOUR-16-CHARACTER-APP-PASSWORD');  // Find the generator for  your app password; in google gmail its here myaccount.google.com/apppasswords
define('SMTP_FROM', 'your-district-email@gmail.com');

// ---- Drive links (category 5 — extendable later) --------
define('DRIVE_ROOT_URL', '');      // e.g. link to the district's root folder on Drive > only when you have a paid subscription drive

// ---- Automatic attachment backup to Google Drive (optional) --
// Without this, receipts/invoices are simply kept locally on your
// hosting (works fine on its own). If you'd like them to also land
// automatically in a shared Drive folder, fill in the folder ID below
// AND place a Google Service Account key file named
// 'google_service_account.json' in the (protected) data/ folder. Without
// that key file this setting does nothing — no errors, just local
// storage.
define('DRIVE_FOLDER_ID', ''); // > only when you have a paid subscription drive

// ---- Attachment storage (receipts/invoices) — pick your method ------
// Each district chooses its own storage method here. Only fill in the
// settings for the method you pick; leave the rest blank. If the chosen
// method ever fails (not fully configured yet, temporary outage), the
// system always falls back to 'local' — an upload should never fail
// outright.
//
// Available values: 'local' | 'kdrive' | 'drive'
// (easy to extend later with e.g. 'dropbox', 's3', 'onedrive' — just add
// a new lib_xxx.php file plus a case below in api_finance.php, without
// touching the rest of the application)
define('STORAGE_BACKEND', 'local'); // > only when you have a paid subscription drive

// -- Settings for STORAGE_BACKEND = 'kdrive' (Infomaniak, via WebDAV) --
// Simplest option: just a username/password, no quota headaches.
// KDRIVE_ID is found in the URL when you open a folder in your kDrive:
// ksuite.infomaniak.com/.../kdrive/app/drive/THIS-IS-THE-ID/files/...
// KDRIVE_USERNAME is your Infomaniak login email address.
// KDRIVE_PASSWORD: if you use 2FA, use an application password (created
// via the Infomaniak admin panel), never your regular password.
// KDRIVE_MAP is the path of the folder inside your kDrive where
// attachments should go (e.g. 'Finance/Attachments') — it must already
// exist, it is not created automatically. Leave blank = top of the root
// folder.
define('KDRIVE_ID', ''); //> only when you have a paid subscription drive
define('KDRIVE_USERNAME', '');
define('KDRIVE_PASSWORD', '');
define('KDRIVE_MAP', '');

// ---- Limit for local attachment storage (only relevant when
// STORAGE_BACKEND = 'local') --------------------------------------------------------
// Once the total size of all locally stored attachments (receipts/
// invoices) approaches this limit, new uploads are refused until a
// backup has been downloaded and old attachments cleaned up — this way
// hosting storage can never fill up unexpectedly. 0 = no limit.
define('LOCAL_ATTACHMENTS_LIMIT_MB', 400); // this is the max of mb that will be used on your web server as storage location 

// -- Settings for STORAGE_BACKEND = 'drive' (Google, via Service Account) --
// Note: only works with a Google Workspace account (Service Accounts
// don't get their own storage quota on regular/personal Gmail accounts).
// Requires the key file 'google_service_account.json' in the (protected)
// data/ folder. Uses the DRIVE_FOLDER_ID defined above.

// ---- Fixed fallback email addresses for coordinators --------
// As soon as someone is given a coordinator role (e.g.
// 'coordinator_distribution', 'coordinator_outreach', 'media_coordinator',
// 'social_media_coordinator') via the member-management screen,
// notifications go automatically to that person instead of the address
// below. Until then (or if for whatever reason no one currently holds
// that role), the system falls back to these addresses, so a
// notification is never simply lost. This includes, for example, new
// campaign/campaign-idea submissions: physical ones notify whoever holds
// 'media_coordinator', online/social ones notify 'social_media_coordinator'
// (see the PI Locations module).
// Keys here MUST exactly match the keys used in the ROLES array above —
// this is only a fallback lookup by that exact key.
const COORDINATOR_EMAILS = [
    'coordinator_outreach' => '',
    'coordinator_distribution' => '',
    'treasurer' => '',
    'secretary' => '',
    'media_coordinator' => '',
    'social_media_coordinator' => '',
];

// ---- Default categories for transactions (Finance) --------------
// This list fills the dropdown on "+ Add transaction". Feel free to add
// your own frequently-used categories, or edit these — the app always
// automatically adds an "Other…" option with a free-text field. Note:
// the system categories (loss/shrinkage, advances, on-account purchase,
// reimbursements, orders) are used automatically by the portal itself
// for certain actions — you can leave them in this list or remove them,
// but the app will keep creating them regardless.
const TRANSACTION_CATEGORIES = [
    'printing',
    'outreach',
    'rent',
    'insurance',
    'bank fees',
    'donations',
    'district budget',
    'fine',
    'reimbursements',
    'orders',
    'advances',
    'on-account purchase',
    'loss / shrinkage',
];

// ---- Committee service roles -----------------------------------------
// Based on the official C.A. World Service Conference Public Information
// (WSCPI) Handbook. Feel free to add district-specific roles, but keep
// the ones below unless your district genuinely doesn't use them — the
// rest of the application checks for these exact keys.
//
// Key            = internal identifier (never changes, not shown to users)
// Value          = human-readable label shown throughout the portal
const ROLES = [
    'admin'                     	=> 'District IT (administrator)',
    'chair'                		=> 'PI Committee Chairperson',
    'treasurer'            		=> 'PI Treasurer',
    'secretary'                		=> 'PI Secretary',
    'coordinator_distribution'      	=> 'PI Print Distribution Coordinator',
    'coordinator_outreach' 	 	=> 'PI Presentation Outreach Coordinator',
    'coordinator_literature'    	=> 'PI Literature Coordinator',
    'media_coordinator'         	=> 'PI Media Coordinator',
    'social_media_coordinator'  	=> 'PI Social Media Coordinator',
    'liaison'                       	=> 'Group PI Liaison',
];
