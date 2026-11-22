<?php
/**
 * Tijdelijke IT-rechten.
 *
 * Een comité-lid vraagt tijdelijke IT-rechten aan (bijv. als IT niet achter de computer zit).
 * IT krijgt een e-mail met een link, kiest of en voor hoe lang, en de aanvrager krijgt bericht.
 * De rechten verlopen vanzelf; ze worden bij elk verzoek gecontroleerd (zie it_tijdelijk_actief_tot()
 * in config.php), er is dus geen achtergrondtaak nodig.
 *
 * Wat tijdelijke IT NIET kan (alleen een vaste IT-beheerder): de IT-rol toekennen, IT-accounts
 * wijzigen/verwijderen, e-mailadressen of wachtwoorden van accounts vervangen en zelf
 * (andermans) aanvragen goedkeuren. Zo kan niemand zichzelf via dit pad blijvend IT maken.
 */
require __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

const IT_TIJDELIJK_MAX_MINUTEN = 240;
const IT_TIJDELIJK_KEUZES = [15, 30, 60, 120, 240];
const IT_AANVRAAG_GELDIG_MS = 24 * 3600 * 1000;

/** Verwerkt een besluit op een aanvraag (gebruikt door de e-mailpagina én door ingelogde IT). */
function it_aanvraag_beslissen($id, $besluit, $minuten, $doorNaam) {
    $lijst = read_json(it_tijdelijk_pad(), []);
    $nu = time() * 1000;
    foreach ($lijst as &$a) {
        if ($a['id'] !== $id) continue;
        if ($a['status'] !== 'wacht') return ['fout' => 'Deze aanvraag is al afgehandeld.', 'code' => 409];
        if ($nu - $a['createdAt'] > IT_AANVRAAG_GELDIG_MS) { $a['status'] = 'verlopen'; write_json(it_tijdelijk_pad(), $lijst); return ['fout' => 'Deze aanvraag is verlopen (ouder dan 24 uur).', 'code' => 410]; }
        $a['beslistOp'] = $nu; $a['beslistDoor'] = $doorNaam; $a['tokenHash'] = null; // link werkt maar één keer
        if ($besluit === 'goedkeuren') {
            $minuten = max(1, min(IT_TIJDELIJK_MAX_MINUTEN, (int)$minuten));
            $a['status'] = 'goedgekeurd'; $a['minuten'] = $minuten; $a['geldigTot'] = $nu + $minuten * 60 * 1000;
            write_json(it_tijdelijk_pad(), $lijst);
            notify([$a['userId']], 'Tijdelijke IT-rechten goedgekeurd', 'Je hebt de komende ' . $minuten . ' minuten IT-rechten (tot ' . date('H:i', (int)($a['geldigTot'] / 1000)) . '). Laad de pagina opnieuw als je de nieuwe mogelijkheden nog niet ziet.', 'index.php');
            return ['ok' => true, 'status' => 'goedgekeurd', 'minuten' => $minuten, 'geldigTot' => $a['geldigTot']];
        }
        $a['status'] = 'afgewezen';
        write_json(it_tijdelijk_pad(), $lijst);
        notify([$a['userId']], 'Tijdelijke IT-rechten afgewezen', 'Je aanvraag voor tijdelijke IT-rechten is niet goedgekeurd.', 'index.php');
        return ['ok' => true, 'status' => 'afgewezen'];
    }
    return ['fout' => 'Aanvraag niet gevonden.', 'code' => 404];
}

function it_vaste_ontvangers() {
    $uit = [];
    foreach (read_json(DATA_DIR . '/users.json', []) as $u) {
        if (in_array('admin', normaliseer_gebruiker_rollen($u)['roles'], true)) $uit[] = $u;
    }
    return $uit;
}

// Alleen het besluit via de e-mailpagina werkt zonder login; alles hieronder niet.
$action = $_GET['action'] ?? '';
if ($action !== 'token_besluit' && $action !== 'token_info') require_login();

switch ($action) {

    // Een lid vraagt tijdelijke IT-rechten aan.
    case 'aanvraag_indienen': {
        if (is_demo_gebruiker()) respond(['error' => 'Niet beschikbaar in de demo-omgeving.'], 403);
        if (heeft_vaste_it()) respond(['error' => 'Je bent al IT-beheerder.'], 400);
        $uid = $_SESSION['user_id'];
        if (it_tijdelijk_actief_tot($uid)) respond(['error' => 'Je hebt op dit moment al tijdelijke IT-rechten.'], 400);
        if (!rate_limit_toegestaan('ittijdelijk:' . $uid, 5, 24 * 3600)) respond(['error' => 'Je hebt vandaag al meerdere aanvragen gedaan.'], 429);
        $lijst = read_json(it_tijdelijk_pad(), []);
        $nu = time() * 1000;
        foreach ($lijst as $a) {
            if ($a['userId'] === $uid && $a['status'] === 'wacht' && $nu - $a['createdAt'] <= IT_AANVRAAG_GELDIG_MS) respond(['error' => 'Je hebt al een openstaande aanvraag. Wacht op de reactie van IT.'], 409);
        }
        $in = json_input();
        $reden = mb_substr(trim(strip_tags((string)($in['reden'] ?? ''))), 0, 300);
        $token = veilige_token();
        $id = uuid();
        $lijst[] = ['id' => $id, 'userId' => $uid, 'naam' => $_SESSION['user_name'] ?? $uid, 'reden' => $reden, 'tokenHash' => hash('sha256', $token),
                    'status' => 'wacht', 'createdAt' => $nu, 'beslistOp' => null, 'beslistDoor' => null, 'minuten' => null, 'geldigTot' => null];
        // Oude, afgehandelde aanvragen opruimen (houd de laatste 200)
        $lijst = array_slice($lijst, -200);
        write_json(it_tijdelijk_pad(), $lijst);
        rate_limit_registreer('ittijdelijk:' . $uid);

        $link = APP_URL . '/it_goedkeuren.php?token=' . $token;
        $naam = $_SESSION['user_name'] ?? $uid;
        $tekst = "$naam vraagt tijdelijke IT-rechten aan voor het " . DISTRICT_NAME . " PI Werkportaal.\n\n" .
                 ($reden !== '' ? "Reden: $reden\n\n" : '') .
                 "Open deze link om goed te keuren (en te kiezen voor hoelang) of af te wijzen:\n$link\n\n" .
                 "De link werkt één keer en is 24 uur geldig. Doe niets als je dit niet vertrouwt.";
        $verstuurd = false;
        foreach (it_vaste_ontvangers() as $u) {
            if (!empty($u['email'])) $verstuurd = (bool)@smtp_send($u['email'], 'Aanvraag tijdelijke IT-rechten — ' . $naam, $tekst) || $verstuurd;
        }
        // Ook in het portaal zelf melden (zonder de geheime link; goedkeuren kan dan via Ledenbeheer).
        $itIds = array_column(it_vaste_ontvangers(), 'id');
        notify_zonder_mail($itIds, 'Aanvraag tijdelijke IT-rechten', "$naam vraagt tijdelijke IT-rechten aan." . ($reden !== '' ? " Reden: $reden" : '') . ' Keur goed via de e-maillink of via Ledenbeheer.', 'ledenbeheer.php');
        respond(['ok' => true, 'gemaild' => $verstuurd], 201);
        break;
    }

    // Status van de eigen aanvraag / rechten.
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

    // Het lid beëindigt zelf zijn tijdelijke rechten (of trekt een openstaande aanvraag in).
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

    // ---- Alleen vaste IT: lijst + besluit vanuit Ledenbeheer ----

    case 'aanvragen_list': {
        if (!heeft_vaste_it()) respond(['error' => 'Alleen een vaste IT-beheerder.'], 403);
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
        if (!heeft_vaste_it()) respond(['error' => 'Alleen een vaste IT-beheerder.'], 403);
        $in = json_input();
        $r = it_aanvraag_beslissen($in['id'] ?? '', ($in['besluit'] ?? '') === 'goedkeuren' ? 'goedkeuren' : 'afwijzen', $in['minuten'] ?? 15, $_SESSION['user_name'] ?? '');
        if (isset($r['fout'])) respond(['error' => $r['fout']], $r['code']);
        respond($r);
        break;
    }

    // IT trekt actieve tijdelijke rechten meteen in.
    case 'aanvraag_intrekken_door_it': {
        if (!heeft_vaste_it()) respond(['error' => 'Alleen een vaste IT-beheerder.'], 403);
        $id = json_input()['id'] ?? '';
        $lijst = read_json(it_tijdelijk_pad(), []);
        $nu = time() * 1000;
        foreach ($lijst as &$a) {
            if ($a['id'] === $id && $a['status'] === 'goedgekeurd') {
                $a['status'] = 'ingetrokken'; $a['geldigTot'] = $nu;
                notify([$a['userId']], 'Tijdelijke IT-rechten beëindigd', 'De IT-beheerder heeft je tijdelijke IT-rechten beëindigd.', 'index.php');
            }
        }
        unset($a);
        write_json(it_tijdelijk_pad(), $lijst);
        respond(['ok' => true]);
        break;
    }

    // ---- Zonder login: besluit via de geheime link uit de e-mail ----

    case 'token_info': {
        if (!rate_limit_toegestaan('ittoken:' . klant_ip(), 30, 900)) respond(['error' => 'Te veel pogingen.'], 429);
        rate_limit_registreer('ittoken:' . klant_ip());
        $a = it_aanvraag_via_token($_GET['token'] ?? '');
        if (!$a) respond(['error' => 'Deze link is ongeldig, verlopen of al gebruikt.'], 404);
        respond(['naam' => $a['naam'], 'reden' => $a['reden'], 'createdAt' => $a['createdAt'], 'keuzes' => IT_TIJDELIJK_KEUZES]);
        break;
    }

    case 'token_besluit': {
        if (!rate_limit_toegestaan('ittoken:' . klant_ip(), 30, 900)) respond(['error' => 'Te veel pogingen.'], 429);
        rate_limit_registreer('ittoken:' . klant_ip());
        $in = json_input();
        $a = it_aanvraag_via_token($in['token'] ?? '');
        if (!$a) respond(['error' => 'Deze link is ongeldig, verlopen of al gebruikt.'], 404);
        $minuten = (int)($in['minuten'] ?? 0);
        if (($in['besluit'] ?? '') === 'goedkeuren' && !in_array($minuten, IT_TIJDELIJK_KEUZES, true)) respond(['error' => 'Kies een geldige duur.'], 400);
        $r = it_aanvraag_beslissen($a['id'], ($in['besluit'] ?? '') === 'goedkeuren' ? 'goedkeuren' : 'afwijzen', $minuten, 'via e-maillink');
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
