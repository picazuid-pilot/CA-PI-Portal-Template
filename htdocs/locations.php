<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>PI Locations — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/papaparse@5.4.1/papaparse.min.js"></script>
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <div style="display:flex; gap:6px;">
      <button class="secondary" data-view="lijst" onclick="toonView('lijst')">Customer directory</button>
      <button class="secondary" data-view="bulk" onclick="toonView('bulk')">Bulk import</button>
      <button class="secondary" data-view="campagnes" onclick="toonView('campagnes')">Campaigns</button>
      <button class="secondary" data-view="targets" onclick="toonView('targets')">Targets</button>
      <button class="secondary" data-view="campagne_ideeen" onclick="toonView('campagne_ideeen')">Campaign ideas</button>
    </div>
    <div class="right">
      <input id="zoek" placeholder="Search by name or city…" style="width:220px;" oninput="renderLijst()" />
      <select id="sorteer" onchange="renderLijst()" style="width:auto;">
        <option value="instantie-az">Institution A-Z</option>
        <option value="instantie-za">Institution Z-A</option>
        <option value="plaats-az">City A-Z</option>
        <option value="plaats-za">City Z-A</option>
        <option value="toegevoegd-nieuw">Date added (newest first)</option>
        <option value="toegevoegd-oud">Date added (oldest first)</option>
        <option value="activiteit-nieuw">Last activity (newest first)</option>
        <option value="activiteit-oud">Last activity (oldest first)</option>
        <option value="activiteiten-hoog">Activities high-low</option>
        <option value="activiteiten-laag">Activities low-high</option>
      </select>
      <?php if (heeft_rol(['admin'])): ?>
      <button class="secondary" onclick="checkDuplicaten()">🧹 Clean up duplicates</button>
      <?php endif; ?>
      <button class="primary" onclick="openNieuweLocatie()">+ New location</button>
    </div>
  </div>

  <!-- ===== Customer directory ===== -->
  <div id="view-lijst" class="card">
    <table>
      <thead>
        <tr><th></th><th>Institution</th><th>City</th><th>Contact person</th><th>Last activity</th><th># activities</th></tr>
      </thead>
      <tbody id="lijst-body"><tr><td colspan="6">Loading…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Bulk import ===== -->
  <div id="view-bulk" class="card" style="display:none;">
    <h3 style="margin-top:0;">Bulk-import locations</h3>
    <p style="color:var(--grey); font-size:13.5px;">
      First download the CSV template, fill it in (Excel/Google Sheets), and upload it below.
      Existing locations are not recognised/merged — so use this for new locations only.
    </p>
    <a class="btn-primary" href="templates/locaties_bulk_sjabloon.csv" download style="margin-bottom:14px; display:inline-block;">⬇ Download CSV template</a>
    <div>
      <input type="file" id="csvInput" accept=".csv" />
    </div>
    <div id="bulk-preview" style="margin-top:14px;"></div>
  </div>

  <!-- ===== Campaigns ===== -->
  <div id="view-campagnes" class="card" style="display:none;">
    <div class="toolbar" style="margin-bottom:0;">
      <strong>Campaigns — Physical</strong>
      <button class="primary" onclick="openNieuweCampagne('fysiek')">+ New campaign (physical)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Print, press, media — approved by the Media Coordinator.</p>
    <table>
      <thead><tr><th>Campaign</th><th>Date</th><th>Channel</th><th># participants</th><th>Status</th></tr></thead>
      <tbody id="campagnes-body-fysiek"><tr><td colspan="5">Loading…</td></tr></tbody>
    </table>

    <div class="toolbar" style="margin-top:26px; margin-bottom:0;">
      <strong>Campaigns — Online</strong>
      <button class="primary" onclick="openNieuweCampagne('online')">+ New campaign (online)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Social media, Laposta, etc. — approved by the Social Media Coordinator.</p>
    <table>
      <thead><tr><th>Campaign</th><th>Date</th><th>Channel</th><th># participants</th><th>Status</th></tr></thead>
      <tbody id="campagnes-body-online"><tr><td colspan="5">Loading…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Modal: reject campaign ===== -->
  <div id="modal-campagne-afwijzen" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-campagne-afwijzen')">
    <div class="modal">
      <button class="modal-close" onclick="sluitModal('modal-campagne-afwijzen')">✕</button>
      <h2>Reject campaign</h2>
      <label>Note (optional, goes to the submitter)</label>
      <textarea id="ca-opmerking" rows="2"></textarea>
      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="campagneAfwijzenBevestigen()">Confirm rejection</button>
      </div>
    </div>
  </div>

  <!-- ===== Targets (institutions for media campaigns) ===== -->
  <div id="view-targets" class="card" style="display:none;">
    <div class="toolbar" style="margin-bottom:0;">
      <strong>Targets — Physical</strong>
      <button class="primary" onclick="openTargetForm('fysiek')">+ Add target (physical)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Print/media institutions (e.g. a local newspaper).</p>
    <table>
      <thead><tr><th>Name</th><th>Description</th><th></th></tr></thead>
      <tbody id="targets-body-fysiek"><tr><td colspan="3">Loading…</td></tr></tbody>
    </table>

    <div class="toolbar" style="margin-top:26px; margin-bottom:0;">
      <strong>Targets — Online</strong>
      <button class="primary" onclick="openTargetForm('online')">+ Add target (online)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Social media channels/accounts.</p>
    <table>
      <thead><tr><th>Name</th><th>Description</th><th></th></tr></thead>
      <tbody id="targets-body-online"><tr><td colspan="3">Loading…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Modal: add/edit target ===== -->
  <div id="modal-target" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-target')">
    <div class="modal">
      <button class="modal-close" onclick="sluitModal('modal-target')">✕</button>
      <h2 id="target-titel-kop">Add target</h2>
      <label>Name</label><input id="tg-naam" />
      <label>Description</label><textarea id="tg-omschrijving" rows="3"></textarea>
      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="targetOpslaan()">Save</button>
      </div>
    </div>
  </div>

  <!-- ===== Campaign ideas ===== -->
  <div id="view-campagne_ideeen" class="card" style="display:none;">
    <div class="toolbar" style="margin-bottom:0;">
      <strong>Campaign ideas — Physical</strong>
      <button class="primary" onclick="openIdeeForm('fysiek')">+ Submit idea (physical)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Carried out by the Media Coordinator.</p>
    <div id="ideeen-lijst-fysiek"></div>

    <div class="toolbar" style="margin-top:26px; margin-bottom:0;">
      <strong>Campaign ideas — Online</strong>
      <button class="primary" onclick="openIdeeForm('online')">+ Submit idea (online)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Carried out by the Social Media Coordinator.</p>
    <div id="ideeen-lijst-online"></div>
  </div>

  <!-- ===== Modal: submit/edit campaign idea ===== -->
  <div id="modal-idee" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-idee')">
    <div class="modal" style="max-width:640px;">
      <button class="modal-close" onclick="sluitModal('modal-idee')">✕</button>
      <h2 id="idee-titel-kop">Submit campaign idea</h2>

      <label>Plan (e.g. "Submit an ad and/or article to the local newspaper")</label>
      <input id="id-titel" />

      <label>Target (institution, optional)</label>
      <div class="form-grid">
        <div><select id="id-target-select" onchange="targetSelectGewijzigd()"><option value="">— choose an existing target —</option></select></div>
        <div><input id="id-target-vrij" placeholder="Or type a name (no target yet)" /></div>
      </div>

      <div class="form-grid" style="margin-top:8px;">
        <div><label>Deadline for submission (optional)</label><input id="id-deadline" type="date" /></div>
        <div><label>Date of first contact (optional)</label><input id="id-eerstecontact" type="date" /></div>
      </div>
      <label style="display:flex; align-items:center; gap:6px; margin-top:8px;">
        <input type="checkbox" id="id-benaderd" style="width:auto;" /> Already contacted
      </label>

      <label style="margin-top:10px;">Materials (ad concept, letter, article, correspondence — multiple allowed)</label>
      <input type="file" id="id-materiaal-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt,.eml,.msg" style="display:none;" onchange="materiaalUploaden()" />
      <button type="button" class="secondary" onclick="document.getElementById('id-materiaal-input').click()">+ Add material</button>
      <div id="id-materiaal-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
      <div id="id-materialen-lijst" style="margin-top:6px;"></div>

      <label style="margin-top:10px;">Follow-up actions</label>
      <div id="id-vervolgacties-lijst"></div>
      <button type="button" class="secondary" onclick="voegVervolgactieToe()">+ Add follow-up action</button>

      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="ideeOpslaan()">Save</button>
      </div>
    </div>
  </div>

</div>

<!-- ===== Modal: location detail / edit ===== -->
<div id="modal-locatie" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-locatie')">
  <div class="modal" style="max-width:680px;">
    <button class="modal-close" onclick="sluitModal('modal-locatie')">✕</button>
    <h2 id="loc-titel">New location</h2>

    <div class="form-grid">
      <div class="full"><label>Institution name *</label><input id="f-naam" /></div>
      <div><label>Type</label><input id="f-type" placeholder="e.g. GP practice" /></div>
      <div><label>City</label><input id="f-plaats" /></div>
      <div class="full"><label>Address</label><input id="f-adres" /></div>
      <div><label>Postal code</label><input id="f-postcode" /></div>
      <div><label>Coordinates (for the map)</label>
        <div style="display:flex; gap:6px;">
          <input id="f-lat" placeholder="lat" />
          <input id="f-lng" placeholder="lng" />
          <button type="button" class="secondary" style="white-space:nowrap; padding:8px 10px;" onclick="zoekCoordinaten()">📍 Search</button>
        </div>
        <div id="geocode-status" style="font-size:11.5px; color:var(--grey); margin-top:3px;"></div>
      </div>

      <div class="full"><hr style="border:none; border-top:1px solid var(--border); margin:14px 0;"></div>
      <div><label>Reception phone number</label><input id="f-receptieTelefoon" /></div>
      <div><label>General email address</label><input id="f-algemeenEmail" /></div>
      <div><label>Contact person name</label><input id="f-contactpersoonNaam" /></div>
      <div><label>Contact person phone</label><input id="f-contactpersoonTelefoon" /></div>
      <div class="full"><label>Contact person email</label><input id="f-contactpersoonEmail" /></div>

      <div class="full"><hr style="border:none; border-top:1px solid var(--border); margin:14px 0;"></div>
      <div class="full">
        <div class="checkbox-row"><input type="checkbox" id="f-flyersToegestaan" /><label style="margin:0;">Flyers allowed</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-posterToegestaan" /><label style="margin:0;">Poster allowed</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-voorlichtingPersoneelInteresse" /><label style="margin:0;">Interested in a staff presentation</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-folderClientenInteresse" /><label style="margin:0;">Interested in client information leaflets</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-infoPakketGeleverd" /><label style="margin:0;">Has received a C.A. info pack (front desk/mailbox or in person)</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-heeftFolderrek" /><label style="margin:0;">Already has a leaflet rack</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-wilFolderrek" /><label style="margin:0;">Wants a leaflet rack</label></div>
        <div class="checkbox-row">
          <input type="checkbox" id="f-contactOnderhoudGewenst" /><label style="margin:0;">Wants regular follow-up contact</label>
          <select id="f-contactOnderhoudMethode" style="width:auto; margin-left:8px;">
            <option value="">method…</option><option value="email">email</option><option value="telefoon">phone</option>
          </select>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" id="f-schermAanwezig" /><label style="margin:0;">Has a screen (e.g. waiting room)</label>
          <select id="f-schermOrientatie" style="width:auto; margin-left:8px;">
            <option value="">orientation…</option><option value="horizontaal">landscape</option><option value="verticaal">portrait</option>
          </select>
        </div>
        <div class="checkbox-row"><input type="checkbox" id="f-schermAnimatieInteresse" /><label style="margin:0;">Interested in on-screen animation</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-isInrichting" /><label style="margin:0;">This is a treatment/residential facility</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-heeftClientenBibliotheek" /><label style="margin:0;">Has a client library</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-literatuurBehoefteCA_AA" /><label style="margin:0;">Needs C.A./A.A. literature</label></div>
      </div>

      <div class="full"><label>Note</label><textarea id="f-notitie" rows="2"></textarea></div>
    </div>

    <div style="margin-top:16px; display:flex; gap:8px; justify-content:space-between;">
      <button id="btn-verwijder-locatie" class="secondary" style="display:none; color:#c9432f; border-color:#c9432f;" onclick="verwijderLocatie()">Delete</button>
      <div style="display:flex; gap:8px; margin-left:auto;">
        <button class="secondary" onclick="sluitModal('modal-locatie')">Cancel</button>
        <button class="primary" onclick="opslaanLocatie()">Save</button>
      </div>
    </div>

    <div id="detail-activiteiten" style="display:none; margin-top:22px; border-top:1px solid var(--border); padding-top:16px;">
      <div class="toolbar" style="margin-bottom:6px;">
        <strong>Activity timeline</strong>
        <button class="secondary" onclick="toonActiviteitForm()">+ Add activity</button>
      </div>
      <div id="activiteit-form" style="display:none; background:var(--primary-light-95); border-radius:8px; padding:12px; margin-bottom:12px;">
        <div class="form-grid">
          <div><label>Date</label><input type="date" id="a-datum" /></div>
          <div><label>Type</label>
            <select id="a-type">
              <option value="bezoek">Visit</option>
              <option value="folders_bijgevuld">Leaflets restocked</option>
              <option value="poster_gecontroleerd">Poster checked</option>
              <option value="telefonisch_contact">Phone contact</option>
              <option value="voorlichting_gegeven">Presentation given</option>
              <option value="wijziging_gesignaleerd">Change noticed (e.g. new screen/staff)</option>
              <option value="anders">Other</option>
            </select>
          </div>
          <div class="full"><label>Description</label><textarea id="a-omschrijving" rows="2"></textarea></div>
        </div>
        <div style="text-align:right; margin-top:8px;"><button class="primary" onclick="opslaanActiviteit()">Add</button></div>
      </div>
      <div id="activiteiten-lijst"></div>
    </div>
  </div>
</div>

<!-- ===== Modal: new campaign ===== -->
<div id="modal-campagne" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-campagne')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-campagne')">✕</button>
    <h2>New campaign</h2>
    <label>Name</label><input id="c-naam" />
    <label>Date</label><input id="c-datum" type="date" />
    <label>Channel</label><input id="c-kanaal" value="Laposta" />
    <label>Description</label><textarea id="c-omschrijving" rows="2"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="opslaanCampagne()">Create</button>
    </div>
  </div>
</div>

<script>
const isAdmin = <?= heeft_rol(['admin']) ? 'true' : 'false' ?>;
const magFysiekGoedkeuren = <?= heeft_rol(['admin', 'media_coordinator']) ? 'true' : 'false' ?>;
const magOnlineGoedkeuren = <?= heeft_rol(['admin', 'social_media_coordinator']) ? 'true' : 'false' ?>;
let locaties = [];
let huidigeLocatieId = null;

function toonView(v) {
  ['lijst','bulk','campagnes','targets','campagne_ideeen'].forEach(x => document.getElementById('view-' + x).style.display = x === v ? '' : 'none');
  if (v === 'campagnes') laadCampagnes();
  if (v === 'targets') laadTargets();
  if (v === 'campagne_ideeen') laadIdeeen();
}

function sluitModal(id) { document.getElementById(id).style.display = 'none'; }

// ---- Customer directory ----------------------------------------------------

async function laadLocaties() {
  const res = await fetch('api_locations.php?action=list');
  const data = await res.json();
  locaties = data.locaties;
  renderLijst();
}

function renderLijst() {
  const zoek = document.getElementById('zoek').value.toLowerCase();
  const sortering = document.getElementById('sorteer').value;
  const sorteerFuncties = {
    'instantie-az': (a, b) => a.naam.localeCompare(b.naam),
    'instantie-za': (a, b) => b.naam.localeCompare(a.naam),
    'plaats-az': (a, b) => (a.plaats || '').localeCompare(b.plaats || ''),
    'plaats-za': (a, b) => (b.plaats || '').localeCompare(a.plaats || ''),
    'toegevoegd-nieuw': (a, b) => (b.createdAt || 0) - (a.createdAt || 0),
    'toegevoegd-oud': (a, b) => (a.createdAt || 0) - (b.createdAt || 0),
    'activiteit-nieuw': (a, b) => (b.laatsteActiviteit || '').localeCompare(a.laatsteActiviteit || ''),
    'activiteit-oud': (a, b) => (a.laatsteActiviteit || '9999-99-99').localeCompare(b.laatsteActiviteit || '9999-99-99'),
    'activiteiten-hoog': (a, b) => (b.aantalActiviteiten || 0) - (a.aantalActiviteiten || 0),
    'activiteiten-laag': (a, b) => (a.aantalActiviteiten || 0) - (b.aantalActiviteiten || 0),
  };
  const rows = locaties
    .filter(l => !zoek || l.naam.toLowerCase().includes(zoek) || (l.plaats || '').toLowerCase().includes(zoek))
    .sort(sorteerFuncties[sortering] || sorteerFuncties['instantie-az'])
    .map(l => `
      <tr onclick="openLocatie('${l.id}')">
        <td><span class="status-dot ${l.statusKleur}"></span></td>
        <td>${escapeHtml(l.naam)}</td>
        <td>${escapeHtml(l.plaats || '')}</td>
        <td>${escapeHtml(l.contact.contactpersoonNaam || '—')}</td>
        <td>${l.laatsteActiviteit ? formatDatum(l.laatsteActiviteit) + ' (' + l.dagenSindsLaatsteActiviteit + 'd)' : '<span class="badge grijs">none yet</span>'}</td>
        <td>${l.aantalActiviteiten}</td>
      </tr>`).join('');
  document.getElementById('lijst-body').innerHTML = rows || '<tr><td colspan="6">No locations found.</td></tr>';
}

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

let datumformaatVolgorde = 'dmy'; // 'dmy' | 'mdy' | 'ymd' — loaded via laadInstellingenLocations()
function formatDatum(iso) {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yy = d.getFullYear();
  if (datumformaatVolgorde === 'mdy') return `${mm}-${dd}-${yy}`;
  if (datumformaatVolgorde === 'ymd') return `${yy}-${mm}-${dd}`;
  return `${dd}-${mm}-${yy}`;
}
async function laadInstellingenLocations() {
  const res = await fetch('api_members.php?action=instellingen_get', { cache: 'no-store' });
  const data = await res.json();
  datumformaatVolgorde = data.instellingen.datumformaat || 'dmy';
}

function leegFormulier() {
  ['naam','type','plaats','adres','postcode','lat','lng','receptieTelefoon','algemeenEmail',
   'contactpersoonNaam','contactpersoonTelefoon','contactpersoonEmail','notitie'].forEach(id => document.getElementById('f-' + id).value = '');
  ['flyersToegestaan','posterToegestaan','voorlichtingPersoneelInteresse','folderClientenInteresse','infoPakketGeleverd','heeftFolderrek',
   'wilFolderrek','contactOnderhoudGewenst','schermAanwezig','schermAnimatieInteresse','isInrichting',
   'heeftClientenBibliotheek','literatuurBehoefteCA_AA'].forEach(id => document.getElementById('f-' + id).checked = false);
  document.getElementById('f-contactOnderhoudMethode').value = '';
  document.getElementById('f-schermOrientatie').value = '';
}

function openNieuweLocatie() {
  huidigeLocatieId = null;
  leegFormulier();
  document.getElementById('loc-titel').textContent = 'New location';
  document.getElementById('detail-activiteiten').style.display = 'none';
  document.getElementById('btn-verwijder-locatie').style.display = 'none';
  document.getElementById('modal-locatie').style.display = 'flex';
}

async function openLocatie(id) {
  const res = await fetch('api_locations.php?action=get&id=' + encodeURIComponent(id));
  const data = await res.json();
  const l = data.locatie;
  huidigeLocatieId = l.id;
  document.getElementById('loc-titel').textContent = l.naam;

  document.getElementById('f-naam').value = l.naam;
  document.getElementById('f-type').value = l.type || '';
  document.getElementById('f-plaats').value = l.plaats || '';
  document.getElementById('f-adres').value = l.adres || '';
  document.getElementById('f-postcode').value = l.postcode || '';
  document.getElementById('f-lat').value = l.lat ?? '';
  document.getElementById('f-lng').value = l.lng ?? '';
  document.getElementById('f-receptieTelefoon').value = l.contact.receptieTelefoon || '';
  document.getElementById('f-algemeenEmail').value = l.contact.algemeenEmail || '';
  document.getElementById('f-contactpersoonNaam').value = l.contact.contactpersoonNaam || '';
  document.getElementById('f-contactpersoonTelefoon').value = l.contact.contactpersoonTelefoon || '';
  document.getElementById('f-contactpersoonEmail').value = l.contact.contactpersoonEmail || '';
  document.getElementById('f-notitie').value = l.notitie || '';
  Object.entries(l.acties).forEach(([k, v]) => {
    const el = document.getElementById('f-' + k);
    if (!el) return;
    if (el.type === 'checkbox') el.checked = !!v; else el.value = v || '';
  });

  document.getElementById('detail-activiteiten').style.display = '';
  document.getElementById('btn-verwijder-locatie').style.display = isAdmin ? 'inline-block' : 'none';
  document.getElementById('activiteit-form').style.display = 'none';
  renderActiviteiten(l.activiteiten);
  document.getElementById('modal-locatie').style.display = 'flex';
}

function verzamelFormulier() {
  return {
    naam: document.getElementById('f-naam').value.trim(),
    type: document.getElementById('f-type').value,
    plaats: document.getElementById('f-plaats').value,
    adres: document.getElementById('f-adres').value,
    postcode: document.getElementById('f-postcode').value,
    lat: document.getElementById('f-lat').value || null,
    lng: document.getElementById('f-lng').value || null,
    notitie: document.getElementById('f-notitie').value,
    contact: {
      receptieTelefoon: document.getElementById('f-receptieTelefoon').value,
      algemeenEmail: document.getElementById('f-algemeenEmail').value,
      contactpersoonNaam: document.getElementById('f-contactpersoonNaam').value,
      contactpersoonTelefoon: document.getElementById('f-contactpersoonTelefoon').value,
      contactpersoonEmail: document.getElementById('f-contactpersoonEmail').value,
    },
    acties: {
      flyersToegestaan: document.getElementById('f-flyersToegestaan').checked,
      posterToegestaan: document.getElementById('f-posterToegestaan').checked,
      voorlichtingPersoneelInteresse: document.getElementById('f-voorlichtingPersoneelInteresse').checked,
      folderClientenInteresse: document.getElementById('f-folderClientenInteresse').checked,
      infoPakketGeleverd: document.getElementById('f-infoPakketGeleverd').checked,
      heeftFolderrek: document.getElementById('f-heeftFolderrek').checked,
      wilFolderrek: document.getElementById('f-wilFolderrek').checked,
      contactOnderhoudGewenst: document.getElementById('f-contactOnderhoudGewenst').checked,
      contactOnderhoudMethode: document.getElementById('f-contactOnderhoudMethode').value,
      schermAanwezig: document.getElementById('f-schermAanwezig').checked,
      schermOrientatie: document.getElementById('f-schermOrientatie').value,
      schermAnimatieInteresse: document.getElementById('f-schermAnimatieInteresse').checked,
      isInrichting: document.getElementById('f-isInrichting').checked,
      heeftClientenBibliotheek: document.getElementById('f-heeftClientenBibliotheek').checked,
      literatuurBehoefteCA_AA: document.getElementById('f-literatuurBehoefteCA_AA').checked,
    },
  };
}

async function zoekCoordinaten() {
  const adres = document.getElementById('f-adres').value;
  const postcode = document.getElementById('f-postcode').value;
  const plaats = document.getElementById('f-plaats').value;
  const statusEl = document.getElementById('geocode-status');
  if (!adres && !plaats) { statusEl.textContent = 'Enter an address or city first.'; return; }

  statusEl.textContent = 'Searching…';
  const params = new URLSearchParams({ adres, postcode, plaats });
  try {
    const res = await fetch('api_locations.php?action=geocode&' + params.toString());
    const data = await res.json();
    if (data.gevonden) {
      document.getElementById('f-lat').value = data.lat;
      document.getElementById('f-lng').value = data.lng;
      statusEl.textContent = '✓ Found: ' + (data.label || (data.lat + ', ' + data.lng));
    } else {
      statusEl.textContent = 'Not found — enter the coordinates manually if needed.';
    }
  } catch (e) {
    statusEl.textContent = 'Search failed — enter the coordinates manually if needed.';
  }
}

async function opslaanLocatie() {
  const body = verzamelFormulier();
  if (!body.naam) { alert('The institution\'s name is required.'); return; }

  // Automatically geocode if no coordinates have been entered yet, so the
  // location also appears on the map without anyone having to look it up
  // themselves. If it fails, we simply save without coordinates.
  if (!body.lat || !body.lng) {
    try {
      const params = new URLSearchParams({ adres: body.adres, postcode: body.postcode, plaats: body.plaats });
      const res = await fetch('api_locations.php?action=geocode&' + params.toString());
      const data = await res.json();
      if (data.gevonden) { body.lat = data.lat; body.lng = data.lng; }
    } catch (e) { /* not a problem, just save without coordinates */ }
  }

  const url = huidigeLocatieId
    ? 'api_locations.php?action=update&id=' + encodeURIComponent(huidigeLocatieId)
    : 'api_locations.php?action=create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { alert('Save failed.'); return; }
  sluitModal('modal-locatie');
  laadLocaties();
}

async function verwijderLocatie() {
  if (!huidigeLocatieId) return;
  if (!confirm('Permanently delete this location? All activities linked to it will also be removed.')) return;
  await fetch('api_locations.php?action=delete&id=' + encodeURIComponent(huidigeLocatieId), { method: 'POST', credentials: 'same-origin' });
  sluitModal('modal-locatie');
  laadLocaties();
}

// ---- Cleaning up duplicates ---------------------------------------------

async function checkDuplicaten() {
  const res = await fetch('api_locations.php?action=duplicaten_zoeken');
  const data = await res.json();
  if (!data.groepen.length) { alert('No duplicates found (same name + postal code + city).'); return; }

  const aantalTeVerwijderen = data.groepen.reduce((som, g) => som + g.length - 1, 0);
  const overzicht = data.groepen.map(g => `- "${g[0].naam}" (${g.length}x)`).join('\n');
  const bevestiging = confirm(
    `${data.groepen.length} group(s) with duplicates found, ${aantalTeVerwijderen} location(s) will be deleted:\n\n${overzicht}\n\n` +
    `For each group, the location with the most activities is kept (if tied: the oldest one). Continue?`
  );
  if (!bevestiging) return;

  const verwRes = await fetch('api_locations.php?action=duplicaten_verwijderen', { method: 'POST', credentials: 'same-origin' });
  const verwData = await verwRes.json();
  alert(verwData.verwijderd + ' duplicate(s) removed.');
  laadLocaties();
}

// ---- Activities --------------------------------------------------

function renderActiviteiten(activiteiten) {
  const labels = {
    bezoek: 'Visit', folders_bijgevuld: 'Leaflets restocked', poster_gecontroleerd: 'Poster checked',
    telefonisch_contact: 'Phone contact', voorlichting_gegeven: 'Presentation given',
    wijziging_gesignaleerd: 'Change noticed', anders: 'Other',
  };
  const el = document.getElementById('activiteiten-lijst');
  if (!activiteiten.length) { el.innerHTML = '<p style="color:var(--grey); font-size:13px;">No activities recorded yet.</p>'; return; }
  el.innerHTML = activiteiten.map(a => `
    <div class="activity-item">
      <div><strong>${labels[a.type] || a.type}</strong> — ${formatDatum(a.datum)}</div>
      ${a.omschrijving ? `<div>${escapeHtml(a.omschrijving)}</div>` : ''}
      <div class="meta">by ${escapeHtml(a.door)}</div>
    </div>`).join('');
}

function toonActiviteitForm() {
  document.getElementById('a-datum').value = new Date().toISOString().slice(0, 10);
  document.getElementById('activiteit-form').style.display = '';
}

async function opslaanActiviteit() {
  const body = { datum: document.getElementById('a-datum').value, type: document.getElementById('a-type').value, omschrijving: document.getElementById('a-omschrijving').value };
  const res = await fetch('api_locations.php?action=activity_add&id=' + encodeURIComponent(huidigeLocatieId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Save failed.'); return; }
  document.getElementById('activiteit-form').style.display = 'none';
  document.getElementById('a-omschrijving').value = '';
  renderActiviteiten(data.locatie.activiteiten.sort((a, b) => b.datum.localeCompare(a.datum)));
  laadLocaties();
}

// ---- Bulk import (CSV) -----------------------------------------------

let laatstIngelezenRijen = [];

document.getElementById('csvInput').addEventListener('change', (e) => {
  const file = e.target.files[0];
  if (!file) return;
  Papa.parse(file, {
    header: true, skipEmptyLines: true,
    complete: (results) => {
      laatstIngelezenRijen = results.data;
      const rijen = laatstIngelezenRijen;
      document.getElementById('bulk-preview').innerHTML = `
        <p>${rijen.length} rows found. Check the first few and click import.</p>
        <table><thead><tr><th>Name</th><th>City</th><th>Contact person</th></tr></thead>
        <tbody>${rijen.slice(0, 5).map(r => `<tr><td>${escapeHtml(r.naam || '')}</td><td>${escapeHtml(r.plaats || '')}</td><td>${escapeHtml(r.contactpersoonNaam || '')}</td></tr>`).join('')}</tbody></table>
        <button class="primary" id="btn-importeer" style="margin-top:12px;">Import ${rijen.length} locations</button>
      `;
      document.getElementById('btn-importeer').addEventListener('click', importeerRijen);
    },
  });
});

async function importeerRijen() {
  const rijen = laatstIngelezenRijen;
  const res = await fetch('api_locations.php?action=bulk_import', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rijen }),
  });
  const data = await res.json();
  document.getElementById('bulk-preview').innerHTML = `<p>${data.aangemaakt} locations imported.` +
    (data.fouten.length ? `<br>Errors: ${data.fouten.join(', ')}` : '') + `</p>`;
  document.getElementById('csvInput').value = '';
  laadLocaties();
}

// ---- Campaigns ----------------------------------------------------

async function laadCampagnes() {
  const res = await fetch('api_locations.php?action=campaign_list', { cache: 'no-store' });
  const data = await res.json();
  const statusBadge = { wacht_op_goedkeuring: 'geel', goedgekeurd: 'groen', afgewezen: 'rood' };
  const statusLabel = { wacht_op_goedkeuring: 'awaiting approval', goedgekeurd: 'approved', afgewezen: 'rejected' };

  const renderRij = (c, magGoedkeuren) => `
    <tr>
      <td>${escapeHtml(c.naam)}</td><td>${formatDatum(c.datum)}</td><td>${escapeHtml(c.kanaal)}</td><td>${c.deelnemers.length}</td>
      <td>
        <span class="badge ${statusBadge[c.status] || 'grijs'}">${statusLabel[c.status] || c.status}</span>
        ${c.status === 'wacht_op_goedkeuring' && magGoedkeuren ? `
          <button class="secondary" onclick="campagneGoedkeuren('${c.id}')">✓ Approve</button>
          <button class="secondary" onclick="campagneAfwijzenOpenen('${c.id}')">✕ Reject</button>
        ` : ''}
      </td>
    </tr>`;

  document.getElementById('campagnes-body-fysiek').innerHTML = data.campagnes.filter(c => (c.type || 'fysiek') === 'fysiek').map(c => renderRij(c, magFysiekGoedkeuren)).join('') || '<tr><td colspan="5">No campaigns yet.</td></tr>';
  document.getElementById('campagnes-body-online').innerHTML = data.campagnes.filter(c => c.type === 'online').map(c => renderRij(c, magOnlineGoedkeuren)).join('') || '<tr><td colspan="5">No campaigns yet.</td></tr>';
}

async function campagneGoedkeuren(id) {
  await fetch('api_locations.php?action=campaign_goedkeuren&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadCampagnes();
}

let huidigAfTeWijzenCampagneId = null;
function campagneAfwijzenOpenen(id) {
  huidigAfTeWijzenCampagneId = id;
  document.getElementById('ca-opmerking').value = '';
  document.getElementById('modal-campagne-afwijzen').style.display = 'flex';
}
async function campagneAfwijzenBevestigen() {
  await fetch('api_locations.php?action=campaign_afwijzen&id=' + encodeURIComponent(huidigAfTeWijzenCampagneId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ opmerking: document.getElementById('ca-opmerking').value }),
  });
  sluitModal('modal-campagne-afwijzen');
  laadCampagnes();
}

let huidigCampagneType = 'fysiek';
function openNieuweCampagne(type) {
  huidigCampagneType = type;
  document.getElementById('c-naam').value = '';
  document.getElementById('c-datum').value = new Date().toISOString().slice(0, 10);
  document.getElementById('c-kanaal').value = type === 'online' ? 'Social media' : 'Laposta';
  document.getElementById('c-omschrijving').value = '';
  document.getElementById('modal-campagne').style.display = 'flex';
}

async function opslaanCampagne() {
  const body = { naam: document.getElementById('c-naam').value, datum: document.getElementById('c-datum').value, kanaal: document.getElementById('c-kanaal').value, omschrijving: document.getElementById('c-omschrijving').value, type: huidigCampagneType };
  if (!body.naam.trim()) { alert('Name is required.'); return; }
  await fetch('api_locations.php?action=campaign_create', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  sluitModal('modal-campagne');
  laadCampagnes();
}

laadInstellingenLocations().then(laadLocaties);

// ---- Targets ----------------------------------------------------

let targetsData = [];

async function laadTargets() {
  const res = await fetch('api_locations.php?action=target_list', { cache: 'no-store' });
  const data = await res.json();
  targetsData = data.targets;
  const renderRij = (t) => `
    <tr>
      <td>${escapeHtml(t.naam)}</td>
      <td>${escapeHtml(t.omschrijving || '—')}</td>
      <td style="white-space:nowrap;">
        <button class="secondary" onclick='openTargetForm(${JSON.stringify(t.type || 'fysiek')}, ${JSON.stringify(t)})'>✎</button>
        <button class="secondary" onclick="targetVerwijderen('${t.id}')">✕</button>
      </td>
    </tr>`;
  document.getElementById('targets-body-fysiek').innerHTML = targetsData.filter(t => (t.type || 'fysiek') === 'fysiek').map(renderRij).join('') || '<tr><td colspan="3">No targets yet.</td></tr>';
  document.getElementById('targets-body-online').innerHTML = targetsData.filter(t => t.type === 'online').map(renderRij).join('') || '<tr><td colspan="3">No targets yet.</td></tr>';
}

let huidigBewerkTargetId = null;
let huidigTargetType = 'fysiek';
function openTargetForm(type, bestaand) {
  huidigTargetType = type;
  huidigBewerkTargetId = bestaand ? bestaand.id : null;
  document.getElementById('target-titel-kop').textContent = bestaand ? 'Edit target' : 'Add target (' + (type === 'online' ? 'online' : 'physical') + ')';
  document.getElementById('tg-naam').value = bestaand ? bestaand.naam : '';
  document.getElementById('tg-omschrijving').value = bestaand ? (bestaand.omschrijving || '') : '';
  document.getElementById('modal-target').style.display = 'flex';
}

async function targetOpslaan() {
  const body = { naam: document.getElementById('tg-naam').value, omschrijving: document.getElementById('tg-omschrijving').value, type: huidigTargetType };
  if (!body.naam.trim()) { alert('Name is required.'); return; }
  const url = huidigBewerkTargetId
    ? 'api_locations.php?action=target_update&id=' + encodeURIComponent(huidigBewerkTargetId)
    : 'api_locations.php?action=target_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Save failed.'); return; }
  sluitModal('modal-target');
  laadTargets();
}

async function targetVerwijderen(id) {
  if (!confirm('Delete this target?')) return;
  const res = await fetch('api_locations.php?action=target_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Deletion failed.'); return; }
  laadTargets();
}

// ---- Campaign ideas ----------------------------------------------------

let ideeenData = [];
let huidigBewerkIdeeId = null;
let materialenHuidigIdee = [];
let vervolgactiesHuidigIdee = [];

async function laadIdeeen() {
  if (!targetsData.length) await laadTargets();
  const res = await fetch('api_locations.php?action=idee_list', { cache: 'no-store' });
  const data = await res.json();
  ideeenData = data.ideeen;
  renderIdeeen();
}

function renderIdeeen() {
  const vandaag = new Date().toISOString().slice(0, 10);
  const maakKaart = (idee, magAfronden) => {
    const teLaat = idee.deadline && idee.deadline < vandaag && idee.status !== 'afgerond';
    const openVervolgacties = idee.vervolgacties.filter(v => !v.voltooid).length;
    return `
    <div class="card" style="margin-bottom:12px; ${idee.status === 'afgerond' ? 'opacity:0.6;' : ''}">
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
          <strong>${escapeHtml(idee.titel)}</strong>
          <span class="badge ${idee.status === 'afgerond' ? 'groen' : (idee.benaderd ? 'geel' : 'grijs')}">${idee.status === 'afgerond' ? 'done' : (idee.benaderd ? 'contacted' : 'not yet contacted')}</span>
          ${teLaat ? '<span class="badge rood">deadline passed</span>' : ''}
          ${idee.targetNaam ? `<div style="font-size:12.5px; color:var(--grey); margin-top:2px;">Target: ${escapeHtml(idee.targetNaam)}</div>` : ''}
          <div style="font-size:12px; color:var(--grey); margin-top:2px;">
            Registered: ${formatDatum(idee.datumRegistratie)}
            ${idee.datumEersteContact ? ' — First contact: ' + formatDatum(idee.datumEersteContact) : ''}
            ${idee.deadline ? ' — Deadline: ' + formatDatum(idee.deadline) : ''}
          </div>
          ${idee.materialen.length ? `<div style="font-size:12.5px; margin-top:6px;">📎 ${idee.materialen.map(m => `<a href="api_locations.php?action=materiaal_stream&bestand=${encodeURIComponent(m.pad)}&naam=${encodeURIComponent(m.bestandsnaam)}" target="_blank">${escapeHtml(m.bestandsnaam)}</a>`).join(', ')}</div>` : ''}
          ${idee.vervolgacties.length ? `
            <div style="margin-top:8px;">
              <strong style="font-size:12.5px;">Follow-up actions (${idee.vervolgacties.length - openVervolgacties}/${idee.vervolgacties.length} done):</strong>
              <ul style="margin:4px 0 0; padding-left:18px; font-size:12.5px;">
                ${idee.vervolgacties.map(v => `<li style="${v.voltooid ? 'text-decoration:line-through; color:var(--grey);' : ''}">${escapeHtml(v.tekst)}${v.datum ? ' — ' + formatDatum(v.datum) : ''}${v.voltooid ? ' ✓ (' + formatDatum(v.voltooidOp) + ')' : ''}</li>`).join('')}
              </ul>
            </div>` : ''}
        </div>
        <div style="white-space:nowrap;">
          ${magAfronden ? `<button class="secondary" onclick="ideeStatusWijzigen('${idee.id}', '${idee.status === 'afgerond' ? 'open' : 'afgerond'}')">${idee.status === 'afgerond' ? '↺ Reopen' : '✓ Mark done'}</button>` : ''}
          <button class="secondary" onclick='openIdeeForm(${JSON.stringify(idee.type || 'fysiek')}, ${JSON.stringify(idee)})'>✎</button>
          <button class="secondary" onclick="ideeVerwijderen('${idee.id}')">✕</button>
        </div>
      </div>
    </div>`;
  };

  document.getElementById('ideeen-lijst-fysiek').innerHTML = ideeenData.filter(i => (i.type || 'fysiek') === 'fysiek').map(i => maakKaart(i, magFysiekGoedkeuren)).join('') || '<p style="color:var(--grey);">No campaign ideas submitted yet.</p>';
  document.getElementById('ideeen-lijst-online').innerHTML = ideeenData.filter(i => i.type === 'online').map(i => maakKaart(i, magOnlineGoedkeuren)).join('') || '<p style="color:var(--grey);">No campaign ideas submitted yet.</p>';
}

async function ideeStatusWijzigen(id, status) {
  const res = await fetch('api_locations.php?action=idee_update&id=' + encodeURIComponent(id), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Change failed.'); return; }
  laadIdeeen();
}

let huidigIdeeType = 'fysiek';
async function openIdeeForm(type, bestaand) {
  huidigIdeeType = bestaand ? (bestaand.type || 'fysiek') : type;
  huidigBewerkIdeeId = bestaand ? bestaand.id : null;
  document.getElementById('idee-titel-kop').textContent = bestaand ? 'Edit campaign idea' : 'Submit campaign idea (' + (huidigIdeeType === 'online' ? 'online' : 'physical') + ')';
  document.getElementById('id-titel').value = bestaand ? bestaand.titel : '';
  document.getElementById('id-deadline').value = bestaand ? (bestaand.deadline || '') : '';
  document.getElementById('id-eerstecontact').value = bestaand ? (bestaand.datumEersteContact || '') : '';
  document.getElementById('id-benaderd').checked = bestaand ? bestaand.benaderd : false;
  document.getElementById('id-target-vrij').value = bestaand ? (bestaand.targetId ? '' : (bestaand.targetNaam || '')) : '';

  if (!targetsData.length) await laadTargets();
  const relevanteTargets = targetsData.filter(t => (t.type || 'fysiek') === huidigIdeeType);
  document.getElementById('id-target-select').innerHTML = '<option value="">— choose an existing target —</option>' +
    relevanteTargets.map(t => `<option value="${t.id}" ${bestaand?.targetId === t.id ? 'selected' : ''}>${escapeHtml(t.naam)}</option>`).join('');

  materialenHuidigIdee = bestaand ? [...bestaand.materialen] : [];
  vervolgactiesHuidigIdee = bestaand ? bestaand.vervolgacties.map(v => ({ ...v })) : [];
  renderMaterialenLijst();
  renderVervolgactiesLijst();
  document.getElementById('id-materiaal-status').textContent = '';
  document.getElementById('id-materiaal-input').value = '';

  document.getElementById('modal-idee').style.display = 'flex';
}

function targetSelectGewijzigd() {
  if (document.getElementById('id-target-select').value) document.getElementById('id-target-vrij').value = '';
}

function renderMaterialenLijst() {
  document.getElementById('id-materialen-lijst').innerHTML = materialenHuidigIdee.map((m, i) => `
    <div style="display:flex; justify-content:space-between; align-items:center; padding:3px 0; font-size:12.5px;">
      <span>📎 ${escapeHtml(m.bestandsnaam)}</span>
      <button type="button" class="secondary" style="padding:1px 8px;" onclick="materialenHuidigIdee.splice(${i},1); renderMaterialenLijst();">✕</button>
    </div>`).join('');
}

async function materiaalUploaden() {
  const inputEl = document.getElementById('id-materiaal-input');
  if (!inputEl.files.length) return;
  const statusEl = document.getElementById('id-materiaal-status');
  statusEl.textContent = 'Uploading…';
  const fd = new FormData();
  fd.append('bestand', inputEl.files[0]);
  const res = await fetch('api_locations.php?action=materiaal_upload', { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  inputEl.value = '';
  if (!res.ok) { statusEl.textContent = data.error || 'Upload failed.'; return; }
  statusEl.textContent = '✓ added: ' + data.bestandsnaam;
  materialenHuidigIdee.push(data);
  renderMaterialenLijst();
}

function renderVervolgactiesLijst() {
  document.getElementById('id-vervolgacties-lijst').innerHTML = vervolgactiesHuidigIdee.map((v, i) => `
    <div class="checkbox-row" data-idx="${i}">
      <input type="checkbox" ${v.voltooid ? 'checked' : ''} onchange="vervolgactieToggle(${i}, this.checked)" style="width:auto;" />
      <input class="vv-tekst" placeholder="Action (e.g. send reminder email)" value="${escapeHtml(v.tekst)}" oninput="vervolgactiesHuidigIdee[${i}].tekst = this.value" style="flex:2; ${v.voltooid ? 'text-decoration:line-through; color:var(--grey);' : ''}" />
      <input type="date" class="vv-datum" value="${v.datum || ''}" onchange="vervolgactiesHuidigIdee[${i}].datum = this.value" style="width:150px;" />
      ${v.voltooid ? `<span class="badge groen" style="white-space:nowrap;">✓ ${v.voltooidOp ? formatDatum(v.voltooidOp) : ''}</span>` : ''}
      <button type="button" class="secondary" onclick="vervolgactiesHuidigIdee.splice(${i},1); renderVervolgactiesLijst();">✕</button>
    </div>`).join('');
}

function vervolgactieToggle(i, voltooid) {
  vervolgactiesHuidigIdee[i].voltooid = voltooid;
  vervolgactiesHuidigIdee[i].voltooidOp = voltooid ? new Date().toISOString().slice(0, 10) : null;
  renderVervolgactiesLijst(); // re-render so the strikethrough/badge is immediately correct; still fully editable (unchecking reopens it)
}

function voegVervolgactieToe() {
  vervolgactiesHuidigIdee.push({ id: null, tekst: '', datum: '', voltooid: false, voltooidOp: null });
  renderVervolgactiesLijst();
}

async function ideeOpslaan() {
  const targetSelect = document.getElementById('id-target-select').value;
  const targetVrij = document.getElementById('id-target-vrij').value.trim();
  const body = {
    titel: document.getElementById('id-titel').value,
    type: huidigIdeeType,
    targetId: targetSelect || null,
    targetNaam: targetSelect ? (targetsData.find(t => t.id === targetSelect)?.naam || '') : targetVrij,
    deadline: document.getElementById('id-deadline').value,
    datumEersteContact: document.getElementById('id-eerstecontact').value,
    benaderd: document.getElementById('id-benaderd').checked,
    materialen: materialenHuidigIdee,
    vervolgacties: vervolgactiesHuidigIdee,
  };
  if (!body.titel.trim()) { alert('A plan/title is required.'); return; }
  const url = huidigBewerkIdeeId
    ? 'api_locations.php?action=idee_update&id=' + encodeURIComponent(huidigBewerkIdeeId)
    : 'api_locations.php?action=idee_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Save failed.'); return; }
  sluitModal('modal-idee');
  laadIdeeen();
}

async function ideeVerwijderen(id) {
  if (!confirm('Completely delete this campaign idea? Any uploaded materials will also be removed.')) return;
  const res = await fetch('api_locations.php?action=idee_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Deletion failed.'); return; }
  laadIdeeen();
}

// URL parameters from the dashboard (?nieuw=1, ?tab=bulk, ?tab=campagnes, ?open=location-id)
const params = new URLSearchParams(location.search);
if (params.get('nieuw') === '1') openNieuweLocatie();
if (params.get('tab')) toonView(params.get('tab'));
if (params.get('open')) openLocatie(params.get('open'));
</script>
</body>
</html>
