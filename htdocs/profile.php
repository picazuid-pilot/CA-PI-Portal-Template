<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$magAnderenBewerken = heeft_rol(['admin', 'secretary']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Profile — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <?php if ($magAnderenBewerken): ?>
  <div class="card">
    <strong>View/edit the profile of</strong>
    <div class="form-grid" style="margin-top:8px;">
      <div><select id="p-ledenkiezer" onchange="ledenKiezerGewijzigd()" style="max-width:320px;"></select></div>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:6px 0 0;">As Secretary/IT you can also update other members' profiles here (e.g. correcting contact details).</p>
  </div>
  <?php endif; ?>

  <div class="card" style="margin-top:16px;">
    <strong id="p-titel">My profile</strong>

    <div style="display:flex; align-items:center; gap:16px; margin-top:12px;">
      <div id="p-foto-vak" style="width:88px; height:88px; border-radius:50%; background:var(--primary-light-95); overflow:hidden; flex:0 0 auto; display:flex; align-items:center; justify-content:center; color:var(--grey); font-size:11px; text-align:center;">no photo</div>
      <div>
        <input type="file" id="p-foto-input" accept=".jpg,.jpeg,.png,.webp" style="display:none;" onchange="fotoUploaden()" />
        <button id="p-foto-knop" class="secondary" onclick="document.getElementById('p-foto-input').click()">📷 Upload/replace photo</button>
        <p style="font-size:11px; color:var(--grey); margin:6px 0 0;">JPG, PNG, or WEBP, max 5 MB.</p>
      </div>
    </div>

    <label style="margin-top:10px;">Name</label><input id="p-naam" />
    <label>Email address (login, cannot be changed)</label><input id="p-email" disabled style="background:var(--primary-light-95);" />
    <div class="form-grid">
      <div><label>Phone</label><input id="p-telefoon" /></div>
      <div><label>Emergency contact (name + phone, optional)</label><input id="p-noodcontact" /></div>
    </div>
    <label>Address (optional)</label><input id="p-adres" />
    <label>Region / coverage area (optional — e.g. "Downtown Rotterdam") <span style="font-weight:normal; color:var(--grey); font-size:11px;">— shown with a circle on the members map</span></label>
    <input id="p-regio" placeholder="e.g. Downtown Rotterdam" />
    <label>Note (optional — e.g. availability, special circumstances)</label>
    <textarea id="p-notitie" rows="3"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button id="p-opslaan-knop" class="primary" onclick="profielOpslaan()">Save</button>
    </div>
  </div>

</div>

<script>
const magAnderenBewerken = <?= $magAnderenBewerken ? 'true' : 'false' ?>;
const eigenUserId = <?= json_encode($_SESSION['user_id']) ?>;
let huidigProfielId = eigenUserId;

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

async function init() {
  const paramId = new URLSearchParams(location.search).get('id');
  if (magAnderenBewerken) {
    const res = await fetch('api_members.php?action=leden_lijst_kiezer', { cache: 'no-store' });
    const data = await res.json();
    const select = document.getElementById('p-ledenkiezer');
    select.innerHTML = data.leden.map(l => `<option value="${l.id}" ${l.id === eigenUserId ? 'selected' : ''}>${escapeHtml(l.naam)}${l.id === eigenUserId ? ' (yourself)' : ''}</option>`).join('');
    if (paramId) { select.value = paramId; await laadProfiel(paramId); return; }
  } else if (paramId) {
    // A regular member clicking through to someone else from the member
    // directory — viewing is allowed, editing gets blocked/hidden by the backend.
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
  const res = await fetch('api_members.php?action=profiel_get&id=' + encodeURIComponent(id), { cache: 'no-store' });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Could not load profile.'); return; }
  const p = data.profiel;
  document.getElementById('p-titel').textContent = id === eigenUserId ? 'My profile' : 'Profile — ' + p.naam;
  document.getElementById('p-naam').value = p.naam;
  document.getElementById('p-email').value = p.email;
  document.getElementById('p-telefoon').value = p.telefoon;
  document.getElementById('p-adres').value = p.adres;
  document.getElementById('p-regio').value = p.regio;
  document.getElementById('p-noodcontact').value = p.noodcontact;
  document.getElementById('p-notitie').value = p.notitie;
  document.getElementById('p-foto-vak').innerHTML = p.profielfoto
    ? `<img src="api_members.php?action=profielfoto_stream&bestand=${encodeURIComponent(p.profielfoto)}" style="width:100%; height:100%; object-fit:cover;" />`
    : 'no photo';

  // If you're not allowed to edit: show everything read-only, no save/photo button.
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
  const res = await fetch('api_members.php?action=profielfoto_upload&id=' + encodeURIComponent(huidigProfielId), { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  inputEl.value = '';
  if (!res.ok) { alert(data.error || 'Upload failed.'); return; }
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
  const res = await fetch('api_members.php?action=profiel_bijwerken&id=' + encodeURIComponent(huidigProfielId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Save failed.'); return; }
  alert('Profile saved.');
  if (huidigProfielId === eigenUserId) location.reload(); // own name may appear in the top bar, so refresh it
}

init();
</script>
</body>
</html>
