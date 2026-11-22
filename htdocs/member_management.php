<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isIT = heeft_vaste_it();   // permanent IT: also manage accounts/IT roles
$isITNu = heeft_rol(['admin']); // permanent or temporary IT: colours etc.
if (!heeft_rol(['admin', 'chair'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Member Management — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="card">
    <div class="toolbar" style="margin-bottom:0;">
      <div>
        <strong>Member Management</strong>
        <p style="color:var(--grey); font-size:12.5px; margin:4px 0 0;">
          Someone can hold multiple roles at once (e.g. Chairperson + IT + Print Distribution Coordinator).
          Tick/untick below and click "Save" — the change takes effect immediately.
        </p>
      </div>
      <button class="primary" onclick="openNieuwLidForm()">+ Register member</button>
    </div>
  </div>

  <!-- ===== Modal: register new member ===== -->
  <div id="modal-nieuw-lid" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-nieuw-lid')">
    <div class="modal">
      <button class="modal-close" onclick="sluitModal('modal-nieuw-lid')">✕</button>
      <h2>Register new member</h2>
      <label>Name</label><input id="nl-naam" />
      <label>Email address</label><input id="nl-email" type="email" />
      <label>Password (leave blank = generated automatically)</label>
      <input id="nl-wachtwoord" placeholder="minimum 8 characters" />
      <p style="font-size:11.5px; color:var(--grey); margin-top:4px;">An invitation will automatically be emailed to this member, with login details and a link to the login page.</p>
      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="nieuwLidOpslaan()">Register &amp; invite</button>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:16px;">
    <table>
      <thead><tr><th>Name</th><th>Email address</th><th>Roles</th><th></th></tr></thead>
      <tbody id="leden-body"><tr><td colspan="4">Loading…</td></tr></tbody>
    </table>
  </div>

  <?php if ($isIT): ?>
  <div class="card" id="it-aanvragen-kaart" style="margin-top:16px; display:none;">
    <strong>Temporary IT rights</strong>
    <p style="color:var(--grey); font-size:12.5px; margin:4px 0 10px;">Members can request temporary IT rights; you receive an email with a link for this. Here you can also approve or reject them, or end a running grant immediately.</p>
    <div id="it-aanvragen-lijst"></div>
  </div>
  <?php endif; ?>

  <?php if ($isITNu): ?>
  <div class="card" style="margin-top:16px;">
    <strong>Colours</strong>
    <p style="color:var(--grey); font-size:12.5px; margin:4px 0 12px;">
      Every role and every kind of calendar item (Outreach, District meeting, Area meeting) gets its own colour in the calendar.
      Note: the calendar colour for "Outreach" (the appointment itself) is deliberately set separately from the colour of the "PI Presentation Outreach Coordinator" role —
      this keeps the coordinator's role and the actual outreach appointments visually distinct from each other.
    </p>
    <div id="kleuren-lijst" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px;"></div>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="opslaanKleuren()">Save colours</button>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
const isIT = <?= $isIT ? "true" : "false" ?>; // permanent IT: email/password buttons
let ledenData = [];
let rollenLijst = {};
let kleurenData = {};
const kleurLabels = {
  admin: 'District IT (administrator)', chair: 'PI Committee Chairperson', treasurer: 'PI Treasurer',
  secretary: 'PI Secretary', coordinator_distribution: 'PI Print Distribution Coordinator',
  coordinator_outreach: 'PI Presentation Outreach Coordinator', coordinator_literature: 'PI Literature Coordinator',
  media_coordinator: 'PI Media Coordinator', social_media_coordinator: 'PI Social Media Coordinator',
  liaison: 'Group PI Liaison — default colour', voorlichting_event: 'Outreach (the appointment itself)',
  district_vergadering: 'District meeting', area_vergadering: 'Area meeting',
};

async function laadKleuren() {
  const res = await fetch('api_members.php?action=kleuren_list');
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
  await fetch('api_members.php?action=kleuren_bijwerken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ kleuren }),
  });
  alert('Colours saved.');
}
// ---- Temporary IT rights (permanent IT only) ----
async function laadITAanvragen() {
  const kaart = document.getElementById('it-aanvragen-kaart'); if (!kaart) return;
  const res = await fetch('api_it_tijdelijk.php?action=aanvragen_list', { cache: 'no-store' });
  if (!res.ok) return;
  const d = await res.json();
  const tijd = (ms) => new Date(ms).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  kaart.style.display = d.aanvragen.length ? '' : 'none';
  document.getElementById('it-aanvragen-lijst').innerHTML = d.aanvragen.map(a => a.status === 'wacht' ? `
    <div style="padding:8px 0; border-top:1px solid #eef1ef;">
      <strong>${escapeHtml(a.naam)}</strong> is requesting temporary IT rights${a.reden ? ': “' + escapeHtml(a.reden) + '”' : ''}
      <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
        ${d.keuzes.map(m => `<button class="secondary" onclick="itBesluit(${escapeHtml(JSON.stringify(a.id))}, 'goedkeuren', ${m})">✓ ${m} min</button>`).join('')}
        <button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="itBesluit(${escapeHtml(JSON.stringify(a.id))}, 'afwijzen', 0)">✕ Reject</button>
      </div>
    </div>` : `
    <div style="padding:8px 0; border-top:1px solid #eef1ef; display:flex; justify-content:space-between; align-items:center;">
      <span><span class="badge groen">active</span> <strong>${escapeHtml(a.naam)}</strong> until ${tijd(a.geldigTot)}</span>
      <button class="secondary" onclick="itIntrekken(${escapeHtml(JSON.stringify(a.id))})">End now</button>
    </div>`).join('');
}
async function itBesluit(id, besluit, minuten) {
  const res = await fetch('api_it_tijdelijk.php?action=aanvraag_beslissen', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, besluit, minuten }) });
  const d = await res.json();
  if (!res.ok) alert(d.error || 'Failed.');
  laadITAanvragen();
}
async function itIntrekken(id) {
  await fetch('api_it_tijdelijk.php?action=aanvraag_intrekken_door_it', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
  laadITAanvragen();
}
laadITAanvragen();

if (document.getElementById('kleuren-lijst')) laadKleuren(); // colours are IT only

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
  if (!body.naam.trim() || !body.email.trim()) { alert('Name and email address are required.'); return; }
  const res = await fetch('api_members.php?action=gebruiker_aanmaken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Registration failed.'); return; }
  sluitModal('modal-nieuw-lid');
  if (data.uitnodigingVerstuurd) {
    alert('Member registered — an invitation with login details has been sent to ' + body.email + '.');
  } else {
    alert('Member registered, but the invitation email could not be sent (SMTP not configured, or temporarily unreachable).\n\nPlease pass this on yourself:\nPassword: ' + data.wachtwoord);
  }
  laadLeden();
}

async function laadLeden() {
  const res = await fetch('api_members.php?action=leden_list');
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
      <td>${l.vergrendeld ? '<span class="badge grijs" title="Only the IT administrator can change this account">IT administrator</span> ' : `<button class="primary" onclick="opslaanRollen(${escapeHtml(JSON.stringify(l.id))})">Save</button> `}<a class="btn-secondary" href="profile.php?id=${encodeURIComponent(l.id)}" style="display:inline-block;">Profile</a> ${isIT ? `<button class="secondary" onclick="emailWijzigen(${escapeHtml(JSON.stringify(l.id))}, ${escapeHtml(JSON.stringify(l.email))})" title="Change login email address">✉</button> <button class="secondary" onclick="wachtwoordVervangen(${escapeHtml(JSON.stringify(l.id))}, ${escapeHtml(JSON.stringify(l.naam))})" title="Set a new temporary password">🔑</button> ` : ''}${l.vergrendeld ? '' : `<button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="gebruikerVerwijderen(${escapeHtml(JSON.stringify(l.id))}, ${escapeHtml(JSON.stringify(l.naam))})">✕</button>`}</td>
    </tr>`).join('') || '<tr><td colspan="4">No members yet.</td></tr>';
}

async function emailWijzigen(id, huidig) {
  const nieuw = prompt('New login email address for this account:\n(profile, roles and history stay linked)', huidig);
  if (nieuw === null || nieuw.trim() === '' || nieuw.trim().toLowerCase() === huidig) return;
  const res = await fetch('api_members.php?action=email_bijwerken', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, email: nieuw }) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Change failed.'); return; }
  alert('Email address changed to ' + data.email + '. You can now log in with this address.');
  laadLeden();
}

async function wachtwoordVervangen(id, naam) {
  if (!confirm(`Set a new temporary password for "${naam}"? The old password will stop working.`)) return;
  const res = await fetch('api_members.php?action=wachtwoord_vervangen', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id }) });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Could not set password.'); return; }
  prompt(`New temporary password for ${naam} (${data.verstuurd ? 'also sent by email' : 'not emailed: pass it on yourself'}). Copy it now, it will not be shown again:`, data.wachtwoord);
}

async function gebruikerVerwijderen(id, naam) {
  if (!confirm(`Completely delete the account and profile of "${naam}"? This cannot be undone — that person will no longer be able to log in.`)) return;
  const res = await fetch('api_members.php?action=gebruiker_verwijderen&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Deletion failed.'); return; }
  laadLeden();
}

async function opslaanRollen(ledId) {
  const checkboxes = document.querySelectorAll(`input[data-lid="${ledId}"]`);
  const roles = [...checkboxes].filter(cb => cb.checked).map(cb => cb.value);

  const res = await fetch('api_members.php?action=rollen_bijwerken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: ledId, roles }),
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Saving failed.'); laadLeden(); return; }
  alert('Roles saved.');
  laadLeden();
}

laadLeden();
</script>
</body>
</html>
