<?php
/**
 * Temporary IT rights.
 *
 * A committee member requests temporary IT rights (e.g. when IT is away from the computer).
 * IT receives an email with a link, chooses whether and for how long, and the requester is notified.
 * The rights expire on their own; they are checked on every request (see it_tijdelijk_actief_tot()
 * in config.php), so no background job is needed.
 *
 * What temporary IT can NOT do (permanent IT administrators only): grant the IT role, change/delete
 * IT accounts, replace email addresses or passwords of accounts, and approve (other people's)
 * requests. So nobody can make themselves permanent IT through this path.
 */
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

const IT_TIJDELIJK_MAX_MINUTEN = 240;
const IT_TIJDELIJK_KEUZES = [15, 30, 60, 120, 240];
const IT_AANVRAAG_GELDIG_MS = 24 * 3600 * 1000;

/** Processes a decision on a request (used by the email page and by logged-in IT). */
function it_aanvraag_beslissen($id, $besluit, $minuten, $doorNaam) {
    $lijst = read_json(it_tijdelijk_pad(), []);
    $nu = time() * 1000;
    foreach ($lijst as &$a) {
        if ($a['id'] !== $id) continue;
        if ($a['status'] !== 'wacht') return ['fout' => 'This request has already been handled.', 'code' => 409];
        if ($nu - $a['createdAt'] > IT_AANVRAAG_GELDIG_MS) { $a['status'] = 'verlopen'; write_json(it_tijdelijk_pad(), $lijst); return ['fout' => 'This request has expired (older than 24 hours).', 'code' => 410]; }
        $a['beslistOp'] = $nu; $a['beslistDoor'] = $doorNaam; $a['tokenHash'] = null; // the link works only once
        if ($besluit === 'goedkeuren') {
            $minuten = max(1, min(IT_TIJDELIJK_MAX_MINUTEN, (int)$minuten));
            $a['status'] = 'goedgekeurd'; $a['minuten'] = $minuten; $a['geldigTot'] = $nu + $minuten * 60 * 1000;
            write_json(it_tijdelijk_pad(), $lijst);
            notify([$a['userId']], 'Temporary IT rights approved', 'You have IT rights for the next ' . $minuten . ' minutes (until ' . date('H:i', (int)($a['geldigTot'] / 1000)) . '). Reload the page if you do not see the new options yet.', 'index.php');
            return ['ok' => true, 'status' => 'goedgekeurd', 'minuten' => $minuten, 'geldigTot' => $a['geldigTot']];
        }
        $a['status'] = 'afgewezen';
        write_json(it_tijdelijk_pad(), $lijst);
        notify([$a['userId']], 'Temporary IT rights rejected', 'Your request for temporary IT rights was not approved.', 'index.php');
        return ['ok' => true, 'status' => 'afgewezen'];
    }
    return ['fout' => 'Request not found.', 'code' => 404];
}

function it_vaste_ontvangers() {
    $uit = [];
    foreach (read_json(DATA_DIR . '/users.json', []) as $u) {
        if (in_array('admin', normaliseer_gebruiker_rollen($u)['roles'], true)) $uit[] = $u;
    }
    return $uit;
}

// Only the decision via the email page works without logging in; everything below requires a login.
$action = $_GET['action'] ?? '';
if ($action !== 'token_besluit' && $action !== 'token_info') require_login();

switch ($action) {

    // A member requests temporary IT rights.
    case 'aanvraag_indienen': {
        if (is_demo_gebruiker()) respond(['error' => 'Not available in the demo environment.'], 403);
        if (heeft_vaste_it()) respond(['error' => 'You are already an IT administrator.'], 400);
        $uid = $_SESSION['user_id'];
        if (it_tijdelijk_actief_tot($uid)) respond(['error' => 'You already have temporary IT rights right now.'], 400);
        if (!rate_limit_toegestaan('ittijdelijk:' . $uid, 5, 24 * 3600)) respond(['error' => 'You have already made several requests today.'], 429);
        $lijst = read_json(it_tijdelijk_pad(), []);
        $nu = time() * 1000;
        foreach ($lijst as $a) {
            if ($a['userId'] === $uid && $a['status'] === 'wacht' && $nu - $a['createdAt'] <= IT_AANVRAAG_GELDIG_MS) respond(['error' => 'You already have an open request. Wait for IT to respond.'], 409);
        }
        $in = json_input();
        $reden = mb_substr(trim(strip_tags((string)($in['reden'] ?? ''))), 0, 300);
        $token = veilige_token();
        $id = uuid();
        $lijst[] = ['id' => $id, 'userId' => $uid, 'naam' => $_SESSION['user_name'] ?? $uid, 'reden' => $reden, 'tokenHash' => hash('sha256', $token),
                    'status' => 'wacht', 'createdAt' => $nu, 'beslistOp' => null, 'beslistDoor' => null, 'minuten' => null, 'geldigTot' => null];
        // Clean up old, handled requests (keep the last 200)
        $lijst = array_slice($lijst, -200);
        write_json(it_tijdelijk_pad(), $lijst);
        rate_limit_registreer('ittijdelijk:' . $uid);

        $link = APP_URL . '/it_goedkeuren.php?token=' . $token;
        $naam = $_SESSION['user_name'] ?? $uid;
        $tekst = "$naam is requesting temporary IT rights for the " . DISTRICT_NAME . " PI Work Portal.\n\n" .
                 ($reden !== '' ? "Reason: $reden\n\n" : '') .
                 "Open this link to approve (and choose for how long) or reject:\n$link\n\n" .
                 "The link works once and is valid for 24 hours. Do nothing if you do not trust this.";
        $verstuurd = false;
        foreach (it_vaste_ontvangers() as $u) {
            if (!empty($u['email'])) $verstuurd = (bool)@smtp_send($u['email'], 'Temporary IT rights request — ' . $naam, $tekst) || $verstuurd;
        }
        // Also notify inside the portal (without the secret link; approving is then possible via Member Management).
        $itIds = array_column(it_vaste_ontvangers(), 'id');
        notify_zonder_mail($itIds, 'Temporary IT rights request', "$naam is requesting temporary IT rights." . ($reden !== '' ? " Reason: $reden" : '') . ' Approve via the email link or via Member Management.', 'ledenbeheer.php');
        respond(['ok' => true, 'gemaild' => $verstuurd], 201);
        break;
    }

    // Status of your own request / rights.
    case 'aanvraag_status': {
        $uid = $_SESSION['user_id'];
        $nu = time() * 1000; $laatste = null;
        foreach (read_json(it_tijdelijk_pad(), []) as $a) { if ($a['userId'] === $uid) $laatste = $a; }
        $tot = it_tijdelijk_actief_tot($uid);
        $uit = ['vasteIt' => heeft_vaste_it(), 'actiefTot' => $tot, 'status' => $laatste ? $laatste['status'] : null];
        if ($laatste && $laatste['status'] === 'wacht' && $nu - $laatste['createdAt'] > IT_AANVRAAG_GELDIG_MS) $uit['status'] = 'verlopen';
        respond($uit);
        break;
    }

    // The member ends their own temporary rights (or withdraws an open request).
    case 'aanvraag_intrekken': {
        $uid = $_SESSION['user_id'];
        $lijst = read_json(it_tijdelijk_pad(), []);
        $nu = time() * 1000;
        foreach ($lijst as &$a) {
            if ($a['userId'] !== $uid) continue;
            if ($a['status'] === 'wacht') { $a['status'] = 'ingetrokken'; $a['tokenHash'] = null; }
            if ($a['status'] === 'goedgekeurd' && ($a['geldigTot'] ?? 0) > $nu) { $a['geldigTot'] = $nu; $a['status'] = 'ingetrokken'; }
        }
        unset($a);
        write_json(it_tijdelijk_pad(), $lijst);
        respond(['ok' => true]);
        break;
    }

    // ---- Permanent IT only: list + decision from Member Management ----

    case 'aanvragen_list': {
        if (!heeft_vaste_it()) respond(['error' => 'Permanent IT administrators only.'], 403);
        $nu = time() * 1000; $uit = [];
        foreach (array_reverse(read_json(it_tijdelijk_pad(), [])) as $a) {
            $open = $a['status'] === 'wacht' && $nu - $a['createdAt'] <= IT_AANVRAAG_GELDIG_MS;
            $actief = $a['status'] === 'goedgekeurd' && ($a['geldigTot'] ?? 0) > $nu;
            if (!$open && !$actief) continue;
            $uit[] = ['id' => $a['id'], 'naam' => $a['naam'], 'reden' => $a['reden'], 'status' => $open ? 'wacht' : 'actief', 'createdAt' => $a['createdAt'], 'geldigTot' => $a['geldigTot']];
        }
        respond(['aanvragen' => $uit, 'keuzes' => IT_TIJDELIJK_KEUZES]);
        break;
    }

    case 'aanvraag_beslissen': {
        if (!heeft_vaste_it()) respond(['error' => 'Permanent IT administrators only.'], 403);
        $in = json_input();
        $r = it_aanvraag_beslissen($in['id'] ?? '', ($in['besluit'] ?? '') === 'goedkeuren' ? 'goedkeuren' : 'afwijzen', $in['minuten'] ?? 15, $_SESSION['user_name'] ?? '');
        if (isset($r['fout'])) respond(['error' => $r['fout']], $r['code']);
        respond($r);
        break;
    }

    // IT revokes active temporary rights immediately.
    case 'aanvraag_intrekken_door_it': {
        if (!heeft_vaste_it()) respond(['error' => 'Permanent IT administrators only.'], 403);
        $id = json_input()['id'] ?? '';
        $lijst = read_json(it_tijdelijk_pad(), []);
        $nu = time() * 1000;
        foreach ($lijst as &$a) {
            if ($a['id'] === $id && $a['status'] === 'goedgekeurd') {
                $a['status'] = 'ingetrokken'; $a['geldigTot'] = $nu;
                notify([$a['userId']], 'Temporary IT rights ended', 'The IT administrator has ended your temporary IT rights.', 'index.php');
            }
        }
        unset($a);
        write_json(it_tijdelijk_pad(), $lijst);
        respond(['ok' => true]);
        break;
    }

    // ---- Without login: decision via the secret link from the email ----

    case 'token_info': {
        if (!rate_limit_toegestaan('ittoken:' . klant_ip(), 30, 900)) respond(['error' => 'Too many attempts.'], 429);
        rate_limit_registreer('ittoken:' . klant_ip());
        $a = it_aanvraag_via_token($_GET['token'] ?? '');
        if (!$a) respond(['error' => 'This link is invalid, expired or already used.'], 404);
        respond(['naam' => $a['naam'], 'reden' => $a['reden'], 'createdAt' => $a['createdAt'], 'keuzes' => IT_TIJDELIJK_KEUZES]);
        break;
    }

    case 'token_besluit': {
        if (!rate_limit_toegestaan('ittoken:' . klant_ip(), 30, 900)) respond(['error' => 'Too many attempts.'], 429);
        rate_limit_registreer('ittoken:' . klant_ip());
        $in = json_input();
        $a = it_aanvraag_via_token($in['token'] ?? '');
        if (!$a) respond(['error' => 'This link is invalid, expired or already used.'], 404);
        $minuten = (int)($in['minuten'] ?? 0);
        if (($in['besluit'] ?? '') === 'goedkeuren' && !in_array($minuten, IT_TIJDELIJK_KEUZES, true)) respond(['error' => 'Choose a valid duration.'], 400);
        $r = it_aanvraag_beslissen($a['id'], ($in['besluit'] ?? '') === 'goedkeuren' ? 'goedkeuren' : 'afwijzen', $minuten, 'via email link');
        if (isset($r['fout'])) respond(['error' => $r['fout']], $r['code']);
        respond($r);
        break;
    }

    default:
        respond(['error' => 'onbekende actie'], 400);
}

function it_aanvraag_via_token($token) {
    $token = (string)$token;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $hash = hash('sha256', $token);
    $nu = time() * 1000;
    foreach (read_json(it_tijdelijk_pad(), []) as $a) {
        if (!empty($a['tokenHash']) && hash_equals($a['tokenHash'], $hash) && $a['status'] === 'wacht' && $nu - $a['createdAt'] <= IT_AANVRAAG_GELDIG_MS) return $a;
    }
    return null;
}
