<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$usersPath = DATA_DIR . '/users.json';

switch ($action) {

    case 'register': {
        $in = json_input();
        $email = strtolower(trim($in['email'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $name = trim($in['name'] ?? '');
        $invite = (string)($in['invite'] ?? '');

        $ipSleutelRegistratie = 'v:' . sha1(klant_ip());
        if (!rate_limit_toegestaan($ipSleutelRegistratie, 10, 900)) te_veel_pogingen();
        if (!hash_equals((string)INVITE_CODE, $invite)) {
            rate_limit_registreer($ipSleutelRegistratie);
            respond(['error' => 'Uitnodigingscode klopt niet.'], 403);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Vul een geldig e-mailadres in.'], 400);
        }
        if (strlen($password) < 8) {
            respond(['error' => 'Wachtwoord moet minstens 8 tekens zijn.'], 400);
        }
        if ($name === '') {
            respond(['error' => 'Vul een naam in.'], 400);
        }

        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['email'] === $email || $u['id'] === $email) {
                respond(['error' => 'Dit e-mailadres is al geregistreerd.'], 409);
            }
        }

        // De allereerste persoon die zich registreert voor dit district
        // wordt automatisch District-IT (admin), zodat er nooit een
        // kip-en-ei probleem is om de eerste beheerder aan te wijzen.
        // Alle volgende registraties starten als gewoon 'lid'; een admin
        // kent daarna rollen toe via het ledenbeheer-scherm. Iemand kan
        // tegenwoordig meerdere rollen tegelijk hebben (bijv. Voorzitter +
        // IT + Coördinator Drukwerk), vandaar een array i.p.v. 1 rol.
        $rollen = empty($users) ? ['admin'] : ['lid'];

        $users[] = [
            'id' => $email,
            'email' => $email,
            'name' => $name,
            'roles' => $rollen,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'createdAt' => time() * 1000,
        ];
        write_json($usersPath, $users);

        session_regenerate_id(true); // tegen session fixation

        $_SESSION['user_id'] = $email;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_roles'] = $rollen;
        respond(['id' => $email, 'email' => $email, 'name' => $name, 'roles' => $rollen]);
        break;
    }

    case 'login': {
        $in = json_input();
        $email = strtolower(trim($in['email'] ?? ''));
        $password = (string)($in['password'] ?? '');

        // Vast demo-account — geen registratie nodig, puur een sessie die
        // overal mag KIJKEN maar nergens iets mag WIJZIGEN (zie de
        // schrijf-blokkade in config.php).
        if ($email === 'dummy' && $password === 'dummy' && (defined('DEMO_ACCOUNT_ENABLED') && DEMO_ACCOUNT_ENABLED)) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = DEMO_ACCOUNT_ID;
            $_SESSION['user_name'] = 'Demo-account (alleen bekijken)';
            $_SESSION['user_roles'] = ['admin']; // ziet alles, kan niets opslaan
            respond(['id' => DEMO_ACCOUNT_ID, 'email' => 'dummy', 'name' => $_SESSION['user_name'], 'roles' => $_SESSION['user_roles']]);
        }

        $emailSleutel = 'e:' . sha1($email);
        $ipSleutel = 'i:' . sha1(klant_ip());
        if (!rate_limit_toegestaan($emailSleutel, 8, 900) || !rate_limit_toegestaan($ipSleutel, 40, 900)) te_veel_pogingen();
        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['email'] === $email && password_verify($password, $u['passwordHash'])) {
                $u = normaliseer_gebruiker_rollen($u);
                session_regenerate_id(true); // tegen session fixation
                rate_limit_wis($emailSleutel);
                $_SESSION['user_id'] = $u['id'];
                $_SESSION['user_name'] = $u['name'];
                $_SESSION['user_roles'] = $u['roles'];
                respond(['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'roles' => $_SESSION['user_roles']]);
            }
        }
        rate_limit_registreer($emailSleutel);
        rate_limit_registreer($ipSleutel);
        respond(['error' => 'E-mailadres of wachtwoord klopt niet.'], 401);
        break;
    }

    case 'wachtwoord_vergeten': {
        $in = json_input();
        $email = strtolower(trim($in['email'] ?? ''));

        // Altijd hetzelfde antwoord, ongeacht of het e-mailadres bestaat —
        // zo kan niemand van buitenaf uitvissen welke adressen geregistreerd
        // zijn. De echte e-mail (of het uitblijven ervan) is het signaal.
        $generiekAntwoord = ['ok' => true, 'melding' => 'Als dit e-mailadres bekend is, ontvang je binnen enkele minuten een link om je wachtwoord opnieuw in te stellen.'];

        $vSleutel = 'w:' . sha1($email);
        $vIpSleutel = 'wi:' . sha1(klant_ip());
        // Over de limiet? Dan stilzwijgend niets versturen (zelfde antwoord, dus geen informatie voor een aanvaller).
        if (!rate_limit_toegestaan($vSleutel, 3, 3600) || !rate_limit_toegestaan($vIpSleutel, 15, 3600)) respond($generiekAntwoord);
        rate_limit_registreer($vSleutel);
        rate_limit_registreer($vIpSleutel);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond($generiekAntwoord);

        $users = read_json($usersPath, []);
        $gebruiker = null;
        foreach ($users as $u) { if ($u['email'] === $email) { $gebruiker = $u; break; } }
        if (!$gebruiker) respond($generiekAntwoord);

        $token = veilige_token();
        write_json(DATA_DIR . '/wachtwoord_resets/' . $token . '.json', [
            'userId' => $gebruiker['id'],
            'verlooptOp' => (time() + 3600) * 1000, // 1 uur geldig
        ]);

        $link = APP_URL . '/reset_wachtwoord.php?token=' . $token;
        @smtp_send(
            $gebruiker['email'],
            'Wachtwoord opnieuw instellen — ' . DISTRICT_NAME . ' PI Werkportaal',
            "Je hebt een nieuw wachtwoord aangevraagd voor het PI Werkportaal.\n\n" .
            "Klik op onderstaande link om een nieuw wachtwoord in te stellen (1 uur geldig):\n" . $link .
            "\n\nHeb je dit niet zelf aangevraagd? Dan kun je deze e-mail negeren."
        );

        respond($generiekAntwoord);
        break;
    }

    case 'wachtwoord_resetten': {
        $in = json_input();
        $token = preg_replace('/[^a-f0-9]/', '', $in['token'] ?? '');
        $wachtwoord = (string)($in['wachtwoord'] ?? '');

        if (strlen($wachtwoord) < 8) respond(['error' => 'Wachtwoord moet minstens 8 tekens zijn.'], 400);

        $tokenPath = DATA_DIR . '/wachtwoord_resets/' . $token . '.json';
        $tokenData = read_json($tokenPath, null);
        if (!$tokenData || $tokenData['verlooptOp'] < time() * 1000) {
            if (file_exists($tokenPath)) unlink($tokenPath);
            respond(['error' => 'Deze link is verlopen of ongeldig. Vraag een nieuwe aan.'], 400);
        }

        $users = read_json($usersPath, []);
        $gevonden = false;
        foreach ($users as &$u) {
            if ($u['id'] === $tokenData['userId']) {
                $u['passwordHash'] = password_hash($wachtwoord, PASSWORD_DEFAULT);
                $gevonden = true;
                break;
            }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Account niet gevonden.'], 404);

        write_json($usersPath, $users);
        unlink($tokenPath); // token is eenmalig bruikbaar
        respond(['ok' => true]);
        break;
    }

    case 'logout': {
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
        break;
    }

    case 'me': {
        if (empty($_SESSION['user_id'])) {
            respond(['user' => null]);
        }
        $rollen = current_user_roles();
        respond(['user' => [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? '',
            'roles' => $rollen,
            'roleLabels' => array_map(fn($r) => ROLES[$r] ?? $r, $rollen),
            'district' => DISTRICT_NAME,
        ]]);
        break;
    }

    default:
        respond(['error' => 'onbekende actie'], 400);
}
