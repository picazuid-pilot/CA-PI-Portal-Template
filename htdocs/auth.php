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

        if ($invite !== INVITE_CODE) {
            respond(['error' => 'Invite code is incorrect.'], 403);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Enter a valid email address.'], 400);
        }
        if (strlen($password) < 8) {
            respond(['error' => 'Password must be at least 8 characters.'], 400);
        }
        if ($name === '') {
            respond(['error' => 'Enter a name.'], 400);
        }

        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['email'] === $email) {
                respond(['error' => 'This email address is already registered.'], 409);
            }
        }

        // The very first person to register for this district automatically
        // becomes District IT (admin), so there's never a chicken-and-egg
        // problem when appointing the first administrator. Every
        // registration after that starts as a plain 'liaison' (member); an
        // admin assigns roles afterwards via the Member Management screen.
        // Someone can hold multiple roles at once nowadays (e.g. Chairperson
        // + IT + Print Coordinator), hence an array rather than a single role.
        $rollen = empty($users) ? ['admin'] : ['liaison'];

        $users[] = [
            'id' => $email,
            'email' => $email,
            'name' => $name,
            'roles' => $rollen,
            'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
            'createdAt' => time() * 1000,
        ];
        write_json($usersPath, $users);

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

        // Fixed demo account — no registration needed, purely a session
        // that can VIEW everything but cannot CHANGE anything anywhere
        // (see the write-blocking rule in config.php).
        if ($email === 'dummy' && $password === 'dummy') {
            $_SESSION['user_id'] = DEMO_ACCOUNT_ID;
            $_SESSION['user_name'] = 'Demo account (view only)';
            $_SESSION['user_roles'] = ['admin']; // sees everything, can save nothing
            respond(['id' => DEMO_ACCOUNT_ID, 'email' => 'dummy', 'name' => $_SESSION['user_name'], 'roles' => $_SESSION['user_roles']]);
        }

        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['email'] === $email && password_verify($password, $u['passwordHash'])) {
                $u = normaliseer_gebruiker_rollen($u);
                $_SESSION['user_id'] = $u['id'];
                $_SESSION['user_name'] = $u['name'];
                $_SESSION['user_roles'] = $u['roles'];
                respond(['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'roles' => $_SESSION['user_roles']]);
            }
        }
        respond(['error' => 'Email address or password is incorrect.'], 401);
        break;
    }

    case 'forgot_password': {
        $in = json_input();
        $email = strtolower(trim($in['email'] ?? ''));

        // Always the same response, whether or not the email address
        // exists — this way no one outside the committee can fish for
        // which addresses are registered. The actual email (or its
        // absence) is the real signal.
        $generiekAntwoord = ['ok' => true, 'melding' => 'If this email address is on file, you\'ll receive a link to reset your password within a few minutes.'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond($generiekAntwoord);

        $users = read_json($usersPath, []);
        $gebruiker = null;
        foreach ($users as $u) { if ($u['email'] === $email) { $gebruiker = $u; break; } }
        if (!$gebruiker) respond($generiekAntwoord);

        $token = veilige_token();
        write_json(DATA_DIR . '/wachtwoord_resets/' . $token . '.json', [
            'userId' => $gebruiker['id'],
            'verlooptOp' => (time() + 3600) * 1000, // valid for 1 hour
        ]);

        $link = APP_URL . '/reset_password.php?token=' . $token;
        @smtp_send(
            $gebruiker['email'],
            'Reset your password — ' . DISTRICT_NAME . ' PI Work Portal',
            "You requested a new password for the PI Work Portal.\n\n" .
            "Click the link below to set a new password (valid for 1 hour):\n" . $link .
            "\n\nDidn't request this yourself? You can safely ignore this email."
        );

        respond($generiekAntwoord);
        break;
    }

    case 'reset_password': {
        $in = json_input();
        $token = preg_replace('/[^a-f0-9]/', '', $in['token'] ?? '');
        $wachtwoord = (string)($in['wachtwoord'] ?? '');

        if (strlen($wachtwoord) < 8) respond(['error' => 'Password must be at least 8 characters.'], 400);

        $tokenPath = DATA_DIR . '/wachtwoord_resets/' . $token . '.json';
        $tokenData = read_json($tokenPath, null);
        if (!$tokenData || $tokenData['verlooptOp'] < time() * 1000) {
            if (file_exists($tokenPath)) unlink($tokenPath);
            respond(['error' => 'This link has expired or is invalid. Request a new one.'], 400);
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
        if (!$gevonden) respond(['error' => 'Account not found.'], 404);

        write_json($usersPath, $users);
        unlink($tokenPath); // token can only be used once
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
        $rollen = $_SESSION['user_roles'] ?? ['liaison'];
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
        respond(['error' => 'unknown action'], 400);
}
