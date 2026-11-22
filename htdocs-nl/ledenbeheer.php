<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isIT = heeft_vaste_it();   // vaste IT: ook accounts/IT-rollen beheren
$isITNu = heeft_rol(['admin']); // vaste of tijdelijke IT: kleuren e.d.
if (!heeft_rol(['admin', 'voorzitter'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Ledenbeheer — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="card">
    <div class="toolbar" style="margin-bottom:0;">
      <div>
        <strong>Ledenbeheer</strong>
        <p style="color:var(--grey); font-size:12.5px; margin:4px 0 0;">
          Iemand kan meerdere rollen tegelijk hebben (bijv. Voorzitter + IT + Coördinator Drukwerk).
          Vink hieronder aan/uit en klik "Opslaan" — de wijziging gaat direct in.
        </p>
      </div>
      <button class="primary" onclick="openNieuwLidForm()">+ Lid registreren</button>
    </div>
  </div>

  <!-- ===== Modal: nieuw lid registreren ===== -->
  <div id="modal-nieuw-lid" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-nieuw-lid')">
    <div class="modal">
      <button class="modal-close" onclick="sluitModal('modal-nieuw-lid')">✕</button>
      <h2>Nieuw lid registreren</h2>
      <label>Naam</label><input id="nl-naam" />
      <label>E-mailadres</label><input id="nl-email" type="email" />
      <label>Wachtwoord (leeg laten = automatisch gegenereerd)</label>
      <input id="nl-wachtwoord" placeholder="minimaal 8 tekens" />
      <p style="font-size:11.5px; color:var(--grey); margin-top:4px;">Er wordt automatisch een uitnodiging per e-mail verstuurd naar dit lid, met inloggegevens en een link naar de inlogpagina.</p>
      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="nieuwLidOpslaan()">Registreren &amp; uitnodigen</button>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:16px;">
    <table>
      <thead><tr><th>Naam</th><th>E-mailadres</th><th>Rollen</th><th></th></tr></thead>
      <tbody id="leden-body"><tr><td colspan="4">Laden…</td></tr></tbody>
    </table>
  </div>

  <?php if ($isIT): ?>
  <div class="card" id="it-aanvragen-kaart" style="margin-top:16px; display:none;">
    <strong>Tijdelijke IT-rechten</strong>
    <p style="color:var(--grey); font-size:12.5px; margin:4px 0 10px;">Leden kunnen tijdelijke IT-rechten aanvragen; jij krijgt daarvoor een e-mail met een link. Hier kun je ze ook goedkeuren, afwijzen of een lopende toekenning meteen beëindigen.</p>
    <div id="it-aanvragen-lijst"></div>
  </div>
  <?php endif; ?>

  <?php if ($isITNu): ?>
  <div class="card" style="margin-top:16px;">
    <strong>Kleuren</strong>
    <p style="color:var(--grey); font-size:12.5px; margin:4px 0 12px;">
      Elke rol en elk soort agendapunt (Voorlichting, District-vergadering, Area-vergadering) krijgt een eigen kleur in de agenda.
      Let op: de agenda-kleur van "Voorlichting" (het evenement zelf) is bewust los in te stellen van de kleur van de rol "PI Coördinator Voorlichting" —
      zo blijven de rol van de coördinator en de voorlichtingsafspraken zelf visueel uit elkaar te houden.
    </p>
    <div id="kleuren-lijst" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px;"></div>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="opslaanKleuren()">Kleuren opslaan</button>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
const isIT = <?= $isIT ? "true" : "false" ?>; // vaste IT: knoppen voor e-mail/wachtwoord
let ledenData = [];
let rollenLijst = {};
let kleurenData = {};
const kleurLabels = {
  admin: 'District-IT (beheerder)', voorzitter: 'PI Voorzitter', penningmeester: 'PI Penningmeester',
  secretaris: 'PI Secretaris', coordinator_drukwerk: 'PI Coördinator Drukwerk',
  coordinator_voorlichting: 'PI Coördinator Voorlichting', coordinator_literatuur: 'PI Coördinator Literatuur',
  lid: 'PI Lid (Liaison) — standaardkleur', voorlichting_event: 'Voorlichting (het evenement zelf)',
  district_vergadering: 'District-vergadering', area_vergadering: 'Area-vergadering',
};

async function laadKleuren() {
  const res = await fetch('api_leden.php?action=kleuren_list');
  const data = await res.json();
  kleurenData = data.kleuren;
  document.getElementById('kleuren-lijst').innerHTML = Object.entries(kleurLabels).map(([sleutel, label]) => `
    <label style="display:flex; align-items:center; gap:8px; font-size:13px; border:1px solid var(--border); border-radius:8px; padding:8px 10px;">
      <input type="color" data-kleursleutel="${sleutel}" value="${kleurenData[sleutel] || '#888888'}" style="width:36px; height:28px; padding:0; border:none;" />
      ${escapeHtml(label)}
    </label>`).join('');
}

async function opslaanKleuren() {
  const kleuren = {};
  document.querySelectorAll('[data-kleursleutel]').forEach(el => { kleuren[el.dataset.kleursleutel] = el.value; });
  await fetch('api_leden.php?action=kleuren_bijwerken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ kleuren }),
  });
  alert('Kleuren opgeslagen.');
}
// ---- Tijdelijke IT-rechten (alleen vaste IT) ----
async function laadITAanvragen() {
  const kaart = document.getElementById('it-aanvragen-kaart'); if (!kaart) return;
  const res = await fetch('api_it_tijdelijk.php?action=aanvragen_list', { cache: 'no-store' });
  if (!res.ok) return;
  const d = await res.json();
  const tijd = (ms) => new Date(ms).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
  kaart.style.display = d.aanvragen.length ? '' : 'none';
  document.getElementById('it-aanvragen-lijst').innerHTML = d.aanvragen.map(a => a.status === 'wacht' ? `
    <div style="padding:8px 0; border-top:1px solid #eef1ef;">
      <strong>${escapeHtml(a.naam)}</strong> vraagt tijdelijke IT-rechten aan${a.reden ? ': “' + escapeHtml(a.reden) + '”' : ''}
      <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
        ${d.keuzes.map(m => `<button class="secondary" onclick="itBesluit(${escapeHtml(JSON.stringify(a.id))}, 'goedkeuren', ${m})">✓ ${m} min</button>`).join('')}
        <button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="itBesluit(${escapeHtml(JSON.stringify(a.id))}, 'afwijzen', 0)">✕ Afwijzen</button>
      </div>
    </div>` : `
    <div style="padding:8px 0; border-top:1px solid #eef1ef; display:flex; justify-content:space-between; align-items:center;">
      <span><span class="badge groen">actief</span> <strong>${escapeHtml(a.naam)}</strong> tot ${tijd(a.geldigTot)}</span>
      <button class="secondary" onclick="itIntrekken(${escapeHtml(JSON.stringify(a.id))})">Nu beëindigen</button>
    </div>`).join('');
}
async function itBesluit(id, besluit, minuten) {
  const res = await fetch('api_it_tijdelijk.php?action=aanvraag_beslissen', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, besluit, minuten }) });
  const d = await res.json();
  if (!res.ok) alert(d.error || 'Mislukt.');
  laadITAanvragen();
}
async function itIntrekken(id) {
  await fetch('api_it_tijdelijk.php?action=aanvraag_intrekken_door_it', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
  laadITAanvragen();
}
laadITAanvragen();

if (document.getElementById('kleuren-lijst')) laadKleuren(); // kleuren zijn alleen voor IT

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

function sluitModal(id) { document.getElementById(id).style.display = 'none'; }

function openNieuwLidForm() {
  document.getElementById('nl-naam').value = '';
  document.getElementById('nl-email').value = '';
  document.getElementById('nl-wachtwoord').value = '';
  document.getElementById('modal-nieuw-lid').style.display = 'flex';
}

async function nieuwLidOpslaan() {
  const body = {
    naam: document.getElementById('nl-naam').value,
    email: document.getElementById('nl-email').value,
    wachtwoord: document.getElementById('nl-wachtwoord').value,
  };
  if (!body.naam.trim() || !body.email.trim()) { alert('Naam en e-mailadres zijn verplicht.'); return; }
  const res = await fetch('api_leden.php?action=gebruiker_aanmaken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Registreren mislukt.'); return; }
  sluitModal('modal-nieuw-lid');
  if (data.uitnodigingVerstuurd) {
    alert('Lid geregistreerd — uitnodiging met inloggegevens is verstuurd naar ' + body.email + '.');
  } else {
    alert('Lid geregistreerd, maar de uitnodigingsmail kon niet worden verstuurd (SMTP niet ingesteld of tijdelijk niet bereikbaar).\n\nGeef dit zelf even door:\nWachtwoord: ' + data.wachtwoord);
  }
  laadLeden();
}

async function laadLeden() {
  const res = await fetch('api_leden.php?action=leden_list');
  const data = await res.json();
  ledenData = data.leden;
  rollenLijst = data.rollen;

  document.getElementById('leden-body').innerHTML = ledenData.map(l => `
    <tr>
      <td>${escapeHtml(l.naam)}</td>
      <td>${escapeHtml(l.email)}</td>
      <td>
        <div style="display:flex; flex-wrap:wrap; gap:6px 14px;">
          ${Object.entries(rollenLijst).map(([sleutel, label]) => `
            <label style="display:flex; align-items:center; gap:4px; font-size:12.5px; margin:0;">
              <input type="checkbox" style="width:auto;" data-lid="${escapeHtml(l.id)}" value="${sleutel}" ${l.roles.includes(sleutel) ? 'checked' : ''} ${l.vergrendeld ? 'disabled' : ''} />
              ${escapeHtml(label)}
            </label>
          `).join('')}
        </div>
      </td>
      <td>${l.vergrendeld ? '<span class="badge grijs" title="Alleen de IT-beheerder kan dit account wijzigen">IT-beheerder</span> ' : `<button class="primary" onclick="opslaanRollen(${escapeHtml(JSON.stringify(l.id))})">Opslaan</button> `}<a class="btn-secondary" href="profiel.php?id=${encodeURIComponent(l.id)}" style="display:inline-block;">Profiel</a> ${isIT ? `<button class="secondary" onclick="emailWijzigen(${escapeHtml(JSON.stringify(l.id))}, ${escapeHtml(JSON.stringify(l.email))})" title="Inlog-e-mailadres wijzigen">✉</button> <button class="secondary" onclick="wachtwoordVervangen(${escapeHtml(JSON.stringify(l.id))}, ${escapeHtml(JSON.stringify(l.naam))})" title="Nieuw tijdelijk wachtwoord instellen">🔑</button> ` : ''}${l.vergrendeld ? '' : `<button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="gebruikerVerwijderen(${escapeHtml(JSON.stringify(l.id))}, ${escapeHtml(JSON.stringify(l.naam))})">✕</button>`}</td>
    </tr>`).join('') || '<tr><td colspan="4">Nog geen leden.</td></tr>';
}

async function emailWijzigen(id, huidig) {
  const nieuw = prompt('Nieuw inlog-e-mailadres voor dit account:\n(profiel, rollen en geschiedenis blijven gekoppeld)', huidig);
  if (nieuw === null || nieuw.trim() === '' || nieuw.trim().toLowerCase() === huidig) return;
  const res = await fetch('api_leden.php?action=email_bijwerken', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, email: nieuw }) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Wijzigen mislukt.'); return; }
  alert('E-mailadres gewijzigd naar ' + data.email + '. Inloggen kan nu met dit adres.');
  laadLeden();
}

async function wachtwoordVervangen(id, naam) {
  if (!confirm(`Een nieuw tijdelijk wachtwoord instellen voor "${naam}"? Het oude wachtwoord werkt dan niet meer.`)) return;
  const res = await fetch('api_leden.php?action=wachtwoord_vervangen', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Instellen mislukt.'); return; }
  prompt(`Nieuw tijdelijk wachtwoord voor ${naam} (${data.verstuurd ? 'ook per e-mail verstuurd' : 'niet gemaild: geef het zelf door'}). Kopieer het nu, het wordt niet nog eens getoond:`, data.wachtwoord);
}

async function gebruikerVerwijderen(id, naam) {
  if (!confirm(`Het account en profiel van "${naam}" volledig verwijderen? Dit kan niet ongedaan gemaakt worden — diegene kan dan niet meer inloggen.`)) return;
  const res = await fetch('api_leden.php?action=gebruiker_verwijderen&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Verwijderen mislukt.'); return; }
  laadLeden();
}

async function opslaanRollen(ledId) {
  const checkboxes = document.querySelectorAll(`input[data-lid="${ledId}"]`);
  const roles = [...checkboxes].filter(cb => cb.checked).map(cb => cb.value);

  const res = await fetch('api_leden.php?action=rollen_bijwerken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: ledId, roles }),
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Opslaan mislukt.'); laadLeden(); return; }
  alert('Rollen opgeslagen.');
  laadLeden();
}

laadLeden();
</script>
</body>
</html>
