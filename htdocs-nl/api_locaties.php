<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();
require_once __DIR__ . '/lib_opslag.php';

$action = $_GET['action'] ?? '';

function locatie_path($id) {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
    return DATA_DIR . '/locaties/' . $safe . '.json';
}

function campagne_path($id) {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
    return DATA_DIR . '/campagnes/' . $safe . '.json';
}

function target_path($id) {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
    return DATA_DIR . '/campagne_targets/' . $safe . '.json';
}

function idee_path($id) {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
    return DATA_DIR . '/campagne_ideeen/' . $safe . '.json';
}

// ---- Bijlagen (materialen) bij ideeën, campagnes en targets ---------------------
// Bestanden staan in data/campagne_ideeen/materialen/ onder een willekeurige naam.
// De browser stuurt alleen {bestandsnaam, pad} terug; we vertrouwen 'pad' nooit
// blind (anders zou een verzonnen pad als ../../users.json bij verwijderen
// een willekeurig bestand kunnen wissen).
function materialen_dir() { return DATA_DIR . '/campagne_ideeen/materialen'; }
function materialen_schoon($lijst) {
    $uit = [];
    foreach ((array)$lijst as $m) {
        if (!is_array($m)) continue;
        $pad = (string)($m['pad'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $pad)) continue;
        $naam = leden_schone_bestandsnaam($m['bestandsnaam'] ?? $pad);
        $uit[] = ['bestandsnaam' => $naam, 'pad' => $pad];
        if (count($uit) >= 20) break;
    }
    return $uit;
}
function materialen_verwijder($lijst, $behoud = []) {
    $behoudPaden = array_column($behoud, 'pad');
    foreach ((array)$lijst as $m) {
        $pad = (string)($m['pad'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $pad) || in_array($pad, $behoudPaden, true)) continue;
        $vol = materialen_dir() . '/' . $pad;
        if (is_file($vol)) @unlink($vol);
    }
}

// Wie mag een campagne/idee van een bepaald type goedkeuren/uitvoeren:
// fysiek (drukwerk/pers/media) via de Media Coördinator, online (social
// media) via de Coördinator Social Media. IT kan in beide gevallen ook.
function goedkeurRollenVoorType($type) {
    return $type === 'online' ? ['admin', 'social_media_coordinator'] : ['admin', 'media_coordinator'];
}

// Bepaalt de statuskleur o.b.v. de meest recente activiteit-datum.
// Hoe langer geleden, hoe "alarmerender" de kleur — van donkergroen (net gedaan)
// via het geel/oranje-midden naar donkerrood (al een jaar niets), en grijs voor
// locaties waar nog nooit iets geregistreerd is.
//   <30 dagen        donkergroen
//   30-60 dagen      lichtgroen
//   60-90 dagen      geelgroen
//   90 dagen-6mnd    geel
//   6-9 maanden      oranjegeel
//   9-12 maanden     rood
//   >12 maanden      donkerrood
//   nog geen activiteit  grijs
function status_kleur($laatsteActiviteit) {
    if (!$laatsteActiviteit) return ['kleur' => 'grijs', 'dagen' => null];
    $dagen = floor((time() - strtotime($laatsteActiviteit)) / 86400);
    if ($dagen < 30) $kleur = 'donkergroen';
    elseif ($dagen < 60) $kleur = 'lichtgroen';
    elseif ($dagen < 90) $kleur = 'geelgroen';
    elseif ($dagen < 180) $kleur = 'geel';
    elseif ($dagen < 270) $kleur = 'oranjegeel';
    elseif ($dagen < 365) $kleur = 'rood';
    else $kleur = 'donkerrood';
    return ['kleur' => $kleur, 'dagen' => (int)$dagen];
}

function laatste_activiteit_datum($locatie) {
    $datums = array_column($locatie['activiteiten'] ?? [], 'datum');
    if (empty($datums)) return null;
    rsort($datums);
    return $datums[0];
}

function locatie_met_status($locatie) {
    $laatste = laatste_activiteit_datum($locatie);
    $status = status_kleur($laatste);
    $locatie['laatsteActiviteit'] = $laatste;
    $locatie['statusKleur'] = $status['kleur'];
    $locatie['dagenSindsLaatsteActiviteit'] = $status['dagen'];
    $locatie['aantalActiviteiten'] = count($locatie['activiteiten'] ?? []);
    return $locatie;
}

function alle_locaties() {
    $out = [];
    foreach (glob(DATA_DIR . '/locaties/*.json') as $file) {
        $locatie = read_json($file, null);
        if ($locatie) $out[] = locatie_met_status($locatie);
    }
    usort($out, fn($a, $b) => strcmp($a['naam'] ?? '', $b['naam'] ?? ''));
    return $out;
}

switch ($action) {

    // ---- Locaties -----------------------------------------------------

    case 'list': {
        respond(['locaties' => alle_locaties()]);
        break;
    }

    case 'get': {
        $id = $_GET['id'] ?? '';
        $locatie = read_json(locatie_path($id), null);
        if (!$locatie) respond(['error' => 'Locatie niet gevonden.'], 404);
        // Nieuwste activiteit bovenaan voor de tijdlijn.
        usort($locatie['activiteiten'], fn($a, $b) => strcmp($b['datum'], $a['datum']));
        respond(['locatie' => locatie_met_status($locatie)]);
        break;
    }

    case 'create': {
        $in = json_input();
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'Naam van de instantie is verplicht.'], 400);

        $id = uuid();
        $locatie = [
            'id' => $id,
            'naam' => trim($in['naam']),
            'type' => $in['type'] ?? '',
            'adres' => $in['adres'] ?? '',
            'postcode' => $in['postcode'] ?? '',
            'plaats' => $in['plaats'] ?? '',
            'lat' => isset($in['lat']) ? (float)$in['lat'] : null,
            'lng' => isset($in['lng']) ? (float)$in['lng'] : null,
            'contact' => [
                'receptieTelefoon' => $in['contact']['receptieTelefoon'] ?? '',
                'algemeenEmail' => $in['contact']['algemeenEmail'] ?? '',
                'contactpersoonNaam' => $in['contact']['contactpersoonNaam'] ?? '',
                'contactpersoonTelefoon' => $in['contact']['contactpersoonTelefoon'] ?? '',
                'contactpersoonEmail' => $in['contact']['contactpersoonEmail'] ?? '',
            ],
            'acties' => [
                'flyersToegestaan' => (bool)($in['acties']['flyersToegestaan'] ?? false),
                'posterToegestaan' => (bool)($in['acties']['posterToegestaan'] ?? false),
                'voorlichtingPersoneelInteresse' => (bool)($in['acties']['voorlichtingPersoneelInteresse'] ?? false),
                'folderClientenInteresse' => (bool)($in['acties']['folderClientenInteresse'] ?? false),
                'infoPakketGeleverd' => (bool)($in['acties']['infoPakketGeleverd'] ?? false),
                'heeftFolderrek' => (bool)($in['acties']['heeftFolderrek'] ?? false),
                'wilFolderrek' => (bool)($in['acties']['wilFolderrek'] ?? false),
                'contactOnderhoudGewenst' => (bool)($in['acties']['contactOnderhoudGewenst'] ?? false),
                'contactOnderhoudMethode' => $in['acties']['contactOnderhoudMethode'] ?? '', // 'email' | 'telefoon'
                'schermAanwezig' => (bool)($in['acties']['schermAanwezig'] ?? false),
                'schermOrientatie' => $in['acties']['schermOrientatie'] ?? '', // 'horizontaal' | 'verticaal'
                'schermAnimatieInteresse' => (bool)($in['acties']['schermAnimatieInteresse'] ?? false),
                'isInrichting' => (bool)($in['acties']['isInrichting'] ?? false),
                'heeftClientenBibliotheek' => (bool)($in['acties']['heeftClientenBibliotheek'] ?? false),
                'literatuurBehoefteCA_AA' => (bool)($in['acties']['literatuurBehoefteCA_AA'] ?? false),
            ],
            'notitie' => $in['notitie'] ?? '',
            'activiteiten' => [],
            'createdAt' => time() * 1000,
            'createdBy' => current_user_slug(),
            'updatedAt' => time() * 1000,
        ];
        write_json(locatie_path($id), $locatie);
        respond(['locatie' => locatie_met_status($locatie)], 201);
        break;
    }

    case 'update': {
        $id = $_GET['id'] ?? '';
        $locatie = read_json(locatie_path($id), null);
        if (!$locatie) respond(['error' => 'Locatie niet gevonden.'], 404);
        $in = json_input();

        // Alleen bekende, toegestane velden overschrijven; activiteiten en
        // id blijven buiten deze route (die lopen via activity_add/delete).
        foreach (['naam', 'type', 'adres', 'postcode', 'plaats', 'lat', 'lng', 'notitie'] as $veld) {
            if (array_key_exists($veld, $in)) $locatie[$veld] = $in[$veld];
        }
        if (isset($in['contact']) && is_array($in['contact'])) {
            $locatie['contact'] = array_merge($locatie['contact'], $in['contact']);
        }
        if (isset($in['acties']) && is_array($in['acties'])) {
            $locatie['acties'] = array_merge($locatie['acties'], $in['acties']);
        }
        $locatie['updatedAt'] = time() * 1000;
        write_json(locatie_path($id), $locatie);
        respond(['locatie' => locatie_met_status($locatie)]);
        break;
    }

    case 'delete': {
        require_role(['admin']);
        $id = $_GET['id'] ?? '';
        $path = locatie_path($id);
        if (file_exists($path)) unlink($path);
        respond(['deleted' => true]);
        break;
    }

    case 'duplicaten_zoeken': {
        // Groepeert locaties op naam+postcode+plaats (hoofdletterongevoelig,
        // spaties genegeerd) — precies wat er dubbel raakt als hetzelfde
        // CSV-bestand meerdere keren wordt geïmporteerd.
        $groepen = [];
        foreach (alle_locaties() as $l) {
            $sleutel = strtolower(preg_replace('/\s+/', '', $l['naam'] . '|' . $l['postcode'] . '|' . $l['plaats']));
            $groepen[$sleutel][] = $l;
        }
        $dubbels = array_values(array_filter($groepen, fn($g) => count($g) > 1));
        respond(['groepen' => $dubbels]);
        break;
    }

    case 'duplicaten_verwijderen': {
        require_role(['admin']);
        $groepen = [];
        foreach (alle_locaties() as $l) {
            $sleutel = strtolower(preg_replace('/\s+/', '', $l['naam'] . '|' . $l['postcode'] . '|' . $l['plaats']));
            $groepen[$sleutel][] = $l;
        }
        $verwijderd = 0;
        foreach ($groepen as $groep) {
            if (count($groep) <= 1) continue;
            // Bewaar de locatie met de meeste activiteiten (of, bij gelijke
            // stand, de oudste) — die bevat waarschijnlijk de meeste al
            // ingevoerde geschiedenis. De rest gaat weg.
            usort($groep, fn($a, $b) => $b['aantalActiviteiten'] <=> $a['aantalActiviteiten'] ?: $a['createdAt'] <=> $b['createdAt']);
            for ($i = 1; $i < count($groep); $i++) {
                $path = locatie_path($groep[$i]['id']);
                if (file_exists($path)) { unlink($path); $verwijderd++; }
            }
        }
        respond(['verwijderd' => $verwijderd]);
        break;
    }

    // ---- Activiteiten ---------------------------------------------------

    case 'activity_add': {
        $id = $_GET['id'] ?? '';
        $locatie = read_json(locatie_path($id), null);
        if (!$locatie) respond(['error' => 'Locatie niet gevonden.'], 404);
        $in = json_input();
        if (trim($in['type'] ?? '') === '') respond(['error' => 'Kies een type activiteit.'], 400);

        $locatie['activiteiten'][] = [
            'id' => uuid(),
            'datum' => $in['datum'] ?? date('Y-m-d'),
            'type' => $in['type'],
            'omschrijving' => $in['omschrijving'] ?? '',
            'door' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        $locatie['updatedAt'] = time() * 1000;
        write_json(locatie_path($id), $locatie);
        respond(['locatie' => locatie_met_status($locatie)], 201);
        break;
    }

    case 'activity_delete': {
        $id = $_GET['id'] ?? '';
        $activiteitId = $_GET['activiteitId'] ?? '';
        $locatie = read_json(locatie_path($id), null);
        if (!$locatie) respond(['error' => 'Locatie niet gevonden.'], 404);
        $locatie['activiteiten'] = array_values(array_filter(
            $locatie['activiteiten'],
            fn($a) => $a['id'] !== $activiteitId
        ));
        write_json(locatie_path($id), $locatie);
        respond(['locatie' => locatie_met_status($locatie)]);
        break;
    }

    // ---- Bulk-import (CSV, al ontleed door de browser tot rijen) -------

    case 'bulk_import': {
        $in = json_input();
        $rijen = $in['rijen'] ?? [];
        $aangemaakt = 0;
        $fouten = [];

        foreach ($rijen as $i => $rij) {
            $naam = trim($rij['naam'] ?? '');
            if ($naam === '') { $fouten[] = "Rij " . ($i + 2) . ": naam ontbreekt."; continue; }

            // Coördinaten: gebruik lat/lng uit de CSV als die er zijn,
            // anders proberen we automatisch te geocoderen op basis van
            // adres/postcode/plaats (zelfde als bij losse locaties).
            $lat = isset($rij['lat']) && $rij['lat'] !== '' ? (float)$rij['lat'] : null;
            $lng = isset($rij['lng']) && $rij['lng'] !== '' ? (float)$rij['lng'] : null;
            if ($lat === null && defined('MAPTILER_KEY') && MAPTILER_KEY !== '') {
                $adresVoorGeocode = trim(($rij['adres'] ?? '') . ' ' . ($rij['postcode'] ?? '') . ' ' . ($rij['plaats'] ?? ''));
                if ($adresVoorGeocode !== '') {
                    $geoUrl = 'https://api.maptiler.com/geocoding/' . rawurlencode($adresVoorGeocode) . '.json?key=' . MAPTILER_KEY . '&country=nl&limit=1';
                    $geoJson = @file_get_contents($geoUrl, false, stream_context_create(['http' => ['timeout' => 6]]));
                    $geoData = $geoJson ? json_decode($geoJson, true) : null;
                    if (!empty($geoData['features'])) {
                        [$lng, $lat] = $geoData['features'][0]['center'];
                    }
                }
            }

            $id = uuid();
            $locatie = [
                'id' => $id,
                'naam' => $naam,
                'type' => $rij['type'] ?? '',
                'adres' => $rij['adres'] ?? '',
                'postcode' => $rij['postcode'] ?? '',
                'plaats' => $rij['plaats'] ?? '',
                'lat' => $lat,
                'lng' => $lng,
                'contact' => [
                    'receptieTelefoon' => $rij['receptieTelefoon'] ?? '',
                    'algemeenEmail' => $rij['algemeenEmail'] ?? '',
                    'contactpersoonNaam' => $rij['contactpersoonNaam'] ?? '',
                    'contactpersoonTelefoon' => $rij['contactpersoonTelefoon'] ?? '',
                    'contactpersoonEmail' => $rij['contactpersoonEmail'] ?? '',
                ],
                'acties' => [
                    'flyersToegestaan' => filter_var($rij['flyersToegestaan'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'posterToegestaan' => filter_var($rij['posterToegestaan'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'voorlichtingPersoneelInteresse' => filter_var($rij['voorlichtingPersoneelInteresse'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'folderClientenInteresse' => filter_var($rij['folderClientenInteresse'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'infoPakketGeleverd' => filter_var($rij['infoPakketGeleverd'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'heeftFolderrek' => filter_var($rij['heeftFolderrek'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'wilFolderrek' => filter_var($rij['wilFolderrek'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'contactOnderhoudGewenst' => filter_var($rij['contactOnderhoudGewenst'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'contactOnderhoudMethode' => $rij['contactOnderhoudMethode'] ?? '',
                    'schermAanwezig' => filter_var($rij['schermAanwezig'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'schermOrientatie' => $rij['schermOrientatie'] ?? '',
                    'schermAnimatieInteresse' => filter_var($rij['schermAnimatieInteresse'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'isInrichting' => filter_var($rij['isInrichting'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'heeftClientenBibliotheek' => filter_var($rij['heeftClientenBibliotheek'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'literatuurBehoefteCA_AA' => filter_var($rij['literatuurBehoefteCA_AA'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
                'notitie' => $rij['notitie'] ?? '',
                'activiteiten' => [],
                'createdAt' => time() * 1000,
                'createdBy' => current_user_slug(),
                'updatedAt' => time() * 1000,
            ];

            // Optioneel: eerste activiteit meteen meenemen als de CSV-rij
            // een laatsteActiviteitDatum/type/omschrijving bevat.
            if (!empty($rij['laatsteActiviteitDatum'])) {
                $locatie['activiteiten'][] = [
                    'id' => uuid(),
                    'datum' => $rij['laatsteActiviteitDatum'],
                    'type' => $rij['laatsteActiviteitType'] ?? 'bezoek',
                    'omschrijving' => $rij['laatsteActiviteitOmschrijving'] ?? 'Geïmporteerd vanuit bulk-CSV.',
                    'door' => $_SESSION['user_name'] ?? current_user_slug(),
                    'createdAt' => time() * 1000,
                ];
            }

            write_json(locatie_path($id), $locatie);
            $aangemaakt++;
        }

        respond(['aangemaakt' => $aangemaakt, 'fouten' => $fouten]);
        break;
    }

    // ---- Kaart (GeoJSON voor MapLibre) ----------------------------------

    // ---- Geocoding (adres -> coördinaten, via MapTiler) -----------------
    // Gebruikt dezelfde MAPTILER_KEY als de kaart. Werkt alleen als er een
    // key is ingevuld in district.php; anders (of bij een netwerkfout op de
    // hosting) geeft dit gewoon 'gevonden: false' terug — de gebruiker kan
    // dan nog steeds handmatig lat/lng invullen, dus dit mag nooit blokkeren.
    case 'geocode': {
        $adres = trim(($_GET['adres'] ?? '') . ' ' . ($_GET['postcode'] ?? '') . ' ' . ($_GET['plaats'] ?? ''));
        if ($adres === '' || !defined('MAPTILER_KEY') || MAPTILER_KEY === '') {
            respond(['gevonden' => false]);
        }
        $url = 'https://api.maptiler.com/geocoding/' . rawurlencode($adres) . '.json?key=' . MAPTILER_KEY . '&country=nl&limit=1';
        $context = stream_context_create(['http' => ['timeout' => 8]]);
        $json = @file_get_contents($url, false, $context);
        $data = $json ? json_decode($json, true) : null;

        if (empty($data['features'])) respond(['gevonden' => false]);
        [$lng, $lat] = $data['features'][0]['center'];
        respond(['gevonden' => true, 'lat' => $lat, 'lng' => $lng, 'label' => $data['features'][0]['place_name'] ?? '']);
        break;
    }

    case 'geojson': {
        $features = [];
        foreach (alle_locaties() as $l) {
            if ($l['lat'] === null || $l['lng'] === null) continue;
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [$l['lng'], $l['lat']]],
                'properties' => [
                    'id' => $l['id'],
                    'naam' => $l['naam'],
                    'statusKleur' => $l['statusKleur'],
                    'dagenSindsLaatsteActiviteit' => $l['dagenSindsLaatsteActiviteit'],
                    'plaats' => $l['plaats'],
                ],
            ];
        }
        respond(['type' => 'FeatureCollection', 'features' => $features]);
        break;
    }

    // ---- Campagnes (categorie 1, campagne-mail-tab) ---------------------

    case 'campaign_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/campagnes/*.json') as $file) {
            $c = read_json($file, null);
            if ($c) $out[] = $c;
        }
        usort($out, fn($a, $b) => strcmp($b['datum'] ?? '', $a['datum'] ?? ''));
        respond(['campagnes' => $out]);
        break;
    }

    case 'campaign_create': {
        $in = json_input();
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'Naam van de campagne is verplicht.'], 400);
        $type = ($in['type'] ?? '') === 'online' ? 'online' : 'fysiek';
        $id = uuid();
        $campagne = [
            'id' => $id,
            'naam' => trim($in['naam']),
            'type' => $type, // fysiek | online — bepaalt wie 'm goedkeurt
            'datum' => $in['datum'] ?? date('Y-m-d'),
            'kanaal' => $in['kanaal'] ?? 'Laposta',
            'omschrijving' => $in['omschrijving'] ?? '',
            'deelnemers' => [],
            // Iedereen mag een campagne aanmaken/voorstellen — de juiste
            // coördinator (Media voor fysiek, Social Media voor online, of
            // IT) keurt 'm daarna goed of af.
            'status' => 'wacht_op_goedkeuring', // wacht_op_goedkeuring | goedgekeurd | afgewezen
            'goedgekeurdDoor' => null,
            'goedgekeurdOp' => null,
            'opmerkingVoorzitter' => '',
            'materialen' => materialen_schoon($in['materialen'] ?? []),
            'createdAt' => time() * 1000,
            'createdBy' => current_user_slug(),
        ];
        write_json(campagne_path($id), $campagne);
        notify_role($type === 'online' ? 'social_media_coordinator' : 'media_coordinator', 'Nieuwe campagne ter goedkeuring — ' . $campagne['naam'],
            ($_SESSION['user_name'] ?? current_user_slug()) . ' stelt de campagne "' . $campagne['naam'] . '" voor (' . $campagne['kanaal'] . ').',
            'locaties.php?tab=campagnes');
        respond(['campagne' => $campagne], 201);
        break;
    }

    // Campagne aanpassen (o.a. bijlagen toevoegen): de maker, de coördinatoren, Voorzitter of IT.
    case 'campaign_update': {
        $id = $_GET['id'] ?? '';
        $campagne = read_json(campagne_path($id), null);
        if (!$campagne) respond(['error' => 'Campagne niet gevonden.'], 404);
        $eigen = ($campagne['createdBy'] ?? '') === current_user_slug();
        if (!$eigen && !heeft_rol(['admin', 'voorzitter', 'media_coordinator', 'social_media_coordinator'])) respond(['error' => 'Geen toestemming.'], 403);
        $in = json_input();
        if (isset($in['naam'])) {
            if (trim($in['naam']) === '') respond(['error' => 'Naam van de campagne is verplicht.'], 400);
            $campagne['naam'] = trim($in['naam']);
        }
        if (isset($in['datum'])) $campagne['datum'] = $in['datum'];
        if (isset($in['kanaal'])) $campagne['kanaal'] = mb_substr(trim($in['kanaal']), 0, 80);
        if (isset($in['omschrijving'])) $campagne['omschrijving'] = $in['omschrijving'];
        if (isset($in['materialen']) && is_array($in['materialen'])) {
            $nieuweMat = materialen_schoon($in['materialen']);
            materialen_verwijder($campagne['materialen'] ?? [], $nieuweMat);
            $campagne['materialen'] = $nieuweMat;
        }
        write_json(campagne_path($id), $campagne);
        respond(['campagne' => $campagne]);
        break;
    }

    case 'campaign_get': {
        $id = $_GET['id'] ?? '';
        $campagne = read_json(campagne_path($id), null);
        if (!$campagne) respond(['error' => 'Campagne niet gevonden.'], 404);
        respond(['campagne' => $campagne]);
        break;
    }

    case 'campaign_add_deelnemer': {
        $id = $_GET['id'] ?? '';
        $campagne = read_json(campagne_path($id), null);
        if (!$campagne) respond(['error' => 'Campagne niet gevonden.'], 404);
        $in = json_input();
        $campagne['deelnemers'][] = [
            'id' => uuid(),
            'naam' => $in['naam'] ?? '',
            'email' => $in['email'] ?? '',
            'locatieId' => $in['locatieId'] ?? null,
            'reactie' => $in['reactie'] ?? '',           // bijv. 'geen reactie', 'interesse', 'afspraak gemaakt'
            'vervolgactie' => $in['vervolgactie'] ?? '', // bijv. 'bestelling', 'voorlichting inplannen'
            'datum' => $in['datum'] ?? date('Y-m-d'),
            'createdAt' => time() * 1000,
        ];
        write_json(campagne_path($id), $campagne);
        respond(['campagne' => $campagne], 201);
        break;
    }

    case 'campaign_goedkeuren': {
        $id = $_GET['id'] ?? '';
        $campagne = read_json(campagne_path($id), null);
        if (!$campagne) respond(['error' => 'Campagne niet gevonden.'], 404);
        require_role(goedkeurRollenVoorType($campagne['type'] ?? 'fysiek'));
        if (($campagne['status'] ?? '') !== 'wacht_op_goedkeuring') respond(['error' => 'Deze campagne staat niet meer als aanvraag open.'], 400);
        $campagne['status'] = 'goedgekeurd';
        $campagne['goedgekeurdDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $campagne['goedgekeurdOp'] = time() * 1000;
        write_json(campagne_path($id), $campagne);
        if (!empty($campagne['createdBy'])) {
            notify([$campagne['createdBy']], 'Campagne goedgekeurd — ' . $campagne['naam'], 'Je voorgestelde campagne "' . $campagne['naam'] . '" is goedgekeurd.', 'locaties.php?tab=campagnes');
        }
        respond(['campagne' => $campagne]);
        break;
    }

    case 'campaign_afwijzen': {
        $id = $_GET['id'] ?? '';
        $campagne = read_json(campagne_path($id), null);
        if (!$campagne) respond(['error' => 'Campagne niet gevonden.'], 404);
        require_role(goedkeurRollenVoorType($campagne['type'] ?? 'fysiek'));
        if (($campagne['status'] ?? '') !== 'wacht_op_goedkeuring') respond(['error' => 'Deze campagne staat niet meer als aanvraag open.'], 400);
        $in = json_input();
        $campagne['status'] = 'afgewezen';
        $campagne['opmerkingVoorzitter'] = trim($in['opmerking'] ?? '');
        write_json(campagne_path($id), $campagne);
        if (!empty($campagne['createdBy'])) {
            notify([$campagne['createdBy']], 'Campagne afgewezen — ' . $campagne['naam'], 'Je voorgestelde campagne "' . $campagne['naam'] . '" is afgewezen.' . ($campagne['opmerkingVoorzitter'] ? ("\nToelichting: " . $campagne['opmerkingVoorzitter']) : ''), 'locaties.php?tab=campagnes');
        }
        respond(['campagne' => $campagne]);
        break;
    }

    // ================= TARGETS (instanties voor mediacampagnes) =================

    case 'target_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/campagne_targets/*.json') as $file) {
            $t = read_json($file, null);
            if ($t) $out[] = $t;
        }
        usort($out, fn($a, $b) => strcmp($a['naam'], $b['naam']));
        respond(['targets' => $out]);
        break;
    }

    case 'target_create': {
        $in = json_input();
        $naam = trim($in['naam'] ?? '');
        if ($naam === '') respond(['error' => 'Naam is verplicht.'], 400);
        $id = uuid();
        $target = [
            'id' => $id,
            'naam' => $naam,
            'type' => ($in['type'] ?? '') === 'online' ? 'online' : 'fysiek',
            'omschrijving' => trim($in['omschrijving'] ?? ''),
            'materialen' => materialen_schoon($in['materialen'] ?? []),
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(target_path($id), $target);
        respond(['target' => $target], 201);
        break;
    }

    case 'target_update': {
        $id = $_GET['id'] ?? '';
        $target = read_json(target_path($id), null);
        if (!$target) respond(['error' => 'Target niet gevonden.'], 404);
        $in = json_input();
        if (isset($in['naam'])) {
            $naam = trim($in['naam']);
            if ($naam === '') respond(['error' => 'Naam is verplicht.'], 400);
            $target['naam'] = $naam;
        }
        if (isset($in['omschrijving'])) $target['omschrijving'] = trim($in['omschrijving']);
        if (isset($in['type'])) $target['type'] = $in['type'] === 'online' ? 'online' : 'fysiek';
        if (isset($in['materialen']) && is_array($in['materialen'])) {
            $nieuweMat = materialen_schoon($in['materialen']);
            materialen_verwijder($target['materialen'] ?? [], $nieuweMat);
            $target['materialen'] = $nieuweMat;
        }
        write_json(target_path($id), $target);
        respond(['target' => $target]);
        break;
    }

    case 'target_delete': {
        $id = $_GET['id'] ?? '';
        $target = read_json(target_path($id), null);
        if ($target) {
            $magVerwijderen = heeft_rol(['admin', 'voorzitter']) || $target['createdBy'] === ($_SESSION['user_name'] ?? '');
            if (!$magVerwijderen) respond(['error' => 'Geen toestemming.'], 403);
            materialen_verwijder($target['materialen'] ?? []);
        }
        if (file_exists(target_path($id))) unlink(target_path($id));
        respond(['deleted' => true]);
        break;
    }

    // ================= CAMPAGNE-IDEEËN (traject-overzicht per idee) =================

    case 'idee_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/campagne_ideeen/*.json') as $file) {
            $i = read_json($file, null);
            if ($i) $out[] = $i;
        }
        usort($out, fn($a, $b) => $b['createdAt'] <=> $a['createdAt']);
        respond(['ideeen' => $out]);
        break;
    }

    case 'idee_create': {
        $in = json_input();
        $titel = trim($in['titel'] ?? '');
        if ($titel === '') respond(['error' => 'Titel/plan is verplicht.'], 400);
        $type = ($in['type'] ?? '') === 'online' ? 'online' : 'fysiek';
        $id = uuid();
        $idee = [
            'id' => $id,
            'titel' => $titel,
            'type' => $type, // fysiek | online — bepaalt wie de uitvoering doet
            'targetId' => trim($in['targetId'] ?? '') ?: null,
            'targetNaam' => trim($in['targetNaam'] ?? ''),
            'materialen' => materialen_schoon($in['materialen'] ?? []),
            'deadline' => trim($in['deadline'] ?? '') ?: null,
            'benaderd' => !empty($in['benaderd']),
            'datumRegistratie' => date('Y-m-d'),
            'datumEersteContact' => trim($in['datumEersteContact'] ?? '') ?: null,
            'vervolgacties' => [],
            // Iedereen mag een idee/plan indienen — de uitvoering (afronden)
            // gebeurt door de juiste coördinator: Media (fysiek) of Social
            // Media (online).
            'status' => 'open', // open | afgerond
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(idee_path($id), $idee);
        notify_role($type === 'online' ? 'social_media_coordinator' : 'media_coordinator', 'Nieuw idee (Ideeontwikkeling) — ' . $titel,
            ($_SESSION['user_name'] ?? current_user_slug()) . ' heeft een idee geregistreerd: ' . $titel,
            'locaties.php?tab=campagne_ideeen');
        respond(['idee' => $idee], 201);
        break;
    }

    case 'idee_update': {
        $id = $_GET['id'] ?? '';
        $idee = read_json(idee_path($id), null);
        if (!$idee) respond(['error' => 'Idee niet gevonden.'], 404);
        $in = json_input();
        if (isset($in['titel'])) {
            $titel = trim($in['titel']);
            if ($titel === '') respond(['error' => 'Titel/plan is verplicht.'], 400);
            $idee['titel'] = $titel;
        }
        if (isset($in['targetId'])) $idee['targetId'] = trim($in['targetId']) ?: null;
        if (isset($in['targetNaam'])) $idee['targetNaam'] = trim($in['targetNaam']);
        if (isset($in['type'])) $idee['type'] = $in['type'] === 'online' ? 'online' : 'fysiek';
        if (isset($in['materialen']) && is_array($in['materialen'])) {
            $nieuweMat = materialen_schoon($in['materialen']);
            materialen_verwijder($idee['materialen'] ?? [], $nieuweMat); // weggehaalde bijlagen ook van schijf
            $idee['materialen'] = $nieuweMat;
        }
        if (isset($in['deadline'])) $idee['deadline'] = trim($in['deadline']) ?: null;
        if (isset($in['benaderd'])) $idee['benaderd'] = !empty($in['benaderd']);
        if (isset($in['datumEersteContact'])) $idee['datumEersteContact'] = trim($in['datumEersteContact']) ?: null;
        if (isset($in['vervolgacties']) && is_array($in['vervolgacties'])) {
            $idee['vervolgacties'] = array_map(function ($v) {
                return [
                    'id' => $v['id'] ?? uuid(),
                    'tekst' => trim($v['tekst'] ?? ''),
                    'datum' => trim($v['datum'] ?? '') ?: null,
                    'voltooid' => !empty($v['voltooid']),
                    'voltooidOp' => !empty($v['voltooid']) ? ($v['voltooidOp'] ?? date('Y-m-d')) : null,
                ];
            }, $in['vervolgacties']);
        }
        if (isset($in['status']) && in_array($in['status'], ['open', 'afgerond'], true)) {
            // Zelf op "afgerond" zetten mag alleen door de coördinator die
            // dit type idee daadwerkelijk uitvoert (Media/Social Media) of IT
            // — bewerken van de rest (materialen, vervolgacties, etc.) blijft
            // voor iedereen open.
            if ($in['status'] !== $idee['status'] && !heeft_rol(goedkeurRollenVoorType($idee['type'] ?? 'fysiek'))) {
                respond(['error' => 'Alleen de Media Coördinator (fysiek) / Coördinator Social Media (online) of IT kan de status wijzigen.'], 403);
            }
            $idee['status'] = $in['status'];
        }
        write_json(idee_path($id), $idee);
        respond(['idee' => $idee]);
        break;
    }

    case 'idee_delete': {
        $id = $_GET['id'] ?? '';
        $idee = read_json(idee_path($id), null);
        if ($idee) {
            $magVerwijderen = heeft_rol(['admin', 'voorzitter']) || $idee['createdBy'] === ($_SESSION['user_name'] ?? '');
            if (!$magVerwijderen) respond(['error' => 'Alleen de maker, Voorzitter of IT kan dit verwijderen.'], 403);
            materialen_verwijder($idee['materialen'] ?? []);
        }
        if (file_exists(idee_path($id))) unlink(idee_path($id));
        respond(['deleted' => true]);
        break;
    }

    // Materiaal (advertentie-concept, brief, artikel, correspondentie...)
    // uploaden — meerdere per idee mogelijk, één voor één.
    case 'materiaal_upload': {
        if (empty($_FILES['bestand'])) respond(['error' => 'Geen bestand ontvangen.'], 400);
        $file = $_FILES['bestand'];
        if (is_array($file['name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) respond(['error' => 'Upload mislukt.'], 400);
        if ($file['size'] > 15 * 1024 * 1024) respond(['error' => 'Bestand mag maximaal 15 MB zijn.'], 400);
        if (!rate_limit_toegestaan('materiaalupload:' . ($_SESSION['user_id'] ?? ''), 40, 3600)) respond(['error' => 'Te veel uploads; probeer het later opnieuw.'], 429);

        $toegestaan = [
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt', 'message/rfc822' => 'eml',
        ];
        // De extensie van de bestandsnaam moet toegestaan zijn (het MIME-type alleen is niet genoeg).
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'txt', 'eml', 'msg'], true)) {
            respond(['error' => 'Bestandstype niet ondersteund.'], 400);
        }

        // De inhoud moet bij het type passen (een script met de naam brief.pdf wordt geweigerd).
        if ($ext === 'jpeg') $ext = 'jpg';
        if (isset(leden_toegestane_typen()[$ext]) && !leden_inhoud_klopt($file['tmp_name'], $ext)) respond(['error' => 'De inhoud van het bestand past niet bij het type (.' . $ext . ').'], 400);
        rate_limit_registreer('materiaalupload:' . ($_SESSION['user_id'] ?? ''));

        $naam = veilige_token() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/campagne_ideeen/materialen/' . $naam)) {
            respond(['error' => 'Opslaan mislukt.'], 500);
        }
        respond(['bestandsnaam' => leden_schone_bestandsnaam($file['name']), 'pad' => $naam]);
        break;
    }

    case 'materiaal_stream': {
        $bestand = basename($_GET['bestand'] ?? '');
        $pad = DATA_DIR . '/campagne_ideeen/materialen/' . $bestand;
        if ($bestand === '' || !file_exists($pad)) { http_response_code(404); exit; }
        if (!preg_match('/^[a-f0-9]{64}\.[a-z0-9]{2,5}$/', $bestand)) { http_response_code(404); exit; }
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', preg_replace('/[^\x20-\x7E]/', '_', basename($_GET['naam'] ?? $bestand))) . '"');
        header('Content-Length: ' . filesize($pad));
        readfile($pad);
        exit;
    }

    // ---- Activiteitenfeed voor het dashboard ----------------------------
    // Alleen betekenisvolle gebeurtenissen (nieuwe locatie, nieuwe activiteit),
    // niet elke klik — zoals gevraagd.

    case 'feed': {
        $items = [];
        foreach (glob(DATA_DIR . '/locaties/*.json') as $file) {
            $l = read_json($file, null);
            if (!$l) continue;
            $items[] = [
                'tijd' => $l['createdAt'],
                'tekst' => htmlspecialchars($l['createdBy'] ?? 'Iemand') . ' voegde locatie "' . htmlspecialchars($l['naam']) . '" toe.',
            ];
            foreach ($l['activiteiten'] ?? [] as $a) {
                $items[] = [
                    'tijd' => $a['createdAt'],
                    'tekst' => htmlspecialchars($a['door'] ?? 'Iemand') . ' registreerde "' . htmlspecialchars($a['type']) . '" bij ' . htmlspecialchars($l['naam']) . '.',
                ];
            }
        }
        usort($items, fn($a, $b) => $b['tijd'] <=> $a['tijd']);
        respond(['items' => array_slice($items, 0, 20)]);
        break;
    }

    default:
        respond(['error' => 'onbekende actie'], 400);
}
