<?php
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login(); // whole file requires at least being logged in; refined per action below

$action = $_GET['action'] ?? '';
$usersPath = DATA_DIR . '/users.json';

// Member management (registering members, assigning roles, deleting accounts) is for IT and the chairperson.
// The IT role stays with IT: a chairperson cannot grant 'admin' nor change or delete an IT account
// (otherwise the chairperson could give themselves IT rights).
$ledenbeheerRollen = ['admin', 'chair'];
function is_it_gebruiker($u) { return in_array('admin', normaliseer_gebruiker_rollen($u)['roles'], true); }
function alleen_it_melding() { respond(['error' => 'Only a permanent IT administrator can grant the IT role, change or delete an IT account, or replace email addresses and passwords.'], 403); }

switch ($action) {

    case 'kleuren_list': {
        // Anyone may READ the colours (needed to display the calendar
        // correctly) — only CHANGING them is IT-only, see 'kleuren_bijwerken'.
        respond(['kleuren' => haal_kleuren_op()]);
        break;
    }

    // ================= PORTAL-WIDE DISPLAY SETTINGS =================
    // Date/time format: applies across the whole portal (dashboard, PI
    // Locations, Print, Planning & Calendar, etc.) — kept here, alongside
    // the colours above, rather than in one specific module. Only IT may
    // change this; anyone may read it.

    case 'instellingen_get': {
        respond(['instellingen' => read_json(DATA_DIR . '/portal_settings.json', ['tijdformaat' => '24', 'datumformaat' => 'dmy'])]);
        break;
    }

    case 'instellingen_bijwerken': {
        require_role(['admin']);
        $in = json_input();
        $tijdformaat = ($in['tijdformaat'] ?? '24') === '12' ? '12' : '24';
        $datumformaat = in_array($in['datumformaat'] ?? '', ['dmy', 'mdy', 'ymd'], true) ? $in['datumformaat'] : 'dmy';
        $instellingen = ['tijdformaat' => $tijdformaat, 'datumformaat' => $datumformaat];
        write_json(DATA_DIR . '/portal_settings.json', $instellingen);
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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 400);
        if ($naam === '') respond(['error' => 'Enter a name.'], 400);
        if ($wachtwoord === '') {
            // No password provided? Generate a secure, random one ourselves.
            $wachtwoord = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
        }
        if (strlen($wachtwoord) < 8) respond(['error' => 'Password must be at least 8 characters.'], 400);

        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['email'] === $email || $u['id'] === $email) respond(['error' => 'This email address is already registered.'], 409);
        }

        $rollen = is_array($in['roles'] ?? null) ? array_values(array_intersect($in['roles'], array_keys(ROLES))) : [];
        if (empty($rollen)) $rollen = ['liaison'];
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

        // Send an invitation with the login details — if SMTP isn't
        // configured, this fails silently (@) and we just report that
        // back to IT, so the account still gets created either way.
        $verstuurd = @smtp_send(
            $email,
            'You\'ve been invited to the ' . DISTRICT_NAME . ' PI Work Portal',
            "Hi $naam,\n\nAn account has been created for you in the PI Work Portal for " . DISTRICT_NAME . ".\n\n" .
            "You can log in here: " . APP_URL . "/login.php\n\n" .
            "Email address: $email\n" .
            "Password: $wachtwoord\n\n" .
            "Feel free to change this password once you're logged in, via your profile.\n\nSee you soon!"
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
                // For a chairperson, IT accounts are read-only.
                'vergrendeld' => !$isIT && is_it_gebruiker($u),
            ];
        }, $users);
        usort($lijst, fn($a, $b) => strcmp($a['naam'], $b['naam']));
        $rollenUit = ROLES;
        if (!$isIT) unset($rollenUit['admin']); // the chairperson cannot pick the IT role
        respond(['leden' => $lijst, 'rollen' => $rollenUit]);
        break;
    }

    // Lightweight member list (id+name only) for the profile picker — also
    // usable by the Secretary, who doesn't have access to the full Member
    // Management screen (roles/colours) but may manage profiles.
    case 'leden_lijst_kiezer': {
        require_role(['admin', 'chair', 'secretary']);
        $users = read_json($usersPath, []);
        $lijst = array_map(fn($u) => ['id' => $u['id'], 'naam' => $u['name'] ?? $u['id']], $users);
        usort($lijst, fn($a, $b) => strcmp($a['naam'], $b['naam']));
        respond(['leden' => $lijst]);
        break;
    }

    // Profile overview — open to any logged-in member, purely to get to
    // know each other (name, role, photo). Contact details/notes are
    // deliberately NOT included here — those stay private within the
    // profile itself.
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
        // Viewing is allowed for any logged-in member (small, close-knit
        // team) — only EDITING is restricted to the member themselves,
        // the Secretary, and IT.
        $magBewerken = $isEigen || heeft_rol(['admin', 'chair', 'secretary']);

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
        respond(['error' => 'Member not found.'], 404);
        break;
    }

    case 'profiel_bijwerken': {
        $id = $_GET['id'] ?? $_SESSION['user_id'];
        $isEigen = $id === $_SESSION['user_id'];
        if (!$isEigen && !heeft_rol(['admin', 'chair', 'secretary'])) respond(['error' => 'You are not permitted to do this.'], 403);

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
                if ($id === $_SESSION['user_id']) $_SESSION['user_name'] = $u['name']; // update own session right away
                break;
            }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Member not found.'], 404);
        write_json($usersPath, $users);
        respond(['ok' => true]);
        break;
    }

    case 'profielfoto_upload': {
        $id = $_GET['id'] ?? $_SESSION['user_id'];
        $isEigen = $id === $_SESSION['user_id'];
        if (!$isEigen && !heeft_rol(['admin', 'chair', 'secretary'])) respond(['error' => 'You are not permitted to do this.'], 403);
        if (empty($_FILES['bestand'])) respond(['error' => 'No file received.'], 400);
        $file = $_FILES['bestand'];
        if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Upload failed.'], 500);

        $toegestaan = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = mime_content_type($file['tmp_name']);
        if (!isset($toegestaan[$mime])) respond(['error' => 'Only JPG, PNG, or WEBP are allowed.'], 400);
        if ($file['size'] > 5 * 1024 * 1024) respond(['error' => 'File must be no larger than 5 MB.'], 400);

        $users = read_json($usersPath, []);
        $gevonden = false;
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                // Clean up the old profile photo (if any) before saving the new one.
                if (!empty($u['profielfoto'])) {
                    $oud = DATA_DIR . '/profielfotos/' . $u['profielfoto'];
                    if (file_exists($oud)) unlink($oud);
                }
                $naam = veilige_token() . '.' . $toegestaan[$mime];
                if (!move_uploaded_file($file['tmp_name'], DATA_DIR . '/profielfotos/' . $naam)) {
                    respond(['error' => 'Saving failed.'], 500);
                }
                $u['profielfoto'] = $naam;
                $gevonden = true;
                break;
            }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Member not found.'], 404);
        write_json($usersPath, $users);
        respond(['ok' => true]);
        break;
    }

    // Streams the profile photo through the portal (stays behind login).
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

    // IT: change the email address (login address) of an account. The internal account ID
    // stays unchanged, so profile, roles, requests and notifications remain linked.
    // Logging in and sending mail use the 'email' field.
    case 'email_bijwerken': {
        require_role(['admin']);
        if (!heeft_vaste_it()) alleen_it_melding(); // not with temporary IT rights
        $in = json_input();
        $id = trim($in['id'] ?? '');
        $nieuw = strtolower(trim($in['email'] ?? ''));
        if ($id === '' || $id === DEMO_ACCOUNT_ID) respond(['error' => 'Unknown member.'], 400);
        if (!filter_var($nieuw, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Enter a valid email address.'], 400);
        $users = read_json($usersPath, []);
        foreach ($users as $u) {
            if ($u['id'] !== $id && ($u['email'] === $nieuw || $u['id'] === $nieuw)) respond(['error' => 'This email address is already used by another account.'], 409);
        }
        $gevonden = false; $oud = '';
        foreach ($users as &$u) {
            if ($u['id'] === $id) { $oud = $u['email'] ?? $id; $u['email'] = $nieuw; $gevonden = true; break; }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Member not found.'], 404);
        write_json($usersPath, $users);
        respond(['ok' => true, 'oud' => $oud, 'email' => $nieuw]);
        break;
    }

    // IT: replace an account's password with a new temporary password
    // (shown once; we also try to email it).
    case 'wachtwoord_vervangen': {
        require_role(['admin']);
        if (!heeft_vaste_it()) alleen_it_melding(); // not with temporary IT rights
        $in = json_input();
        $id = trim($in['id'] ?? '');
        if ($id === '' || $id === DEMO_ACCOUNT_ID) respond(['error' => 'Unknown member.'], 400);
        $nieuwWw = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
        $users = read_json($usersPath, []);
        $gevonden = null;
        foreach ($users as &$u) {
            if ($u['id'] === $id) { $u['passwordHash'] = password_hash($nieuwWw, PASSWORD_DEFAULT); $gevonden = $u; break; }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Member not found.'], 404);
        write_json($usersPath, $users);
        $verstuurd = !empty($gevonden['email']) ? @smtp_send(
            $gevonden['email'],
            'New password for the ' . DISTRICT_NAME . ' PI Work Portal',
            "Hi " . ($gevonden['name'] ?? '') . ",\n\nThe IT administrator has set a new password for you.\n\n" .
            "Log in: " . APP_URL . "/login.php\nEmail address: " . $gevonden['email'] . "\nTemporary password: $nieuwWw\n\n" .
            "Please change it once you're logged in, via your profile."
        ) : false;
        respond(['ok' => true, 'wachtwoord' => $nieuwWw, 'verstuurd' => (bool)$verstuurd]);
        break;
    }

    case 'gebruiker_verwijderen': {
        require_role($ledenbeheerRollen);
        $id = $_GET['id'] ?? '';
        if ($id === '') respond(['error' => 'Unknown member.'], 400);
        if ($id === DEMO_ACCOUNT_ID) respond(['error' => 'The demo account isn\'t a real account and cannot be deleted.'], 400);

        $users = read_json($usersPath, []);
        $te_verwijderen = null;
        foreach ($users as $u) { if ($u['id'] === $id) { $te_verwijderen = $u; break; } }
        if (!$te_verwijderen) respond(['error' => 'Member not found.'], 404);
        if (is_it_gebruiker($te_verwijderen) && !heeft_vaste_it()) alleen_it_melding();

        // Prevent removing the last IT administrator (yourself or the only
        // other admin) — otherwise no one could reach Member Management anymore.
        if (in_array('admin', normaliseer_gebruiker_rollen($te_verwijderen)['roles'], true)) {
            $andereAdmins = array_filter($users, fn($x) => $x['id'] !== $id && in_array('admin', normaliseer_gebruiker_rollen($x)['roles'], true));
            if (empty($andereAdmins)) respond(['error' => 'This is the only IT administrator — deleting them would leave no one with access to this screen. Assign IT to someone else first.'], 400);
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
        if ($id === '') respond(['error' => 'Unknown member.'], 400);
        if (empty($nieuweRollen)) $nieuweRollen = ['liaison']; // never leave someone with no role at all, or they'd lose access everywhere

        $users = read_json($usersPath, []);
        $gevonden = false;
        $eigenAccountZonderAdmin = false;
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                if (is_it_gebruiker($u) && !heeft_vaste_it()) alleen_it_melding();
                // Prevent accidentally removing the last/your own IT access
                // without any other admin remaining — otherwise no one
                // could reach the Member Management screen anymore.
                if ($id === $_SESSION['user_id'] && !in_array('admin', $nieuweRollen, true)) {
                    $andereAdmins = array_filter($users, fn($x) => $x['id'] !== $id && in_array('admin', normaliseer_gebruiker_rollen($x)['roles'], true));
                    if (empty($andereAdmins)) $eigenAccountZonderAdmin = true;
                }
                $u['roles'] = $nieuweRollen;
                unset($u['role']); // clean up the old single-role field, only 'roles' from now on
                $gevonden = true;
                break;
            }
        }
        unset($u);
        if (!$gevonden) respond(['error' => 'Member not found.'], 404);
        if ($eigenAccountZonderAdmin) {
            respond(['error' => 'You cannot remove your own IT role when there is no other IT administrator — that would leave no one with access to this screen.'], 400);
        }

        write_json($usersPath, $users);

        // Update the edited user's session if they're editing themselves
        // (otherwise permissions only take effect after logging in again).
        if ($id === $_SESSION['user_id']) $_SESSION['user_roles'] = $nieuweRollen;

        notify([$id], 'Your roles have been updated', 'You now hold the following role(s): ' . implode(', ', array_map(fn($r) => ROLES[$r] ?? $r, $nieuweRollen)), 'member_management.php');

        respond(['ok' => true, 'roles' => $nieuweRollen]);
        break;
    }

    default:
        respond(['error' => 'unknown action'], 400);
}
