<?php
/**
 * ============================================================
 *  DISTRICT-CONFIGURATIE — PI WERKPORTAAL (C.A. Public Information)
 * ============================================================
 * Dit is het ENIGE bestand dat de District-IT hoeft aan te passen om
 * deze template over te nemen voor een nieuw district. Kopieer de hele
 * "php-backend" map naar de eigen hosting/domeinnaam, pas hieronder de
 * waarden aan, en klaar.
 *
 * Niets hier hoeft geheim te blijven behalve SMTP_PASS — zet dat NOOIT
 * in een bestand dat via het web te downloaden is zonder de .htaccess
 * bescherming die in data/ staat (dit bestand zelf staat in de hoofdmap
 * en wordt nooit direct als tekst geserveerd omdat het door PHP wordt
 * uitgevoerd, maar zorg dat 'display_errors' uitstaat op productie).
 */

// ---- Toegang tot dit district -----------------------------------
// Alleen wie deze code kent kan een account aanmaken — zo blijft het
// portaal besloten voor jullie team, zonder dat een admin elk account
// handmatig hoeft goed te keuren. Verander dit in iets eigens.
define('INVITE_CODE', 'VERANDER-DIT-IN-EEN-EIGEN-CODE');

// ---- Identiteit van dit district -----------------------------------
define('DISTRICT_NAME', 'Voorbeeld District');
define('DISTRICT_SLUG', 'voorbeeld-district');           // gebruikt in bestandsnamen, geen spaties
define('APP_URL', 'https://voorbeeld-district.jouw-domein.nl'); // zonder slash op het eind

// ---- Huisstijl --------------------------------------------------------
// Hoofdkleur + automatisch afgeleid subpalet (lichter/donkerder). Als een
// district een andere hoofdkleur wil, hoeft alleen COLOR_PRIMARY te
// veranderen — de rest van de UI leest deze constante uit via style.css.php.
define('COLOR_PRIMARY', '#00594F');
define('FONT_FAMILY', "'Open Sans', sans-serif");

// ---- Kaart (MapLibre) ---------------------------------------------
// Startpositie van de kaart op het dashboard (bijv. midden van de regio).
define('MAP_CENTER_LAT', 52.0907);
define('MAP_CENTER_LNG', 5.1214);
define('MAP_ZOOM', 9);

// Straal van de transparante werkgebied-cirkel rond het regio-punt van een
// PI-lid op de ledenkaart (in kilometers). Hier aanpassen werkt overal.
define('LID_REGIO_STRAAL_KM', 25);

// Gratis MapTiler-account geeft een veel mooiere basiskaart (straatnamen,
// plaatsnamen, betere kleuren) dan de kale MapLibre-demostijl. Ga naar
// https://cloud.maptiler.com/, maak een gratis account (tot 100.000
// kaartweergaven/maand gratis), en plak de API-key hieronder. Leeg laten
// = automatisch terugvallen op de gratis demo-kaart, dus niets breekt als
// je dit even overslaat.
define('MAPTILER_KEY', '');
define('MAP_STYLE_URL', MAPTILER_KEY !== ''
    ? 'https://api.maptiler.com/maps/streets-v2/style.json?key=' . MAPTILER_KEY
    : 'https://demotiles.maplibre.org/style.json'
);

// ---- E-mailmeldingen (optioneel) -----------------------------------
// Leeg laten ('') = geen e-mail, alleen het berichtenpaneel in het portaal.
// Invullen = het portaal probeert ook een e-mail te sturen bij meldingen
// (nieuwe aanvraag, goedkeuring, etc.).
//
// Voorbeeld voor Gmail: gebruik NOOIT je gewone Gmail-wachtwoord bij
// SMTP_PASS. Zet eerst 2-stapsverificatie aan op het Google-account, maak
// daarna een "app-wachtwoord" aan via myaccount.google.com/apppasswords,
// en gebruik die 16-tekenige code hieronder (spaties weglaten).
define('SMTP_HOST', 'smtp.gmail.com');   // leeg ('') = e-mail uit, alleen in-portaal bericht
define('SMTP_PORT', 587);
define('SMTP_USER', 'jouw-district@gmail.com');
define('SMTP_PASS', 'VUL-HIER-HET-16-TEKENIGE-APP-WACHTWOORD-IN');
define('SMTP_FROM', 'jouw-district@gmail.com');

// ---- Drive-koppelingen (categorie 5 — later uit te breiden) --------
define('DRIVE_ROOT_URL', '');      // bijv. link naar de hoofdmap van het district op Drive

// ---- Automatische opslag van bijlagen op Google Drive (optioneel) --
// Zonder dit blijven bonnetjes/facturen gewoon lokaal op de hosting staan
// (werkt prima). Wil je ze automatisch in een gedeelde Drive-map laten
// belanden, vul dan de map-ID hieronder in EN zet het sleutelbestand van
// een Google Service Account als 'google_service_account.json' in de
// (beveiligde) data/-map. Zonder dat sleutelbestand doet dit niets — geen
// foutmeldingen, gewoon lokale opslag.
define('DRIVE_FOLDER_ID', '');

// ---- Opslag van bijlagen (bonnetjes/facturen) — kies je methode ------
// Elk district kiest hier zelf welke opslagmethode gebruikt wordt. Vul
// alleen de instellingen in van de methode die je kiest; de rest mag leeg
// blijven. Lukt de gekozen methode een keer niet (nog niet compleet
// ingesteld, tijdelijke storing), dan valt het systeem altijd terug op
// 'lokaal' — een upload mag nooit volledig mislukken.
//
// Beschikbare waarden: 'lokaal' | 'kdrive' | 'drive'
// (later eenvoudig uit te breiden met bijv. 'dropbox', 's3', 'onedrive' —
// telkens een nieuw lib_xxx.php bestand + een case hieronder in
// api_financien.php, zonder de rest van de applicatie te hoeven aanpassen)
define('STORAGE_BACKEND', 'lokaal');

// -- Instellingen voor STORAGE_BACKEND = 'kdrive' (Infomaniak, via WebDAV) --
// Simpelste optie: gewoon gebruikersnaam/wachtwoord, geen quota-problemen.
// KDRIVE_ID vind je in de URL als je een map opent in je kDrive:
// ksuite.infomaniak.com/.../kdrive/app/drive/HIER-STAAT-HET-ID/files/...
// KDRIVE_USERNAME is je Infomaniak-inlog-e-mailadres.
// KDRIVE_PASSWORD: gebruik bij 2FA een toepassingswachtwoord (aan te maken
// via het Infomaniak-beheerpaneel), nooit je gewone wachtwoord.
// KDRIVE_MAP is het pad van de map binnen je kDrive waar bijlagen in moeten
// komen (bijv. 'Financieel/Bijlagen') — moet al bestaan, wordt niet
// automatisch aangemaakt. Leeg laten = bovenaan in de hoofdmap.
define('KDRIVE_ID', '');
define('KDRIVE_USERNAME', '');
define('KDRIVE_PASSWORD', '');
define('KDRIVE_MAP', '');

// ---- Limiet voor lokale bijlage-opslag (alleen relevant bij STORAGE_BACKEND
// = 'lokaal') --------------------------------------------------------
// Zodra de totale grootte van alle lokaal opgeslagen bijlagen (bonnetjes/
// facturen) deze grens nadert, worden nieuwe uploads geweigerd totdat er
// een back-up is gedownload en de oude bijlagen zijn opgeruimd — zo kan de
// hostingopslag nooit onverwacht vol raken. 0 = geen limiet.
define('LOKAAL_BIJLAGEN_LIMIET_MB', 400);

// -- Instellingen voor STORAGE_BACKEND = 'drive' (Google, via Service Account) --
// Let op: werkt alleen met een Google Workspace-account (Service Accounts
// hebben geen eigen opslagquota bij gewone/persoonlijke Gmail-accounts).
// Vereist het sleutelbestand 'google_service_account.json' in de
// (beveiligde) data/-map. Gebruikt de DRIVE_FOLDER_ID hierboven.

// ---- Optionele vangnet-adressen voor coördinatoren ------------------
// Je hoeft dit normaal NIET in te vullen. Zodra iemand via Ledenbeheer een
// rol krijgt (bijv. 'coordinator_drukwerk', 'coordinator_voorlichting',
// 'media_coordinator', 'social_media_coordinator'), ontvangt die persoon
// automatisch de meldingen voor die rol (IT-beheerders krijgen ze altijd
// ook). Alleen als nog niemand een rol heeft, valt het systeem terug op
// het adres hieronder, zodat een melding nooit zomaar verloren gaat.
// De sleutels MOETEN exact overeenkomen met de sleutels in de ROLES-lijst.
const COORDINATOR_EMAILS = [
    'coordinator_drukwerk' => '',
    'coordinator_voorlichting' => '',
    'penningmeester' => '',
    'secretaris' => '',
];

// ---- Configureerbare rollen per functie -----------------------------
// De District-IT vult hier in WIE welke coördinatierol heeft. De koppeling
// user-id (e-mailadres) -> rol wordt hierna nog per gebruiker gezet via het
// ledenbeheer-scherm (categorie 6) door de admin; dit blok is alleen de
// lijst met rollen die het systeem kent zodat andere districten dezelfde
// rolnamen gebruiken.
// ---- Standaard categorieën voor transacties (Financiën) --------------
// Deze lijst vult de dropdown bij "+ Transactie toevoegen". Vul hier gerust
// je eigen, vaak terugkerende categorieën aan of pas ze aan — de app voegt
// er altijd automatisch een "Overig…" optie aan toe met een los invulveld.
// Let op: de systeem-categorieën (derving, voorschotten, inkoop op
// rekening, declaraties, bestellingen) worden door het portaal zelf
// automatisch gebruikt bij bepaalde acties — die kun je laten staan of
// verwijderen uit deze lijst, maar de app blijft ze wel zo aanmaken.
const TRANSACTIE_CATEGORIEEN = [
    'drukwerk',
    'voorlichting',
    'huur',
    'verzekering',
    'bankkosten',
    'donaties',
    'districtsbudget',
    'boete',
    'declaraties',
    'bestellingen',
    'voorschotten',
    'inkoop op rekening',
    'derving',
];

const ROLES = [
    'admin'                     => 'District-IT (beheerder)',
    'voorzitter'                => 'PI Voorzitter',
    'penningmeester'            => 'PI Penningmeester',
    'secretaris'                => 'PI Secretaris',
    'coordinator_drukwerk'      => 'PI Coördinator Drukwerk',
    'coordinator_voorlichting'  => 'PI Coördinator Voorlichting',
    'coordinator_literatuur'    => 'PI Coördinator Literatuur',
    'media_coordinator'         => 'PI Media Coördinator',
    'social_media_coordinator'  => 'PI Coördinator Social Media',
    'lid'                       => 'PI liaison',
];

// ---- Demo-account (STANDAARD UIT) --------------------------------------
// Een ingebouwd alleen-lezen "dummy"-account (login: dummy / dummy) laat
// mensen rondkijken zonder iets te kunnen wijzigen. Staat standaard UIT.
// Haal de twee slashes voor de regel hieronder weg om het aan te zetten,
// bijv. voor een presentatie. Laat het uit op een live portaal met echte data.
// define('DEMO_ACCOUNT_ENABLED', true);
