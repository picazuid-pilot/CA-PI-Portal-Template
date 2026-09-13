<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$coordinatorRollen = ['admin', 'coordinator_outreach'];

// Also usable without login: external institutions have no account.
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

// Cleans up rejected requests that were handled more than a week ago —
// same "no cron needed" approach as Print.
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

    // ================= REQUESTS =================

    case 'aanvraag_extern_create': {
        $in = json_input();
        if (trim($in['website'] ?? '') !== '') respond(['ok' => true]); // honeypot

        $instellingNaam = trim($in['instellingNaam'] ?? '');
        $contactpersoon = trim($in['contactpersoon'] ?? '');
        $email = strtolower(trim($in['email'] ?? ''));
        if ($instellingNaam === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Enter at least the institution name and a valid email address.'], 400);
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
            'coordinator_outreach',
            'New outreach request — ' . $instellingNaam,
            'External institution ' . $instellingNaam . ($contactpersoon ? (' (contact: ' . $contactpersoon . ')') : '') . ' is requesting an outreach presentation.' .
                "\n\nEmail: " . $email . ($aanvraag['contactTelefoon'] ? ("\nPhone: " . $aanvraag['contactTelefoon']) : '') .
                ($aanvraag['gewensteDatum'] ? ("\nPreferred date: " . $aanvraag['gewensteDatum'] . ($aanvraag['gewensteTijd'] ? (' at ' . $aanvraag['gewensteTijd']) : '')) : '') .
                ($aanvraag['aantalDeelnemers'] ? ("\nNumber of attendees: " . $aanvraag['aantalDeelnemers']) : '') .
                ($aanvraag['toelichting'] ? ("\nNote: " . $aanvraag['toelichting']) : ''),
            'outreach.php?tab=voorlichting'
        );

        respond(['ok' => true], 201);
        break;
    }

    case 'aanvraag_create': {
        // Internal: a PI member requests an outreach presentation on behalf
        // of an institution (e.g. after a visit that revealed interest —
        // see also PI Locations).
        $in = json_input();
        $instellingNaam = trim($in['instellingNaam'] ?? '');
        if ($instellingNaam === '') respond(['error' => 'The institution name is required.'], 400);

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
            'coordinator_outreach',
            'New outreach request — ' . $instellingNaam,
            $aanvraag['aanvragerNaam'] . ' is requesting an outreach presentation for ' . $instellingNaam . '.' .
                ($aanvraag['gewensteDatum'] ? ("\nPreferred date: " . $aanvraag['gewensteDatum'] . ($aanvraag['gewensteTijd'] ? (' at ' . $aanvraag['gewensteTijd']) : '')) : '') .
                ($aanvraag['toelichting'] ? ("\nNote: " . $aanvraag['toelichting']) : ''),
            'outreach.php?tab=voorlichting'
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
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);
        $in = json_input();

        $aanvraag['status'] = 'afgewezen';
        $aanvraag['behandeldOp'] = time() * 1000;
        $aanvraag['behandeldDoor'] = $_SESSION['user_name'] ?? current_user_slug();
        $aanvraag['opmerkingCoordinator'] = $in['opmerking'] ?? '';
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        $tekst = 'Your outreach request for ' . $aanvraag['instellingNaam'] . ' has unfortunately been declined.' .
            ($aanvraag['opmerkingCoordinator'] ? ("\nNote: " . $aanvraag['opmerkingCoordinator']) : '');
        if ($aanvraag['extern']) {
            if (!empty($aanvraag['contactEmail'])) @smtp_send($aanvraag['contactEmail'], 'Outreach request declined', $tekst);
        } elseif ($aanvraag['aanvragerId']) {
            notify([$aanvraag['aanvragerId']], 'Outreach request declined', $tekst, 'outreach.php?tab=voorlichting');
        }

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanvraag_delete': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $path = voorlichting_aanvraag_path($id);
        if (file_exists($path)) unlink($path);
        // Linked agenda items shouldn't stay behind as orphans.
        foreach (glob(DATA_DIR . '/agenda/*.json') as $file) {
            $a = read_json($file, null);
            if ($a && ($a['voorlichtingId'] ?? null) === $id) unlink($file);
        }
        respond(['deleted' => true]);
        break;
    }

    // ================= CALL-OUT TO MEMBERS =================

    case 'oproep_versturen': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);

        $aanvraag['oproepVerstuurd'] = true;
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        notify_alle_leden(
            'Who wants to co-present at ' . $aanvraag['instellingNaam'] . '?',
            'An outreach presentation needs to be scheduled at ' . $aanvraag['instellingNaam'] . '.' .
                ($aanvraag['gewensteDatum'] ? ("\nPreferred date: " . $aanvraag['gewensteDatum'] . (!empty($aanvraag['gewensteTijd']) ? (' at ' . $aanvraag['gewensteTijd']) : '')) : '') .
                ($aanvraag['toelichting'] ? ("\nNote: " . $aanvraag['toelichting']) : '') .
                "\n\nSign up in the portal if you'd like to take part.",
            'outreach.php?tab=voorlichting',
            $_SESSION['user_id']
        );

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'aanmelden': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);

        foreach ($aanvraag['aanmeldingen'] as $a) {
            if ($a['userId'] === $_SESSION['user_id']) respond(['aanvraag' => $aanvraag]); // already signed up
        }
        $aanvraag['aanmeldingen'][] = [
            'userId' => $_SESSION['user_id'],
            'naam' => $_SESSION['user_name'] ?? $_SESSION['user_id'],
            'aangemeldOp' => time() * 1000,
        ];
        write_json(voorlichting_aanvraag_path($id), $aanvraag);

        notify_role(
            'coordinator_outreach',
            'Sign-up for outreach presentation',
            ($_SESSION['user_name'] ?? $_SESSION['user_id']) . ' has signed up for the outreach presentation at ' . $aanvraag['instellingNaam'] . '.',
            'outreach.php?tab=voorlichting'
        );

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    case 'afmelden': {
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);
        $aanvraag['aanmeldingen'] = array_values(array_filter($aanvraag['aanmeldingen'], fn($a) => $a['userId'] !== $_SESSION['user_id']));
        write_json(voorlichting_aanvraag_path($id), $aanvraag);
        respond(['aanvraag' => $aanvraag]);
        break;
    }

    // ================= FINAL SCHEDULING =================

    case 'aanvraag_inplannen': {
        require_role($coordinatorRollen);
        $id = $_GET['id'] ?? '';
        $aanvraag = read_json(voorlichting_aanvraag_path($id), null);
        if (!$aanvraag) respond(['error' => 'Request not found.'], 404);
        $in = json_input();

        $datum = trim($in['datum'] ?? '');
        $tijd = trim($in['tijd'] ?? '');
        if ($datum === '') respond(['error' => 'Choose a final date.'], 400);

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

        // Clean up any existing agenda items for this outreach presentation
        // first — this may also be a CHANGE to an already-scheduled
        // presentation, and we don't want to end up with duplicate agenda items.
        foreach (glob(DATA_DIR . '/agenda/*.json') as $file) {
            $bestaand = read_json($file, null);
            if ($bestaand && ($bestaand['voorlichtingId'] ?? null) === $id) unlink($file);
        }

        $agendaId = uuid();
        write_json(agenda_path($agendaId), [
            'id' => $agendaId,
            'titel' => 'Outreach — ' . $aanvraag['instellingNaam'],
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

        $tijdTekst = $tijd ? ($datum . ' at ' . $tijd) : $datum;

        // The "scheduled" notification to the requester only goes out the
        // FIRST time it's scheduled — otherwise the institution gets the
        // same email again for every small change (e.g. correcting the address).
        if ($isEersteKeerInplannen) {
            $instantieTekst = "Your outreach request with " . DISTRICT_NAME . " has been scheduled for $tijdTekst." .
                (!empty($leden) ? ("\nAttending on our behalf: " . implode(', ', array_column($leden, 'naam'))) : '');
            if ($aanvraag['extern'] && !empty($aanvraag['contactEmail'])) {
                @smtp_send($aanvraag['contactEmail'], 'Outreach presentation scheduled — ' . $tijdTekst, $instantieTekst);
            } elseif (!$aanvraag['extern'] && $aanvraag['aanvragerId']) {
                notify([$aanvraag['aanvragerId']], 'Outreach presentation scheduled', $instantieTekst, 'outreach.php?tab=agenda');
            }
        }

        // Members who were already scheduled don't need to be notified
        // again — only the newly added members.
        $nieuweLedenIds = array_filter(array_diff(array_column($leden, 'userId') ?: [], $vorigeLedenIds));
        if (!empty($nieuweLedenIds)) {
            notify(
                $nieuweLedenIds,
                'You\'re scheduled for an outreach presentation',
                'You\'re scheduled for the outreach presentation at ' . $aanvraag['instellingNaam'] . ' on ' . $tijdTekst . '.' .
                    ($aanvraag['contactpersoon'] ? ("\nContact: " . $aanvraag['contactpersoon']) : '') .
                    ($aanvraag['contactTelefoon'] ? ("\nPhone: " . $aanvraag['contactTelefoon']) : ''),
                'outreach.php?tab=agenda'
            );
        }

        respond(['aanvraag' => $aanvraag]);
        break;
    }

    // ================= SHARED CALENDAR =================

    case 'agenda_list': {
        $out = [];
        $bekendeVoorlichtingIds = [];
        foreach (glob(DATA_DIR . '/agenda/*.json') as $file) {
            $a = read_json($file, null);
            if (!$a) continue;
            $out[] = $a;
            if (!empty($a['voorlichtingId'])) $bekendeVoorlichtingIds[] = $a['voorlichtingId'];
        }

        // Self-healing: if an outreach presentation is already scheduled but
        // (for whatever reason, e.g. older data) has no agenda item, create
        // one anyway — this way a scheduled presentation never silently
        // disappears from the calendar.
        foreach (glob(DATA_DIR . '/voorlichting/aanvragen/*.json') as $file) {
            $aanvraag = read_json($file, null);
            if (!$aanvraag || $aanvraag['status'] !== 'ingepland' || empty($aanvraag['definitief'])) continue;
            if (in_array($aanvraag['id'], $bekendeVoorlichtingIds, true)) continue;

            $d = $aanvraag['definitief'];
            $agendaId = uuid();
            $nieuw = [
                'id' => $agendaId,
                'titel' => 'Outreach — ' . $aanvraag['instellingNaam'],
                'type' => 'voorlichting',
                'datum' => $d['datum'] ?? '',
                'tijd' => $d['tijd'] ?? '',
                'locatie' => ($aanvraag['adres'] ?? '') !== '' ? $aanvraag['adres'] : $aanvraag['instellingNaam'],
                'omschrijving' => $aanvraag['toelichting'] ?? '',
                'voorlichtingId' => $aanvraag['id'],
                'betrokkenen' => $d['leden'] ?? [],
                'createdBy' => 'system (auto-recovered)',
                'createdAt' => time() * 1000,
            ];
            if ($nieuw['datum'] === '') continue; // no usable date, don't add
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
            respond(['error' => 'Title and date are required.'], 400);
        }
        $type = in_array($in['type'] ?? '', ['evenement', 'district_vergadering', 'area_vergadering', 'overig'], true) ? $in['type'] : 'overig';
        // District and Area meetings are deliberately only creatable by the
        // Secretary, Chairperson, or IT — any member can create other agenda items.
        if (in_array($type, ['district_vergadering', 'area_vergadering'], true) && !heeft_rol(['admin', 'secretary', 'chair'])) {
            respond(['error' => 'Only the Secretary, Chairperson, or IT can create a District/Area meeting.'], 403);
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
        if (!$item) respond(['error' => 'Agenda item not found.'], 404);
        // Same permissions as deleting: IT/coordinator, or the original
        // creator. Agenda items linked to an outreach request
        // (voorlichtingId set) are deliberately NOT edited here — that
        // happens via "Change schedule" on the request itself, so both
        // always stay in sync.
        if (!empty($item['voorlichtingId'])) {
            respond(['error' => 'This agenda item belongs to an outreach request — change it via "Change schedule" on that request.'], 400);
        }
        $magBewerken = heeft_rol($coordinatorRollen) || $item['createdBy'] === ($_SESSION['user_name'] ?? '');
        if (!$magBewerken) respond(['error' => 'You are not permitted to do this.'], 403);

        $in = json_input();
        if (trim($in['titel'] ?? '') === '' || trim($in['datum'] ?? '') === '') {
            respond(['error' => 'Title and date are required.'], 400);
        }
        $type = in_array($in['type'] ?? '', ['evenement', 'district_vergadering', 'area_vergadering', 'overig', 'voorlichting'], true) ? $in['type'] : 'overig';
        if (in_array($type, ['district_vergadering', 'area_vergadering'], true) && !heeft_rol(['admin', 'secretary', 'chair'])) {
            respond(['error' => 'Only the Secretary, Chairperson, or IT can create a District/Area meeting.'], 403);
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
        if (!$magVerwijderen) respond(['error' => 'You are not permitted to do this.'], 403);
        unlink(agenda_path($id));
        respond(['deleted' => true]);
        break;
    }

    // ================= TO-DO LIST =================
    // Deliberately low-friction: anyone can create a task, assign it (or
    // leave it open), and complete it — no approval step, just collaboration.

    case 'todo_list': {
        $out = [];
        foreach (glob(DATA_DIR . '/todos/*.json') as $file) {
            $t = read_json($file, null);
            if ($t) $out[] = $t;
        }
        // Open tasks first (by deadline, no-deadline last), completed tasks at the bottom.
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
        if ($titel === '') respond(['error' => 'Title is required.'], 400);
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
                notify($ids, 'New task assigned — ' . $titel,
                    ($_SESSION['user_name'] ?? current_user_slug()) . ' assigned you a task: ' . $titel . ($todo['deadline'] ? ("\nDeadline: " . $todo['deadline']) : ''),
                    'outreach.php?tab=todo');
            }
        }
        respond(['todo' => $todo], 201);
        break;
    }

    case 'todo_update': {
        $id = $_GET['id'] ?? '';
        $todo = read_json(todo_path($id), null);
        if (!$todo) respond(['error' => 'Task not found.'], 404);
        $in = json_input();
        if (isset($in['titel'])) {
            $titel = trim($in['titel']);
            if ($titel === '') respond(['error' => 'Title is required.'], 400);
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
        if (!$todo) respond(['error' => 'Task not found.'], 404);
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
        if (!$magVerwijderen) respond(['error' => 'Only the creator or IT can delete this task.'], 403);
        unlink(todo_path($id));
        respond(['deleted' => true]);
        break;
    }

    default:
        respond(['error' => 'unknown action'], 400);
}
