<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login(); // hele bestand vereist minstens ingelogd zijn; per actie hieronder verder verfijnd

$action = $_GET['action'] ?? '';
$usersPath = DATA_DIR . '/users.json';

// Ledenbeheer (leden registreren, rollen toekennen, accounts verwijderen) is voor IT én voorzitter.
// De IT-rol blijft bij IT: een voorzitter kan 'admin' niet toekennen en geen account met de
// IT-rol wijzigen of verwijderen (anders kan de voorzitter zichzelf IT-rechten geven).
$ledenbeheerRollen = ['admin', 'voorzitter'];
function is_it_gebruiker($u) { return in_array('admin', normaliseer_gebruiker_rollen($u)['roles'], true); }
function alleen_it_melding() { respond(['error' => 'Alleen een vaste IT-beheerder kan de IT-rol toekennen, een IT-account wijzigen of verwijderen, of e-mailadressen en wachtwoorden vervangen.'], 403); }

switch ($action) {

    case 'kleuren_list': {
        // Iedereen mag de kleuren AFLEZEN (nodig om de agenda goed te tonen)
        // — alleen het WIJZIGEN ervan is IT-only, zie 'kleuren_bijwerken'.
        respond(['kleuren' => haal_kleuren_op()]);
        break;
    }

    // ================= PORTAALBREDE WEERGAVE-INSTELLINGEN =================
    // Datum-/tijdnotatie: geldt voor het hele portaal (dashboard, PI
    // Locaties, Drukwerk, Planning & Agenda, etc.) — daarom hier
    // ondergebracht, net als de kleuren hierboven, i.p.v. in één specifieke
    // module. Alleen IT mag dit wijzigen; iedereen mag het aflezen.

    case 'instellingen_get': {
        respond(['instellingen' => read_json(DATA_DIR . '/portaal_instellingen.json', ['tijdformaat' => '24', 'datumformaat' => 'dmy'])]);
        break;
    }

    case 'instellingen_bijwerken': {
        require_role(['admin']);
        $in = json_input();
        $tijdformaat = ($in['tijdformaat'] ?? '24') === '12' ? '12' : '24';
        $datumformaat = in_array($in['datumformaat'] ?? '', ['dmy', 'mdy', 'ymd'], true) ? $in['datumformaat'] : 'dmy';
        $instellingen = ['tijdformaat' => $tijdformaat, 'datumformaat' => $datumformaat];
        write_json(DATA_DIR . '/portaal_instellingen.json', $instellingen);
        respond(['instellingen' => $instellingen]);
        break;
    }

    case 'kleuren_bijwerken': {
        require_role(['admin']);
        $in = json_input();
        $nieuw = is_array($in['kleuren'] ?? null) ? $in['kleuren'] : [];
        $huidig = read_json(kleuren_pad(), []);
        foreach (STANDAARD_KLEUREN as $sleutel => $default) {
            if (isset($nieuw[$sleutel]) && preg_match('/^#[0-9a-fA-F]{6}$/', $nieuw[$sleutel])) {
                $huidig[$sleutel] = $nieuw[$sleutel];
            }
        }
        write_json(kleuren_pad(), $huidig);
        respond(['kleuren' => haal_kleuren_op()]);
        break;
    }

    case 'gebruiker_aanmaken': {
        require_role($ledenbeheerRollen);
        $in = json_input();
        $email = strtolower(trim($in['email'] ?? ''));
        $naam = trim($in['naam'] ?? '');
        $wachtwoord = (string)($in['wachtwoord'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Vul een geldig e-mailadres in.'], 400);
        if ($naam === '') respond(['error' => 'Vul een naam in.'], 400);
        if ($wachtwoord === '') {
            // Geen wachtwoord opgegeven? Genereer er zelf een veilig, willekeurig een.
            $wachtwoord = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
        }
        if (strlen($wachtwoord) < 8) respond(['error' => 'Wachtwoord moet minstens 8 tekens zijn.'], 400);

        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['email'] === $email || $u['id'] === $email) respond(['error' => 'Dit e-mailadres is al geregistreerd.'], 409);
        }

        $rollen = is_array($in['roles'] ?? null) ? array_values(array_intersect($in['roles'], array_keys(ROLES))) : [];
        if (empty($rollen)) $rollen = ['lid'];
        if (in_array('admin', $rollen, true) && !heeft_vaste_it()) alleen_it_melding();

        $users[] = [
            'id' => $email,
            'email' => $email,
            'name' => $naam,
            'roles' => $rollen,
            'passwordHash' => password_hash($wachtwoord, PASSWORD_DEFAULT),
            'createdAt' => time() * 1000,
        ];
        write_json($usersPath, $users);

        // Uitnodiging met inloggegevens versturen — als SMTP niet is
        // ingesteld, faalt dit stilletjes (@) en meld je dat gewoon terug
        // aan de IT, zodat het account alsnog gewoon is aangemaakt.
        $verstuurd = @smtp_send(
            $email,
            'Je bent uitgenodigd voor het ' . DISTRICT_NAME . ' PI Werkportaal',
            "Hoi $naam,\n\nEr is een account voor je aangemaakt in het PI Werkportaal van " . DISTRICT_NAME . ".\n\n" .
            "Inloggen kan hier: " . APP_URL . "/login.php\n\n" .
            "E-mailadres: $email\n" .
            "Wachtwoord: $wachtwoord\n\n" .
            "Wijzig dit wachtwoord gerust zodra je bent ingelogd, via je profiel.\n\nTot snel!"
        );

        respond(['ok' => true, 'uitnodigingVerstuurd' => (bool)$verstuurd, 'wachtwoord' => $wachtwoord], 201);
        break;
    }

    case 'leden_list': {
        require_role($ledenbeheerRollen);
        $isIT = heeft_vaste_it();
        $users = read_json($usersPath, []);
        $lijst = array_map(function ($u) use ($isIT) {
            $u = normaliseer_gebruiker_rollen($u);
            return [
                'id' => $u['id'],
                'naam' => $u['name'] ?? $u['id'],
                'email' => $u['email'] ?? $u['id'],
                'roles' => $u['roles'],
                'createdAt' => $u['createdAt'] ?? null,
                // Voor een voorzitter zijn IT-accounts alleen-lezen.
                'vergrendeld' => !$isIT && is_it_gebruiker($u),
            ];
        }, $users);
        usort($lijst, fn($a, $b) => strcmp($a['naam'], $b['naam']));
        $rollenUit = ROLES;
        if (!$isIT) unset($rollenUit['admin']); // de voorzitter kan de IT-rol niet kiezen
        respond(['leden' => $lijst, 'rollen' => $rollenUit]);
        break;
    }

    // Lichte ledenlijst (alleen id+naam) voor de profiel-kiezer — ook
    // bruikbaar voor Secretaris, die geen toegang heeft tot het volledige
    // Ledenbeheer-scherm (rollen/kleuren) maar wel profielen mag beheren.
    case 'leden_lijst_kiezer': {
        require_role(['admin', 'voorzitter', 'secretaris']);
        $users = read_json($usersPath, []);
        $lijst = array_map(fn($u) => ['id' => $u['id'], 'naam' => $u['name'] ?? $u['id']], $users);
        usort($lijst, fn($a, $b) => strcmp($a['naam'], $b['naam']));
        respond(['leden' => $lijst]);
        break;
    }

    // Overzicht met profielen — open voor elk ingelogd lid, puur ter
    // kennismaking (naam, rol, foto). Contactgegevens/notities staan hier
    // bewust NIET in, dat blijft privé binnen het profiel zelf.
    case 'profielen_overzicht': {
        $users = read_json($usersPath, []);
        $lijst = array_map(function ($u) {
            $u = normaliseer_gebruiker_rollen($u);
            return [
                'id' => $u['id'],
                'naam' => $u['name'] ?? $u['id'],
                'roles' => $u['roles'],
                'profielfoto' => $u['profielfoto'] ?? null,
                'regio' => $u['regio'] ?? '',
            ];
        }, $users);
        usort($lijst, fn($a, $b) => strcmp($a['naam'], $b['naam']));
        respond(['leden' => $lijst, 'rollen' => ROLES]);
        break;
    }

    case 'profiel_get': {
        $id = $_GET['id'] ?? $_SESSION['user_id'];
        $isEigen = $id === $_SESSION['user_id'];
        // Bekijken mag door elk ingelogd lid (klein hecht team) — alleen
        // BEWERKEN is beperkt tot het lid zelf, Secretaris, en IT.
        $magBewerken = $isEigen || heeft_rol(['admin', 'voorzitter', 'secretaris']);

        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['id'] === $id) {
                respond(['profiel' => [
                    'id' => $u['id'],
                    'naam' => $u['name'] ?? $u['id'],
                    'email' => $u['email'] ?? $u['id'],
                    'telefoon' => $u['telefoon'] ?? '',
                    'adres' => $u['adres'] ?? '',
                    'regio' => $u['regio'] ?? '',
                    'noodcontact' => $u['noodcontact'] ?? '',
                    'notitie' => $u['notitie'] ?? '',
                    'profielfoto' => $u['profielfoto'] ?? null,
                ], 'magBewerken' => $magBewerken]);
            }
        }
        respond(['error' => 'Lid niet gevonden.'], 404);
        break;
    }

    case 'profiel_bijwerken': {
        $id = $_GET['id'] ?? $_SESSION['user_id'];
        $isEigen = $id === $_SESSION['user_id'];
        if (!$isEigen && !heeft_rol(['admin', 'voorzitter', 'secretaris'])) respond(['error' => 'Geen toestemming.'], 403);

        $in = json_input();
        $users = read_json($usersPath, []);
        $gevonden = false;
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                if (isset($in['naam']) && trim($in['naam']) !== '') $u['name'] = trim($in['naam']);
                if (isset($in['telefoon'])) $u['telefoon'] = trim($in['telefoon']);
                if (isset($in['adres'])) $u['adres'] = trim($in['adres']);
                if (isset($in['regio'])) $u['regio'] = trim($in['regio']);
                if (isset($in['noodcontact'])) $u['noodcontact'] = trim($in['noodcontact']);
                if (isset($in['notitie'])) $u['notitie'] = trim($in['notitie']);
                $gevonden = true;
                if ($id === $_SESSION['user_id']) $_SESSION['user_name'] = $u['name']; // eigen sessie direct bijwerken
                break;
            }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Lid niet gevonden.'], 404);
        write_json($usersPath, $users);
        respond(['ok' => true]);
        break;
    }

    case 'profielfoto_upload': {
        $id = $_GET['id'] ?? $_SESSION['user_id'];
        $isEigen = $id === $_SESSION['user_id'];
        if (!$isEigen && !heeft_rol(['admin', 'voorzitter', 'secretaris'])) respond(['error' => 'Geen toestemming.'], 403);
        if (empty($_FILES['bestand'])) respond(['error' => 'Geen bestand ontvangen.'], 400);
        $file = $_FILES['bestand'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload mislukt.'], 500);

        $toegestaan = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = mime_content_type($file['tmp_name']);
        if (!isset($toegestaan[$mime])) respond(['error' => 'Alleen JPG, PNG of WEBP toegestaan.'], 400);
        if ($file['size'] > 5 * 1024 * 1024) respond(['error' => 'Bestand mag maximaal 5 MB zijn.'], 400);

        $users = read_json($usersPath, []);
        $gevonden = false;
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                // Oude profielfoto (indien aanwezig) opruimen vóór de nieuwe wegschrijven.
                if (!empty($u['profielfoto'])) {
                    $oud = DATA_DIR . '/profielfotos/' . $u['profielfoto'];
                    if (file_exists($oud)) unlink($oud);
                }
                $naam = veilige_token() . '.' . $toegestaan[$mime];
                if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/profielfotos/' . $naam)) {
                    respond(['error' => 'Opslaan mislukt.'], 500);
                }
                $u['profielfoto'] = $naam;
                $gevonden = true;
                break;
            }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Lid niet gevonden.'], 404);
        write_json($usersPath, $users);
        respond(['ok' => true]);
        break;
    }

    // Streamt de profielfoto door het portaal heen (blijft achter de login).
    case 'profielfoto_stream': {
        $bestand = basename($_GET['bestand'] ?? '');
        $pad = DATA_DIR . '/profielfotos/' . $bestand;
        if ($bestand === '' || !file_exists($pad)) { http_response_code(404); exit; }
        $ext = strtolower(pathinfo($pad, PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($pad));
        header('Cache-Control: private, max-age=3600');
        readfile($pad);
        exit;
    }

    // IT: e-mailadres (inlogadres) van een account wijzigen. Het interne account-ID
    // blijft ongewijzigd, zodat profiel, rollen, aanvragen en notificaties
    // gewoon gekoppeld blijven. Inloggen en mails gebruiken het veld 'email'.
    case 'email_bijwerken': {
        require_role(['admin']);
        if (!heeft_vaste_it()) alleen_it_melding(); // niet met tijdelijke IT-rechten
        $in = json_input();
        $id = trim($in['id'] ?? '');
        $nieuw = strtolower(trim($in['email'] ?? ''));
        if ($id === '' || $id === DEMO_ACCOUNT_ID) respond(['error' => 'Onbekend lid.'], 400);
        if (!filter_var($nieuw, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Vul een geldig e-mailadres in.'], 400);
        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['id'] !== $id && ($u['email'] === $nieuw || $u['id'] === $nieuw)) respond(['error' => 'Dit e-mailadres is al in gebruik bij een ander account.'], 409);
        }
        $gevonden = false; $oud = '';
        foreach ($users as &$u) {
            if ($u['id'] === $id) { $oud = $u['email'] ?? $id; $u['email'] = $nieuw; $gevonden = true; break; }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Lid niet gevonden.'], 404);
        write_json($usersPath, $users);
        respond(['ok' => true, 'oud' => $oud, 'email' => $nieuw]);
        break;
    }

    // IT: wachtwoord van een account vervangen door een nieuw tijdelijk wachtwoord
    // (komt eenmalig in beeld; er wordt ook geprobeerd het te mailen).
    case 'wachtwoord_vervangen': {
        require_role(['admin']);
        if (!heeft_vaste_it()) alleen_it_melding(); // niet met tijdelijke IT-rechten
        $in = json_input();
        $id = trim($in['id'] ?? '');
        if ($id === '' || $id === DEMO_ACCOUNT_ID) respond(['error' => 'Onbekend lid.'], 400);
        $nieuwWw = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
        $users = read_json($usersPath, []);
        $gevonden = null;
        foreach ($users as &$u) {
            if ($u['id'] === $id) { $u['passwordHash'] = password_hash($nieuwWw, PASSWORD_DEFAULT); $gevonden = $u; break; }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Lid niet gevonden.'], 404);
        write_json($usersPath, $users);
        $verstuurd = !empty($gevonden['email']) ? @smtp_send(
            $gevonden['email'],
            'Nieuw wachtwoord voor het ' . DISTRICT_NAME . ' PI Werkportaal',
            "Hoi " . ($gevonden['name'] ?? '') . ",\n\nDe IT-beheerder heeft een nieuw wachtwoord voor je ingesteld.\n\n" .
            "Inloggen: " . APP_URL . "/login.php\nE-mailadres: " . $gevonden['email'] . "\nTijdelijk wachtwoord: $nieuwWw\n\n" .
            "Wijzig het zodra je bent ingelogd, via je profiel."
        ) : false;
        respond(['ok' => true, 'wachtwoord' => $nieuwWw, 'verstuurd' => (bool)$verstuurd]);
        break;
    }

    case 'gebruiker_verwijderen': {
        require_role($ledenbeheerRollen);
        $id = $_GET['id'] ?? '';
        if ($id === '') respond(['error' => 'Onbekend lid.'], 400);
        if ($id === DEMO_ACCOUNT_ID) respond(['error' => 'Het demo-account bestaat niet als echt account en kan niet verwijderd worden.'], 400);

        $users = read_json($usersPath, []);
        $te_verwijderen = null;
        foreach ($users as $u) { if ($u['id'] === $id) { $te_verwijderen = $u; break; } }
        if (!$te_verwijderen) respond(['error' => 'Lid niet gevonden.'], 404);
        if (is_it_gebruiker($te_verwijderen) && !heeft_vaste_it()) alleen_it_melding();

        // Voorkom dat de laatste IT-beheerder zichzelf (of de enige andere
        // admin) wegklikt — dan kan niemand meer bij Ledenbeheer komen.
        if (in_array('admin', normaliseer_gebruiker_rollen($te_verwijderen)['roles'], true)) {
            $andereAdmins = array_filter($users, fn($x) => $x['id'] !== $id && in_array('admin', normaliseer_gebruiker_rollen($x)['roles'], true));
            if (empty($andereAdmins)) respond(['error' => 'Dit is de enige IT-beheerder — verwijderen zou niemand meer toegang tot dit scherm geven. Wijs eerst iemand anders IT toe.'], 400);
        }

        if (!empty($te_verwijderen['profielfoto'])) {
            $pad = DATA_DIR . '/profielfotos/' . $te_verwijderen['profielfoto'];
            if (file_exists($pad)) unlink($pad);
        }

        $users = array_values(array_filter($users, fn($u) => $u['id'] !== $id));
        write_json($usersPath, $users);
        respond(['ok' => true]);
        break;
    }

    case 'rollen_bijwerken': {
        require_role($ledenbeheerRollen);
        $in = json_input();
        if (is_array($in['roles'] ?? null) && in_array('admin', $in['roles'], true) && !heeft_vaste_it()) alleen_it_melding();
        $id = trim($in['id'] ?? '');
        $nieuweRollen = is_array($in['roles'] ?? null) ? array_values(array_intersect($in['roles'], array_keys(ROLES))) : [];
        if ($id === '') respond(['error' => 'Onbekend lid.'], 400);
        if (empty($nieuweRollen)) $nieuweRollen = ['lid']; // nooit zonder enige rol, anders kan iemand nergens meer bij

        $users = read_json($usersPath, []);
        $gevonden = false;
        $eigenAccountZonderAdmin = false;
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                if (is_it_gebruiker($u) && !heeft_vaste_it()) alleen_it_melding();
                // Voorkom dat de laatste/eigen IT-toegang per ongeluk wordt
                // weggehaald zonder dat er nog een andere admin overblijft —
                // anders kan niemand meer bij het ledenbeheer-scherm komen.
                if ($id === $_SESSION['user_id'] && !in_array('admin', $nieuweRollen, true)) {
                    $andereAdmins = array_filter($users, fn($x) => $x['id'] !== $id && in_array('admin', normaliseer_gebruiker_rollen($x)['roles'], true));
                    if (empty($andereAdmins)) $eigenAccountZonderAdmin = true;
                }
                $u['roles'] = $nieuweRollen;
                unset($u['role']); // oude enkelvoudige veld opruimen, voortaan alleen 'roles'
                $gevonden = true;
                break;
            }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Lid niet gevonden.'], 404);
        if ($eigenAccountZonderAdmin) {
            respond(['error' => 'Je kunt je eigen IT-rol niet weghalen als er geen andere IT-beheerder is — dan kan niemand meer bij dit scherm.'], 400);
        }

        write_json($usersPath, $users);

        // Sessie van de bewerkte gebruiker bijwerken als die persoon zichzelf
        // aanpast (anders lopen rechten pas na opnieuw inloggen goed).
        if ($id === $_SESSION['user_id']) $_SESSION['user_roles'] = $nieuweRollen;

        notify([$id], 'Je rollen zijn bijgewerkt', 'Je hebt nu de volgende rol(len): ' . implode(', ', array_map(fn($r) => ROLES[$r] ?? $r, $nieuweRollen)), 'ledenbeheer.php');

        respond(['ok' => true, 'roles' => $nieuweRollen]);
        break;
    }

    default:
        respond(['error' => 'onbekende actie'], 400);
}
