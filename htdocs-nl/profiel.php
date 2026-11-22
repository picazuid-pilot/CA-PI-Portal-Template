<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$magAnderenBewerken = heeft_rol(['admin', 'voorzitter', 'secretaris']);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Profiel — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <?php if ($magAnderenBewerken): ?>
  <div class="card">
    <strong>Profiel bekijken/bewerken van</strong>
    <div class="form-grid" style="margin-top:8px;">
      <div><select id="p-ledenkiezer" onchange="ledenKiezerGewijzigd()" style="max-width:320px;"></select></div>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:6px 0 0;">Als Secretaris/IT kun je hier ook het profiel van andere leden bijwerken (bijv. contactgegevens corrigeren).</p>
  </div>
  <?php endif; ?>

  <div class="card" style="margin-top:16px;">
    <strong id="p-titel">Mijn profiel</strong>

    <div style="display:flex; align-items:center; gap:16px; margin-top:12px;">
      <div id="p-foto-vak" style="width:88px; height:88px; border-radius:50%; background:var(--primary-light-95); overflow:hidden; flex:0 0 auto; display:flex; align-items:center; justify-content:center; color:var(--grey); font-size:11px; text-align:center;">geen foto</div>
      <div>
        <input type="file" id="p-foto-input" accept=".jpg,.jpeg,.png,.webp" style="display:none;" onchange="fotoUploaden()" />
        <button id="p-foto-knop" class="secondary" onclick="document.getElementById('p-foto-input').click()">📷 Foto uploaden/vervangen</button>
        <p style="font-size:11px; color:var(--grey); margin:6px 0 0;">JPG, PNG of WEBP, max 5 MB.</p>
      </div>
    </div>

    <label style="margin-top:10px;">Naam</label><input id="p-naam" />
    <label>E-mailadres (login; wijzigen kan via IT)</label><input id="p-email" disabled style="background:var(--primary-light-95);" />
    <div class="form-grid">
      <div><label>Telefoon</label><input id="p-telefoon" /></div>
      <div><label>Noodcontact (naam + telefoon, optioneel)</label><input id="p-noodcontact" /></div>
    </div>
    <label>Adres (optioneel)</label><input id="p-adres" />
    <label>Regio / werkgebied (optioneel — bijv. "Rotterdam centrum") <span style="font-weight:normal; color:var(--grey); font-size:11px;">— verschijnt met een cirkel op de ledenkaart</span></label>
    <input id="p-regio" placeholder="bijv. Rotterdam centrum" />
    <label>Notitie (optioneel — bijv. bereikbaarheid, bijzonderheden)</label>
    <textarea id="p-notitie" rows="3"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button id="p-opslaan-knop" class="primary" onclick="profielOpslaan()">Opslaan</button>
    </div>
  </div>

  <?php if (!is_demo_gebruiker() && !heeft_vaste_it()): ?>
  <div class="card" id="it-aanvraag-kaart" style="margin-top:16px;">
    <strong>Tijdelijke IT-rechten</strong>
    <p style="color:var(--grey); font-size:12.5px; margin:4px 0 10px;">Is IT even niet bereikbaar en moet er iets gebeuren? Vraag tijdelijke IT-rechten aan. IT krijgt een e-mail, kiest of en hoelang, en jij krijgt bericht. Blijvende IT-acties (IT-rol toekennen, accounts of wachtwoorden van IT wijzigen) blijven altijd bij een vaste IT-beheerder.</p>
    <div id="it-aanvraag-inhoud">Laden…</div>
  </div>
  <?php endif; ?>

</div>

<script>
// ---- Tijdelijke IT-rechten aanvragen ----
async function itAanvraagLaad() {
  const el = document.getElementById('it-aanvraag-inhoud'); if (!el) return;
  const res = await fetch('api_it_tijdelijk.php?action=aanvraag_status', { cache: 'no-store' });
  const d = await res.json();
  const tijd = (ms) => new Date(ms).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
  if (d.actiefTot) {
    el.innerHTML = `<p><span class="badge groen">Actief</span> Je hebt tijdelijke IT-rechten tot <strong>${tijd(d.actiefTot)}</strong>.</p><button class="secondary" onclick="itAanvraagIntrekken()">Nu beëindigen</button>`;
  } else if (d.status === 'wacht') {
    el.innerHTML = `<p><span class="badge geel">Wacht op IT</span> Je aanvraag is verstuurd; je krijgt bericht zodra IT heeft gereageerd.</p><button class="secondary" onclick="itAanvraagIntrekken()">Aanvraag intrekken</button>`;
  } else {
    const eerder = d.status === 'afgewezen' ? '<p style="color:var(--grey); font-size:12.5px;">Je vorige aanvraag is afgewezen.</p>' : '';
    el.innerHTML = `${eerder}<label>Waarvoor heb je het nodig? (optioneel, IT ziet dit)</label><input id="it-reden" maxlength="300" placeholder="bijv. flyers staan niet in de database" /><div style="margin-top:10px;"><button class="primary" onclick="itAanvraagIndienen()">Tijdelijke IT-rechten aanvragen</button></div>`;
  }
}
async function itAanvraagIndienen() {
  const reden = document.getElementById('it-reden').value;
  const res = await fetch('api_it_tijdelijk.php?action=aanvraag_indienen', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ reden }) });
  const d = await res.json();
  if (!res.ok) { alert(d.error || 'Aanvragen mislukt.'); return; }
  itAanvraagLaad();
}
async function itAanvraagIntrekken() {
  await fetch('api_it_tijdelijk.php?action=aanvraag_intrekken', { method: 'POST', credentials: 'same-origin' });
  itAanvraagLaad();
}
itAanvraagLaad();
</script>

<script>
const magAnderenBewerken = <?= $magAnderenBewerken ? 'true' : 'false' ?>;
const eigenUserId = <?= json_encode($_SESSION['user_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let huidigProfielId = eigenUserId;

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

async function init() {
  const paramId = new URLSearchParams(location.search).get('id');
  if (magAnderenBewerken) {
    const res = await fetch('api_leden.php?action=leden_lijst_kiezer', { cache: 'no-store' });
    const data = await res.json();
    const select = document.getElementById('p-ledenkiezer');
    select.innerHTML = data.leden.map(l => `<option value="${l.id}" ${l.id === eigenUserId ? 'selected' : ''}>${escapeHtml(l.naam)}${l.id === eigenUserId ? ' (jijzelf)' : ''}</option>`).join('');
    if (paramId) { select.value = paramId; await laadProfiel(paramId); return; }
  } else if (paramId) {
    // Regulier lid dat via het ledenoverzicht naar iemand anders klikt —
    // bekijken mag, bewerken wordt door de backend geblokkeerd/verborgen.
    await laadProfiel(paramId);
    return;
  }
  await laadProfiel(eigenUserId);
}

function ledenKiezerGewijzigd() {
  laadProfiel(document.getElementById('p-ledenkiezer').value);
}

async function laadProfiel(id) {
  huidigProfielId = id;
  const res = await fetch('api_leden.php?action=profiel_get&id=' + encodeURIComponent(id), { cache: 'no-store' });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Kon profiel niet laden.'); return; }
  const p = data.profiel;
  document.getElementById('p-titel').textContent = id === eigenUserId ? 'Mijn profiel' : 'Profiel — ' + p.naam;
  document.getElementById('p-naam').value = p.naam;
  document.getElementById('p-email').value = p.email;
  document.getElementById('p-telefoon').value = p.telefoon;
  document.getElementById('p-adres').value = p.adres;
  document.getElementById('p-regio').value = p.regio;
  document.getElementById('p-noodcontact').value = p.noodcontact;
  document.getElementById('p-notitie').value = p.notitie;
  document.getElementById('p-foto-vak').innerHTML = p.profielfoto
    ? `<img src="api_leden.php?action=profielfoto_stream&bestand=${encodeURIComponent(p.profielfoto)}" style="width:100%; height:100%; object-fit:cover;" />`
    : 'geen foto';

  // Als je niet mag bewerken: alles read-only tonen, geen opslaan-/foto-knop.
  const magBewerken = !!data.magBewerken;
  ['p-naam','p-telefoon','p-adres','p-regio','p-noodcontact','p-notitie'].forEach(elId => document.getElementById(elId).disabled = !magBewerken);
  document.getElementById('p-opslaan-knop').style.display = magBewerken ? '' : 'none';
  document.getElementById('p-foto-knop').style.display = magBewerken ? '' : 'none';
}

async function fotoUploaden() {
  const inputEl = document.getElementById('p-foto-input');
  if (!inputEl.files.length) return;
  const fd = new FormData();
  fd.append('bestand', inputEl.files[0]);
  const res = await fetch('api_leden.php?action=profielfoto_upload&id=' + encodeURIComponent(huidigProfielId), { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  inputEl.value = '';
  if (!res.ok) { alert(data.error || 'Upload mislukt.'); return; }
  laadProfiel(huidigProfielId);
}

async function profielOpslaan() {
  const body = {
    naam: document.getElementById('p-naam').value,
    telefoon: document.getElementById('p-telefoon').value,
    adres: document.getElementById('p-adres').value,
    regio: document.getElementById('p-regio').value,
    noodcontact: document.getElementById('p-noodcontact').value,
    notitie: document.getElementById('p-notitie').value,
  };
  const res = await fetch('api_leden.php?action=profiel_bijwerken&id=' + encodeURIComponent(huidigProfielId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Opslaan mislukt.'); return; }
  alert('Profiel opgeslagen.');
  if (huidigProfielId === eigenUserId) location.reload(); // eigen naam kan in de topbalk staan, dus even verversen
}

init();
</script>
</body>
</html>
