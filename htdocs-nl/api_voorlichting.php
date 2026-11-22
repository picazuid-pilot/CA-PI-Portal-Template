<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$coordinatorRollen = ['admin', 'coordinator_voorlichting'];

// Ook zonder login bruikbaar: externe instellingen hebben geen account.
$publiekeActies = ['aanvraag_extern_create'];
if (!in_array($action, $publiekeActies, true)) {
    require_login();
}

function voorlichting_aanvraag_path($id) {
    return DATA_DIR . '/voorlichting/aanvragen/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json';
}
function agenda_path($id) {
    return DATA_DIR . '/agenda/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json';
}
function todo_path($id) {
    return DATA_DIR . '/todos/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) . '.json';
}

// Ruimt afgewezen aanvragen op die langer dan een week geleden zijn
// afgehandeld — zelfde "geen cron nodig"-aanpak als bij Drukwerk.
function ruim_oude_afwijzingen_op_voorlichting() {
    $grens = (time() - 7 * 24 * 60 * 60) * 1000;
    foreach (glob(DATA_DIR . '/voorlichting/aanvragen/*.json') as $file) {
        $a = read_json($file, null);
        if ($a && $a['status'] === 'afgewezen' && ($a['behandeldOp'] ?? null) !== null && $a['behandeldOp'] < $grens) {
            unlink($file);
        }
    }
}

switch ($action) {

    // ================= AANVRAGEN =================

    case 'aanvraag_extern_create': {
        $in = json_input();
        $extSleutel = 'x:' . sha1(klant_ip());
        if (!rate_limit_toegestaan($extSleutel, 8, 3600)) respond(['error' => 'Er zijn te veel aanvragen vanaf dit adres verstuurd. Probeer het later opnieuw.'], 429);
        rate_limit_registreer($extSleutel);
        if (trim($in['website'] ?? '') !== '') respond(['ok' => true]); // honeypot

        $instellingNaam = trim($in['instellingNaam'] ?? '');
        $contactpersoon = trim($in['contactpersoon'] ?? '');
        $email = strtolower(trim($in['email'] ?? ''));
        if ($instellingNaam === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Vul minstens de naam van de instantie en een geldig e-mailadres in.'], 400);
        }

        $id = uuid();
        $aanvraag = [
            'id' => $id,
            'extern' => true,
            'aanvragerId' => null,
            'aanvragerNaam' => null,
            'instellingNaam' => $instellingNaam,
            'adres' => trim($in['adres'] ?? ''),
            'contactpersoon' => $contactpersoon,
            'contactEmail' => $email,
            'contactTelefoon' => trim($in['telefoon'] ?? ''),
            'gewensteDatum' => trim($in['gewensteDatum'] ?? ''),
            'gewensteTijd' => trim($in['gewensteTijd'] ?? ''),
            'aantalDeelnemers' => trim($in['aantalDeelnemers'] ?? ''),
            'toelichting' => trim($in['toelichting'] ?? ''),
            'status' => 'open',
            'oproepVerstuurd' => false,
            'aanmeldingen' => [],
            'definitief' => null,
            'aangemaaktOp' => time() * 1000,
            'behandeldOp' => null,
            'behandeldDoor' => null,
        ];
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        notify_role(
            'coordinator_voorlichting',
            'Nieuwe voorlichtingsaanvraag — ' . $instellingNaam,
            'Externe instantie ' . $instellingNaam . ($contactpersoon ? (' (contactpersoon: ' . $contactpersoon . ')') : '') . ' vraagt voorlichting aan.' .
                "\n\nE-mail: " . $email . ($aanvraag['contactTelefoon'] ? ("\nTelefoon: " . $aanvraag['contactTelefoon']) : '') .
                ($aanvraag['gewensteDatum'] ? ("\nGewenste datum: " . $aanvraag['gewensteDatum'] . ($aanvraag['gewensteTijd'] ? (' om ' . $aanvraag['gewensteTijd']) : '')) : '') .
                ($aanvraag['aantalDeelnemers'] ? ("\nAantal deelnemers: " . $aanvraag['aantalDeelnemers']) : '') .
                ($aanvraag['toelichting'] ? ("\nToelichting: " . $aanvraag['toelichting']) : ''),
            'voorlichting.php?tab=voorlichting'
        );

        respond(['ok' => true], 201);
        break;
    }

    case 'aanvraag_create': {
        // Intern: een PI-lid vraagt voorlichting aan namens een instantie
        // (bijv. na een bezoek waarbij interesse bleek — zie ook PI Locaties).
        $in = json_input();
        $instellingNaam = trim($in['instellingNaam'] ?? '');
        if ($instellingNaam === '') respond(['error' => 'Naam van de instantie is verplicht.'], 400);

        $id = uuid();
        $aanvraag = [
            'id' => $id,
            'extern' => false,
            'aanvragerId' => $_SESSION['user_id'],
            'aanvragerNaam' => $_SESSION['user_name'] ?? $_SESSION['user_id'],
            'instellingNaam' => $instellingNaam,
            'adres' => trim($in['adres'] ?? ''),
            'contactpersoon' => trim($in['contactpersoon'] ?? ''),
            'contactEmail' => trim($in['contactEmail'] ?? ''),
            'contactTelefoon' => trim($in['contactTelefoon'] ?? ''),
            'gewensteDatum' => trim($in['gewensteDatum'] ?? ''),
            'gewensteTijd' => trim($in['gewensteTijd'] ?? ''),
            'aantalDeelnemers' => trim($in['aantalDeelnemers'] ?? ''),
            'toelichting' => trim($in['toelichting'] ?? ''),
            'status' => 'open',
            'oproepVerstuurd' => false,
            'aanmeldingen' => [],
            'definitief' => null,
            'aangemaaktOp' => time() * 1000,
            'behandeldOp' => null,
            'behandeldDoor' => null,
        ];
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        notify_role(
            'coordinator_voorlichting',
            'Nieuwe voorlichtingsaanvraag — ' . $instellingNaam,
            $aanvraag['aanvragerNaam'] . ' vraagt voorlichting aan voor ' . $instellingNaam . '.' .
                ($aanvraag['gewensteDatum'] ? ("\nGewenste datum: " . $aanvraag['gewensteDatum'] . ($aanvraag['gewensteTijd'] ? (' om ' . $aanvraag['gewensteTijd']) : '')) : '') .
                ($aanvraag['toelichting'] ? ("\nToelichting: " . $aanvraag['toelichting']) : ''),
            'voorlichting.php?tab=voorlichting'
        );

        respond(['aanvraag' => $aanvraag], 201);
        break;
    }

    case 'aanvraag_list': {
        ruim_oude_afwijzingen_op_voorlichting();
        $out = [];
        foreach (glob(DATA_DIR . '/voorlichting/aanvragen/*.json') as $file) {
            $a = read_json($file, null);
            if ($a) $out[] = $a;
        }
        usort($out, fn($a, $b) => $b['aangemaaktOp'] <=> $a['aangemaaktOp']);
        respond(['aanvragen' => $out]);
        break;
    }

    case 'aanvraag_afwijzen': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);
        $in = json_input();

        $aanvraag['status'] = 'afgewezen';
        $aanvraag['behandeldOp'] = time() * 1000;
        $aanvraag['behandeldDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $aanvraag['opmerkingCoordinator'] = $in['opmerking'] ?? '';
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        $tekst = 'Uw/je voorlichtingsaanvraag voor ' . $aanvraag['instellingNaam'] . ' is helaas afgewezen.' .
            ($aanvraag['opmerkingCoordinator'] ? ("\nOpmerking: " . $aanvraag['opmerkingCoordinator']) : '');
        if ($aanvraag['extern']) {
            if (!empty($aanvraag['contactEmail'])) @smtp_send($aanvraag['contactEmail'], 'Voorlichtingsaanvraag afgewezen', $tekst);
        } elseif ($aanvraag['aanvragerId']) {
            notify([$aanvraag['aanvragerId']], 'Voorlichtingsaanvraag afgewezen', $tekst, 'voorlichting.php?tab=voorlichting');
        }

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_delete': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $path = voorlichting_aanvraag_path($id);
        if (file_exists($path)) unlink($path);
        // Gekoppelde agendapunten horen niet als wees te blijven staan.
        foreach (glob(DATA_DIR . '/agenda/*.json') as $file) {
            $a = read_json($file, null);
            if ($a && ($a['voorlichtingId'] ?? null) === $id) unlink($file);
        }
        respond(['deleted' => true]);
        break;
    }

    // ================= OPROEP AAN LEDEN =================

    case 'oproep_versturen': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);

        $aanvraag['oproepVerstuurd'] = true;
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        notify_alle_leden(
            'Wie geeft mee voorlichting bij ' . $aanvraag['instellingNaam'] . '?',
            'Er is een voorlichting te plannen bij ' . $aanvraag['instellingNaam'] . '.' .
                ($aanvraag['gewensteDatum'] ? ("\nGewenste datum: " . $aanvraag['gewensteDatum'] . (!empty($aanvraag['gewensteTijd']) ? (' om ' . $aanvraag['gewensteTijd']) : '')) : '') .
                ($aanvraag['toelichting'] ? ("\nToelichting: " . $aanvraag['toelichting']) : '') .
                "\n\nMeld je aan in het portaal als je mee wilt doen.",
            'voorlichting.php?tab=voorlichting',
            $_SESSION['user_id']
        );

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanmelden': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);

        foreach ($aanvraag['aanmeldingen'] as $a) {
            if ($a['userId'] === $_SESSION['user_id']) respond(['aanvraag' => $aanvraag]); // al aangemeld
        }
        $aanvraag['aanmeldingen'][] = [
            'userId' => $_SESSION['user_id'],
            'naam' => $_SESSION['user_name'] ?? $_SESSION['user_id'],
            'aangemeldOp' => time() * 1000,
        ];
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        notify_role(
            'coordinator_voorlichting',
            'Aanmelding voor voorlichting',
            ($_SESSION['user_name'] ?? $_SESSION['user_id']) . ' heeft zich aangemeld voor de voorlichting bij ' . $aanvraag['instellingNaam'] . '.',
            'voorlichting.php?tab=voorlichting'
        );

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'afmelden': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);
        $aanvraag['aanmeldingen'] = array_values(array_filter($aanvraag['aanmeldingen'], fn($a) => $a['userId'] !== $_SESSION['user_id']));
        write_json(voorlichting_aanvraag_path($id), $aanvraag);
        respond(['aanvraag' => $aanvraag]);
        break;
    }

    // ================= DEFINITIEF INPLANNEN =================

    case 'aanvraag_inplannen': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Aanvraag niet gevonden.'], 404);
        $in = json_input();

        $datum = trim($in['datum'] ?? '');
        $tijd = trim($in['tijd'] ?? '');
        if ($datum === '') respond(['error' => 'Kies een definitieve datum.'], 400);

        $leden = is_array($in['leden'] ?? null) ? $in['leden'] : [];
        $leden = array_values(array_filter(array_map(function ($l) {
            return ['userId' => $l['userId'] ?? null, 'naam' => trim($l['naam'] ?? '')];
        }, $leden), fn($l) => $l['naam'] !== ''));

        $isEersteKeerInplannen = $aanvraag['status'] !== 'ingepland';
        $vorigeLedenIds = array_filter(array_column($aanvraag['definitief']['leden'] ?? [], 'userId'));

        if (isset($in['instellingNaam'])) $aanvraag['instellingNaam'] = trim($in['instellingNaam']);
        if (isset($in['adres'])) $aanvraag['adres'] = trim($in['adres']);
        if (isset($in['contactpersoon'])) $aanvraag['contactpersoon'] = trim($in['contactpersoon']);
        if (isset($in['contactEmail'])) $aanvraag['contactEmail'] = trim($in['contactEmail']);
        if (isset($in['contactTelefoon'])) $aanvraag['contactTelefoon'] = trim($in['contactTelefoon']);

        $aanvraag['definitief'] = [
            'datum' => $datum,
            'tijd' => $tijd,
            'duurMinuten' => (int)($in['duurMinuten'] ?? 60),
            'leden' => $leden,
            'opmerking' => $in['opmerking'] ?? '',
        ];
        $aanvraag['status'] = 'ingepland';
        $aanvraag['behandeldOp'] = time() * 1000;
        $aanvraag['behandeldDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        // Bestaande agendapunten van deze voorlichting eerst opruimen — dit
        // kan ook een WIJZIGING van een al ingeplande voorlichting zijn, en
        // dan willen we geen dubbele agendapunten laten ontstaan.
        foreach (glob(DATA_DIR . '/agenda/*.json') as $file) {
            $bestaand = read_json($file, null);
            if ($bestaand && ($bestaand['voorlichtingId'] ?? null) === $id) unlink($file);
        }

        $agendaId = uuid();
        write_json(agenda_path($agendaId), [
            'id' => $agendaId,
            'titel' => 'Voorlichting — ' . $aanvraag['instellingNaam'],
            'type' => 'voorlichting',
            'datum' => $datum,
            'tijd' => $tijd,
            'locatie' => ($aanvraag['adres'] ?? '') !== '' ? $aanvraag['adres'] : $aanvraag['instellingNaam'],
            'omschrijving' => $aanvraag['toelichting'] ?? '',
            'voorlichtingId' => $id,
            'betrokkenen' => $leden,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ]);

        $tijdTekst = $tijd ? ($datum . ' om ' . $tijd) : $datum;

        // De "ingepland"-melding aan de aanvrager gaat alleen bij de EERSTE
        // keer inplannen — anders krijgt de instantie bij elke kleine
        // wijziging (bijv. adres corrigeren) opnieuw dezelfde e-mail.
        if ($isEersteKeerInplannen) {
            $instantieTekst = "Uw voorlichtingsaanvraag bij " . DISTRICT_NAME . " is ingepland op $tijdTekst." .
                (!empty($leden) ? ("\nAanwezig namens ons: " . implode(', ', array_column($leden, 'naam'))) : '');
            if ($aanvraag['extern'] && !empty($aanvraag['contactEmail'])) {
                @smtp_send($aanvraag['contactEmail'], 'Voorlichting ingepland — ' . $tijdTekst, $instantieTekst);
            } elseif (!$aanvraag['extern'] && $aanvraag['aanvragerId']) {
                notify([$aanvraag['aanvragerId']], 'Voorlichting ingepland', $instantieTekst, 'voorlichting.php?tab=agenda');
            }
        }

        // Leden die al eerder waren ingepland hoeven niet opnieuw een
        // melding te krijgen — alleen de nieuw toegevoegde leden.
        $nieuweLedenIds = array_filter(array_diff(array_column($leden, 'userId') ?: [], $vorigeLedenIds));
        if (!empty($nieuweLedenIds)) {
            notify(
                $nieuweLedenIds,
                'Je staat ingepland voor een voorlichting',
                'Je bent ingepland voor de voorlichting bij ' . $aanvraag['instellingNaam'] . ' op ' . $tijdTekst . '.' .
                    ($aanvraag['contactpersoon'] ? ("\nContactpersoon: " . $aanvraag['contactpersoon']) : '') .
                    ($aanvraag['contactTelefoon'] ? ("\nTelefoon: " . $aanvraag['contactTelefoon']) : ''),
                'voorlichting.php?tab=agenda'
            );
        }

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    // ================= GEZAMENLIJKE AGENDA =================

    case 'agenda_list': {
        $out = [];
        $bekendeVoorlichtingIds = [];
        foreach (glob(DATA_DIR . '/agenda/*.json') as $file) {
            $a = read_json($file, null);
            if (!$a) continue;
            $out[] = $a;
            if (!empty($a['voorlichtingId'])) $bekendeVoorlichtingIds[] = $a['voorlichtingId'];
        }

        // Zelfherstellend: als een voorlichting al is ingepland maar er (om
        // wat voor reden dan ook, bijv. oudere data) geen agendapunt voor
        // bestaat, alsnog aanmaken — zo verdwijnt een ingeplande voorlichting
        // nooit stilzwijgend uit de agenda.
        foreach (glob(DATA_DIR . '/voorlichting/aanvragen/*.json') as $file) {
            $aanvraag = read_json($file, null);
            if (!$aanvraag || $aanvraag['status'] !== 'ingepland' || empty($aanvraag['definitief'])) continue;
            if (in_array($aanvraag['id'], $bekendeVoorlichtingIds, true)) continue;

            $d = $aanvraag['definitief'];
            $agendaId = uuid();
            $nieuw = [
                'id' => $agendaId,
                'titel' => 'Voorlichting — ' . $aanvraag['instellingNaam'],
                'type' => 'voorlichting',
                'datum' => $d['datum'] ?? '',
                'tijd' => $d['tijd'] ?? '',
                'locatie' => ($aanvraag['adres'] ?? '') !== '' ? $aanvraag['adres'] : $aanvraag['instellingNaam'],
                'omschrijving' => $aanvraag['toelichting'] ?? '',
                'voorlichtingId' => $aanvraag['id'],
                'betrokkenen' => $d['leden'] ?? [],
                'createdBy' => 'systeem (automatisch hersteld)',
                'createdAt' => time() * 1000,
            ];
            if ($nieuw['datum'] === '') continue; // geen bruikbare datum, niet toevoegen
            write_json(agenda_path($agendaId), $nieuw);
            $out[] = $nieuw;
        }

        usort($out, fn($a, $b) => strcmp(($a['datum'] ?? '') . ($a['tijd'] ?? ''), ($b['datum'] ?? '') . ($b['tijd'] ?? '')));
        respond(['agenda' => $out]);
        break;
    }

    case 'agenda_create': {
        $in = json_input();
        if (trim($in['titel'] ?? '') === '' || trim($in['datum'] ?? '') === '') {
            respond(['error' => 'Titel en datum zijn verplicht.'], 400);
        }
        $type = in_array($in['type'] ?? '', ['evenement', 'district_vergadering', 'area_vergadering', 'overig'], true) ? $in['type'] : 'overig';
        // District- en Area-vergaderingen zijn bewust alleen aan te maken
        // door Secretaris, Voorzitter of IT — andere agendapunten mag elk lid.
        if (in_array($type, ['district_vergadering', 'area_vergadering'], true) && !heeft_rol(['admin', 'secretaris', 'voorzitter'])) {
            respond(['error' => 'Alleen Secretaris, Voorzitter of IT kan een District-/Area-vergadering aanmaken.'], 403);
        }
        $id = uuid();
        $item = [
            'id' => $id,
            'titel' => trim($in['titel']),
            'type' => $type,
            'datum' => trim($in['datum']),
            'tijd' => trim($in['tijd'] ?? ''),
            'locatie' => trim($in['locatie'] ?? ''),
            'omschrijving' => trim($in['omschrijving'] ?? ''),
            'voorlichtingId' => null,
            'betrokkenen' => [],
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(agenda_path($id), $item);
        respond(['item' => $item], 201);
        break;
    }

    case 'agenda_update': {
        $id = $_GET['id'] ?? '';
        $item = read_json(agenda_path($id), null);
        if (!$item) respond(['error' => 'Agendapunt niet gevonden.'], 404);
        // Zelfde rechten als verwijderen: IT/coördinator, of de eigen maker.
        // Agendapunten die bij een voorlichtingsaanvraag horen (voorlichtingId
        // gezet) worden bewust NIET hier bewerkt — dat gaat via "Wijzig
        // planning" bij de aanvraag zelf, zodat beide altijd synchroon blijven.
        if (!empty($item['voorlichtingId'])) {
            respond(['error' => 'Dit agendapunt hoort bij een voorlichtingsaanvraag — wijzig het via "Wijzig planning" bij die aanvraag.'], 400);
        }
        $magBewerken = heeft_rol($coordinatorRollen) || $item['createdBy'] === ($_SESSION['user_name'] ?? '');
        if (!$magBewerken) respond(['error' => 'Geen toestemming.'], 403);

        $in = json_input();
        if (trim($in['titel'] ?? '') === '' || trim($in['datum'] ?? '') === '') {
            respond(['error' => 'Titel en datum zijn verplicht.'], 400);
        }
        $type = in_array($in['type'] ?? '', ['evenement', 'district_vergadering', 'area_vergadering', 'overig', 'voorlichting'], true) ? $in['type'] : 'overig';
        if (in_array($type, ['district_vergadering', 'area_vergadering'], true) && !heeft_rol(['admin', 'secretaris', 'voorzitter'])) {
            respond(['error' => 'Alleen Secretaris, Voorzitter of IT kan een District-/Area-vergadering aanmaken.'], 403);
        }
        $item['titel'] = trim($in['titel']);
        $item['type'] = $type;
        $item['datum'] = trim($in['datum']);
        $item['tijd'] = trim($in['tijd'] ?? '');
        $item['locatie'] = trim($in['locatie'] ?? '');
        $item['omschrijving'] = trim($in['omschrijving'] ?? '');
        write_json(agenda_path($id), $item);
        respond(['item' => $item]);
        break;
    }

    case 'agenda_delete': {
        $id = $_GET['id'] ?? '';
        $item = read_json(agenda_path($id), null);
        if (!$item) respond(['deleted' => true]);
        $magVerwijderen = heeft_rol($coordinatorRollen) || $item['createdBy'] === ($_SESSION['user_name'] ?? '');
        if (!$magVerwijderen) respond(['error' => 'Geen toestemming.'], 403);
        unlink(agenda_path($id));
        respond(['deleted' => true]);
        break;
    }

    // ================= AGENDA-ABONNEMENT (iCal-feed) =================
    // Agenda-apps (Thunderbird, Google, Apple, Outlook) kunnen niet inloggen
    // met een portaalwachtwoord. Daarom krijgt elk lid een eigen, niet te raden
    // link (token) naar agenda.ics.php. Wie de link kent kan de agenda LEZEN;
    // met "nieuw" wordt de oude link meteen ongeldig.

    case 'agenda_abonnement': {
        $in = json_input();
        $tokensPad = DATA_DIR . '/agenda_tokens.json';
        $tokens = read_json($tokensPad, []);
        $uid = $_SESSION['user_id'];
        $bestaand = null;
        $idx = null;
        foreach ($tokens as $i => $t) {
            if (($t['userId'] ?? '') === $uid) { $bestaand = $t; $idx = $i; break; }
        }
        if (!$bestaand || !empty($in['nieuw'])) {
            $bestaand = ['userId' => $uid, 'token' => veilige_token(), 'createdAt' => time() * 1000];
            if ($idx !== null) $tokens[$idx] = $bestaand; else $tokens[] = $bestaand;
            write_json($tokensPad, array_values($tokens));
        }
        $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $basis = $schema . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $url = $basis . '/agenda.ics.php?token=' . $bestaand['token'];
        respond(['url' => $url, 'webcalUrl' => preg_replace('#^https?://#', 'webcal://', $url)]);
        break;
    }

    // ================= TO-DO LIJST =================
    // Bewust laagdrempelig: iedereen kan een taak aanmaken, toewijzen (of
    // open laten), en afronden — geen goedkeuringsstap, gewoon samenwerken.

    case 'todo_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/todos/*.json') as $file) {
            $t = read_json($file, null);
            if ($t) $out[] = $t;
        }
        // Open taken eerst (op deadline, geen-deadline achteraan), afgeronde taken onderaan.
        usort($out, function ($a, $b) {
            if ($a['status'] !== $b['status']) return $a['status'] === 'afgerond' ? 1 : -1;
            $da = $a['deadline'] ?: '9999-99-99';
            $db = $b['deadline'] ?: '9999-99-99';
            return strcmp($da, $db);
        });
        respond(['todos' => $out]);
        break;
    }

    case 'todo_create': {
        $in = json_input();
        $titel = trim($in['titel'] ?? '');
        if ($titel === '') respond(['error' => 'Titel is verplicht.'], 400);
        $id = uuid();
        $todo = [
            'id' => $id,
            'titel' => $titel,
            'omschrijving' => trim($in['omschrijving'] ?? ''),
            'toegewezenAan' => is_array($in['toegewezenAan'] ?? null) ? $in['toegewezenAan'] : [],
            'deadline' => trim($in['deadline'] ?? '') ?: null,
            'status' => 'open',
            'afgerondDoor' => null,
            'afgerondOp' => null,
            'createdBy' => $_SESSION['user_name'] ?? current_user_slug(),
            'createdAt' => time() * 1000,
        ];
        write_json(todo_path($id), $todo);
        if (!empty($todo['toegewezenAan'])) {
            $ids = array_filter(array_column($todo['toegewezenAan'], 'userId'));
            if (!empty($ids)) {
                notify($ids, 'Nieuwe taak toegewezen — ' . $titel,
                    ($_SESSION['user_name'] ?? current_user_slug()) . ' heeft je een taak toegewezen: ' . $titel . ($todo['deadline'] ? ("\nDeadline: " . $todo['deadline']) : ''),
                    'voorlichting.php?tab=todo');
            }
        }
        respond(['todo' => $todo], 201);
        break;
    }

    case 'todo_update': {
        $id = $_GET['id'] ?? '';
        $todo = read_json(todo_path($id), null);
        if (!$todo) respond(['error' => 'Taak niet gevonden.'], 404);
        $in = json_input();
        if (isset($in['titel'])) {
            $titel = trim($in['titel']);
            if ($titel === '') respond(['error' => 'Titel is verplicht.'], 400);
            $todo['titel'] = $titel;
        }
        if (isset($in['omschrijving'])) $todo['omschrijving'] = trim($in['omschrijving']);
        if (isset($in['toegewezenAan'])) $todo['toegewezenAan'] = is_array($in['toegewezenAan']) ? $in['toegewezenAan'] : [];
        if (isset($in['deadline'])) $todo['deadline'] = trim($in['deadline']) ?: null;
        write_json(todo_path($id), $todo);
        respond(['todo' => $todo]);
        break;
    }

    case 'todo_toggle': {
        $id = $_GET['id'] ?? '';
        $todo = read_json(todo_path($id), null);
        if (!$todo) respond(['error' => 'Taak niet gevonden.'], 404);
        if ($todo['status'] === 'open') {
            $todo['status'] = 'afgerond';
            $todo['afgerondDoor'] = $_SESSION['user_name'] ?? current_user_slug();
            $todo['afgerondOp'] = time() * 1000;
        } else {
            $todo['status'] = 'open';
            $todo['afgerondDoor'] = null;
            $todo['afgerondOp'] = null;
        }
        write_json(todo_path($id), $todo);
        respond(['todo' => $todo]);
        break;
    }

    case 'todo_delete': {
        $id = $_GET['id'] ?? '';
        $todo = read_json(todo_path($id), null);
        if (!$todo) respond(['deleted' => true]);
        $magVerwijderen = heeft_rol(['admin']) || $todo['createdBy'] === ($_SESSION['user_name'] ?? '');
        if (!$magVerwijderen) respond(['error' => 'Alleen de maker of IT kan deze taak verwijderen.'], 403);
        unlink(todo_path($id));
        respond(['deleted' => true]);
        break;
    }

    default:
        respond(['error' => 'onbekende actie'], 400);
}
