<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();

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

// Who may approve/carry out a campaign or idea of a given type: physical
// (print/press/media) via the Media Coordinator, online (social media)
// via the Social Media Coordinator. IT can do both.
function goedkeurRollenVoorType($type) {
    return $type === 'online' ? ['admin', 'social_media_coordinator'] : ['admin', 'media_coordinator'];
}

// A small helper: MapTiler's &country= filter, only appended when
// GEOCODING_COUNTRY_CODE is actually set in district.php — leave that
// setting blank to search worldwide instead of restricting to one country.
function geocode_country_param() {
    return (defined('GEOCODING_COUNTRY_CODE') && GEOCODING_COUNTRY_CODE !== '')
        ? '&country=' . rawurlencode(GEOCODING_COUNTRY_CODE)
        : '';
}

// Determines the status colour based on the most recent activity date.
// green <30 days, yellow 30-60, orange 60-90, red >90, grey = nothing done yet.
function status_kleur($laatsteActiviteit) {
    if (!$laatsteActiviteit) return ['kleur' => 'grijs', 'dagen' => null];
    $dagen = floor((time() - strtotime($laatsteActiviteit)) / 86400);
    if ($dagen < 30) $kleur = 'groen';
    elseif ($dagen < 60) $kleur = 'geel';
    elseif ($dagen < 90) $kleur = 'oranje';
    else $kleur = 'rood';
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

    // ---- Locations -----------------------------------------------------

    case 'list': {
        respond(['locaties' => alle_locaties()]);
        break;
    }

    case 'get': {
        $id = $_GET['id'] ?? '';
        $locatie = read_json(locatie_path($id), null);
        if (!$locatie) respond(['error' => 'Location not found.'], 404);
        // Most recent activity first, for the timeline.
        usort($locatie['activiteiten'], fn($a, $b) => strcmp($b['datum'], $a['datum']));
        respond(['locatie' => locatie_met_status($locatie)]);
        break;
    }

    case 'create': {
        $in = json_input();
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'The institution\'s name is required.'], 400);

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
        if (!$locatie) respond(['error' => 'Location not found.'], 404);
        $in = json_input();

        // Only overwrite known, allowed fields; activities and id stay
        // outside this route (those go via activity_add/delete).
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
        // Groups locations by name+postcode+city (case-insensitive, spaces
        // ignored) — exactly what would end up duplicated if the same CSV
        // file were imported more than once.
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
            // Keep the location with the most activities (or, if tied, the
            // oldest one) — that one likely holds the most already-entered
            // history. The rest get removed.
            usort($groep, fn($a, $b) => $b['aantalActiviteiten'] <=> $a['aantalActiviteiten'] ?: $a['createdAt'] <=> $b['createdAt']);
            for ($i = 1; $i < count($groep); $i++) {
                $path = locatie_path($groep[$i]['id']);
                if (file_exists($path)) { unlink($path); $verwijderd++; }
            }
        }
        respond(['verwijderd' => $verwijderd]);
        break;
    }

    // ---- Activities ---------------------------------------------------

    case 'activity_add': {
        $id = $_GET['id'] ?? '';
        $locatie = read_json(locatie_path($id), null);
        if (!$locatie) respond(['error' => 'Location not found.'], 404);
        $in = json_input();
        if (trim($in['type'] ?? '') === '') respond(['error' => 'Choose an activity type.'], 400);

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
        if (!$locatie) respond(['error' => 'Location not found.'], 404);
        $locatie['activiteiten'] = array_values(array_filter(
            $locatie['activiteiten'],
            fn($a) => $a['id'] !== $activiteitId
        ));
        write_json(locatie_path($id), $locatie);
        respond(['locatie' => locatie_met_status($locatie)]);
        break;
    }

    // ---- Bulk import (CSV, already parsed into rows by the browser) -------

    case 'bulk_import': {
        $in = json_input();
        $rijen = $in['rijen'] ?? [];
        $aangemaakt = 0;
        $fouten = [];

        foreach ($rijen as $i => $rij) {
            $naam = trim($rij['naam'] ?? '');
            if ($naam === '') { $fouten[] = "Row " . ($i + 2) . ": name is missing."; continue; }

            // Coordinates: use lat/lng from the CSV if present, otherwise
            // try to geocode automatically based on address/postcode/city
            // (same as for individual locations).
            $lat = isset($rij['lat']) && $rij['lat'] !== '' ? (float)$rij['lat'] : null;
            $lng = isset($rij['lng']) && $rij['lng'] !== '' ? (float)$rij['lng'] : null;
            if ($lat === null && defined('MAPTILER_KEY') && MAPTILER_KEY !== '') {
                $adresVoorGeocode = trim(($rij['adres'] ?? '') . ' ' . ($rij['postcode'] ?? '') . ' ' . ($rij['plaats'] ?? ''));
                if ($adresVoorGeocode !== '') {
                    $geoUrl = 'https://api.maptiler.com/geocoding/' . rawurlencode($adresVoorGeocode) . '.json?key=' . MAPTILER_KEY . geocode_country_param() . '&limit=1';
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

            // Optional: include a first activity right away if the CSV row
            // contains a laatsteActiviteitDatum/type/omschrijving.
            if (!empty($rij['laatsteActiviteitDatum'])) {
                $locatie['activiteiten'][] = [
                    'id' => uuid(),
                    'datum' => $rij['laatsteActiviteitDatum'],
                    'type' => $rij['laatsteActiviteitType'] ?? 'bezoek',
                    'omschrijving' => $rij['laatsteActiviteitOmschrijving'] ?? 'Imported from bulk CSV.',
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

    // ---- Map (GeoJSON for MapLibre) ----------------------------------

    // ---- Geocoding (address -> coordinates, via MapTiler) -----------------
    // Uses the same MAPTILER_KEY as the map. Only works if a key is filled
    // in in district.php; otherwise (or on a hosting-side network error)
    // this simply returns 'gevonden: false' — the user can still enter
    // lat/lng manually, so this must never block anything.
    case 'geocode': {
        $adres = trim(($_GET['adres'] ?? '') . ' ' . ($_GET['postcode'] ?? '') . ' ' . ($_GET['plaats'] ?? ''));
        if ($adres === '' || !defined('MAPTILER_KEY') || MAPTILER_KEY === '') {
            respond(['gevonden' => false]);
        }
        $url = 'https://api.maptiler.com/geocoding/' . rawurlencode($adres) . '.json?key=' . MAPTILER_KEY . geocode_country_param() . '&limit=1';
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

    // ---- Campaigns (category 1, campaign-mail tab) ---------------------

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
        if (trim($in['naam'] ?? '') === '') respond(['error' => 'The campaign name is required.'], 400);
        $type = ($in['type'] ?? '') === 'online' ? 'online' : 'fysiek';
        $id = uuid();
        $campagne = [
            'id' => $id,
            'naam' => trim($in['naam']),
            'type' => $type, // fysiek | online — determines who approves it
            'datum' => $in['datum'] ?? date('Y-m-d'),
            'kanaal' => $in['kanaal'] ?? 'Laposta',
            'omschrijving' => $in['omschrijving'] ?? '',
            'deelnemers' => [],
            // Anyone may create/propose a campaign — the appropriate
            // coordinator (Media for physical, Social Media for online, or
            // IT) approves or rejects it afterwards.
            'status' => 'wacht_op_goedkeuring', // wacht_op_goedkeuring | goedgekeurd | afgewezen
            'goedgekeurdDoor' => null,
            'goedgekeurdOp' => null,
            'opmerkingVoorzitter' => '',
            'createdAt' => time() * 1000,
            'createdBy' => current_user_slug(),
        ];
        write_json(campagne_path($id), $campagne);
        notify_role($type === 'online' ? 'social_media_coordinator' : 'media_coordinator', 'New campaign awaiting approval — ' . $campagne['naam'],
            ($_SESSION['user_name'] ?? current_user_slug()) . ' is proposing the campaign "' . $campagne['naam'] . '" (' . $campagne['kanaal'] . ').',
            'locations.php?tab=campagnes');
        respond(['campagne' => $campagne], 201);
        break;
    }

    case 'campaign_get': {
        $id = $_GET['id'] ?? '';
        $campagne = read_json(campagne_path($id), null);
        if (!$campagne) respond(['error' => 'Campaign not found.'], 404);
        respond(['campagne' => $campagne]);
        break;
    }

    case 'campaign_add_deelnemer': {
        $id = $_GET['id'] ?? '';
        $campagne = read_json(campagne_path($id), null);
        if (!$campagne) respond(['error' => 'Campaign not found.'], 404);
        $in = json_input();
        $campagne['deelnemers'][] = [
            'id' => uuid(),
            'naam' => $in['naam'] ?? '',
            'email' => $in['email'] ?? '',
            'locatieId' => $in['locatieId'] ?? null,
            'reactie' => $in['reactie'] ?? '',           // e.g. 'no response', 'interested', 'appointment made'
            'vervolgactie' => $in['vervolgactie'] ?? '', // e.g. 'order', 'schedule outreach'
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
        if (!$campagne) respond(['error' => 'Campaign not found.'], 404);
        require_role(goedkeurRollenVoorType($campagne['type'] ?? 'fysiek'));
        if (($campagne['status'] ?? '') !== 'wacht_op_goedkeuring') respond(['error' => 'This campaign is no longer awaiting approval.'], 400);
        $campagne['status'] = 'goedgekeurd';
        $campagne['goedgekeurdDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $campagne['goedgekeurdOp'] = time() * 1000;
        write_json(campagne_path($id), $campagne);
        if (!empty($campagne['createdBy'])) {
            notify([$campagne['createdBy']], 'Campaign approved — ' . $campagne['naam'], 'Your proposed campaign "' . $campagne['naam'] . '" has been approved.', 'locations.php?tab=campagnes');
        }
        respond(['campagne' => $campagne]);
        break;
    }

    case 'campaign_afwijzen': {
        $id = $_GET['id'] ?? '';
        $campagne = read_json(campagne_path($id), null);
        if (!$campagne) respond(['error' => 'Campaign not found.'], 404);
        require_role(goedkeurRollenVoorType($campagne['type'] ?? 'fysiek'));
        if (($campagne['status'] ?? '') !== 'wacht_op_goedkeuring') respond(['error' => 'This campaign is no longer awaiting approval.'], 400);
        $in = json_input();
        $campagne['status'] = 'afgewezen';
        $campagne['opmerkingVoorzitter'] = trim($in['opmerking'] ?? '');
        write_json(campagne_path($id), $campagne);
        if (!empty($campagne['createdBy'])) {
            notify([$campagne['createdBy']], 'Campaign rejected — ' . $campagne['naam'], 'Your proposed campaign "' . $campagne['naam'] . '" has been rejected.' . ($campagne['opmerkingVoorzitter'] ? ("\nNote: " . $campagne['opmerkingVoorzitter']) : ''), 'locations.php?tab=campagnes');
        }
        respond(['campagne' => $campagne]);
        break;
    }

    // ================= TARGETS (institutions for media campaigns) =================

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
        if ($naam === '') respond(['error' => 'Name is required.'], 400);
        $id = uuid();
        $target = [
            'id' => $id,
            'naam' => $naam,
            'type' => ($in['type'] ?? '') === 'online' ? 'online' : 'fysiek',
            'omschrijving' => trim($in['omschrijving'] ?? ''),
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
        if (!$target) respond(['error' => 'Target not found.'], 404);
        $in = json_input();
        if (isset($in['naam'])) {
            $naam = trim($in['naam']);
            if ($naam === '') respond(['error' => 'Name is required.'], 400);
            $target['naam'] = $naam;
        }
        if (isset($in['omschrijving'])) $target['omschrijving'] = trim($in['omschrijving']);
        if (isset($in['type'])) $target['type'] = $in['type'] === 'online' ? 'online' : 'fysiek';
        write_json(target_path($id), $target);
        respond(['target' => $target]);
        break;
    }

    case 'target_delete': {
        $id = $_GET['id'] ?? '';
        $target = read_json(target_path($id), null);
        if ($target) {
            $magVerwijderen = heeft_rol(['admin', 'chair']) || $target['createdBy'] === ($_SESSION['user_name'] ?? '');
            if (!$magVerwijderen) respond(['error' => 'You are not permitted to do this.'], 403);
        }
        if (file_exists(target_path($id))) unlink(target_path($id));
        respond(['deleted' => true]);
        break;
    }

    // ================= CAMPAIGN IDEAS (per-idea pipeline overview) =================

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
        if ($titel === '') respond(['error' => 'A title/plan is required.'], 400);
        $type = ($in['type'] ?? '') === 'online' ? 'online' : 'fysiek';
        $id = uuid();
        $idee = [
            'id' => $id,
            'titel' => $titel,
            'type' => $type, // fysiek | online — determines who carries it out
            'targetId' => trim($in['targetId'] ?? '') ?: null,
            'targetNaam' => trim($in['targetNaam'] ?? ''),
            'materialen' => is_array($in['materialen'] ?? null) ? $in['materialen'] : [],
            'deadline' => trim($in['deadline'] ?? '') ?: null,
            'benaderd' => !empty($in['benaderd']),
            'datumRegistratie' => date('Y-m-d'),
            'datumEersteContact' => trim($in['datumEersteContact'] ?? '') ?: null,
            'vervolgacties' => [],
            // Anyone may submit an idea/plan — carrying it out (marking it
            // done) happens via the appropriate coordinator: Media
            // (physical) or Social Media (online).
            'status' => 'open', // open | afgerond
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(idee_path($id), $idee);
        notify_role($type === 'online' ? 'social_media_coordinator' : 'media_coordinator', 'New campaign idea — ' . $titel,
            ($_SESSION['user_name'] ?? current_user_slug()) . ' has registered a campaign idea: ' . $titel,
            'locations.php?tab=campagne_ideeen');
        respond(['idee' => $idee], 201);
        break;
    }

    case 'idee_update': {
        $id = $_GET['id'] ?? '';
        $idee = read_json(idee_path($id), null);
        if (!$idee) respond(['error' => 'Idea not found.'], 404);
        $in = json_input();
        if (isset($in['titel'])) {
            $titel = trim($in['titel']);
            if ($titel === '') respond(['error' => 'A title/plan is required.'], 400);
            $idee['titel'] = $titel;
        }
        if (isset($in['targetId'])) $idee['targetId'] = trim($in['targetId']) ?: null;
        if (isset($in['targetNaam'])) $idee['targetNaam'] = trim($in['targetNaam']);
        if (isset($in['type'])) $idee['type'] = $in['type'] === 'online' ? 'online' : 'fysiek';
        if (isset($in['materialen']) && is_array($in['materialen'])) $idee['materialen'] = $in['materialen'];
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
            // Setting the status to "done" yourself is only allowed for the
            // coordinator who actually carries out this type of idea
            // (Media/Social Media) or IT — editing everything else
            // (materials, follow-up actions, etc.) stays open for everyone.
            if ($in['status'] !== $idee['status'] && !heeft_rol(goedkeurRollenVoorType($idee['type'] ?? 'fysiek'))) {
                respond(['error' => 'Only the Media Coordinator (physical) / Social Media Coordinator (online) or IT can change the status.'], 403);
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
            $magVerwijderen = heeft_rol(['admin', 'chair']) || $idee['createdBy'] === ($_SESSION['user_name'] ?? '');
            if (!$magVerwijderen) respond(['error' => 'Only the creator, the Chairperson, or IT can delete this.'], 403);
            foreach ($idee['materialen'] as $m) {
                $pad = DATA_DIR . '/campagne_ideeen/materialen/' . ($m['pad'] ?? '');
                if (!empty($m['pad']) && file_exists($pad)) unlink($pad);
            }
        }
        if (file_exists(idee_path($id))) unlink(idee_path($id));
        respond(['deleted' => true]);
        break;
    }

    // Material (ad concept, letter, article, correspondence...) upload —
    // multiple per idea are possible, one at a time.
    case 'materiaal_upload': {
        if (empty($_FILES['bestand'])) respond(['error' => 'No file received.'], 400);
        $file = $_FILES['bestand'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload failed.'], 500);
        if ($file['size'] > 15 * 1024 * 1024) respond(['error' => 'File must be no larger than 15 MB.'], 400);

        $toegestaan = [
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt', 'message/rfc822' => 'eml',
        ];
        $mime = mime_content_type($file['tmp_name']);
        $ext = $toegestaan[$mime] ?? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'txt', 'eml', 'msg'], true)) {
            respond(['error' => 'File type not supported.'], 400);
        }

        $naam = veilige_token() . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/campagne_ideeen/materialen/' . $naam)) {
            respond(['error' => 'Saving failed.'], 500);
        }
        respond(['bestandsnaam' => $file['name'], 'pad' => $naam]);
        break;
    }

    case 'materiaal_stream': {
        $bestand = basename($_GET['bestand'] ?? '');
        $pad = DATA_DIR . '/campagne_ideeen/materialen/' . $bestand;
        if ($bestand === '' || !file_exists($pad)) { http_response_code(404); exit; }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: inline; filename="' . basename($_GET['naam'] ?? $bestand) . '"');
        header('Content-Length: ' . filesize($pad));
        readfile($pad);
        exit;
    }

    // ---- Activity feed for the dashboard ----------------------------
    // Only meaningful events (new location, new activity), not every
    // click — as requested.

    case 'feed': {
        $items = [];
        foreach (glob(DATA_DIR . '/locaties/*.json') as $file) {
            $l = read_json($file, null);
            if (!$l) continue;
            $items[] = [
                'tijd' => $l['createdAt'],
                'tekst' => htmlspecialchars($l['createdBy'] ?? 'Someone') . ' added location "' . htmlspecialchars($l['naam']) . '".',
            ];
            foreach ($l['activiteiten'] ?? [] as $a) {
                $items[] = [
                    'tijd' => $a['createdAt'],
                    'tekst' => htmlspecialchars($a['door'] ?? 'Someone') . ' logged "' . htmlspecialchars($a['type']) . '" at ' . htmlspecialchars($l['naam']) . '.',
                ];
            }
        }
        usort($items, fn($a, $b) => $b['tijd'] <=> $a['tijd']);
        respond(['items' => array_slice($items, 0, 20)]);
        break;
    }

    default:
        respond(['error' => 'unknown action'], 400);
}
