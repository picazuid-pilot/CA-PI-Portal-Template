<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isCoordinator = heeft_rol(['admin', 'coordinator_outreach']);
$eigenUserId = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Planning &amp; Calendar — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet" />
<script src="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <div style="display:flex; gap:6px;">
      <button class="secondary" onclick="toonView('agenda')">Shared calendar</button>
      <button class="secondary" onclick="toonView('voorlichting')">Outreach</button>
      <button class="secondary" onclick="toonView('todo')">To-Do</button>
    </div>
    <div class="right" id="voorlichting-acties" style="display:none;">
      <button class="secondary" onclick="kopieerExterneLink()">🔗 Copy external request link</button>
      <button class="primary" onclick="openAanvraagForm()">+ Request outreach</button>
    </div>
    <div class="right" id="agenda-acties">
      <?php if (heeft_rol(['admin'])): ?><button class="secondary" onclick="openInstellingenForm()">⚙️ Settings</button><?php endif; ?>
      <button class="primary" onclick="openAgendaForm()">+ Add agenda item</button>
    </div>
    <div class="right" id="todo-acties" style="display:none;">
      <button class="primary" onclick="openTodoForm()">+ Add task</button>
    </div>
  </div>

  <!-- ===== To-Do ===== -->
  <div id="view-todo" class="card" style="display:none;">
    <strong>To-Do</strong>
    <div id="todo-lijst" style="margin-top:12px;"></div>
  </div>

  <!-- ===== Modal: add/edit task ===== -->
  <div id="modal-todo" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-todo')">
    <div class="modal">
      <button class="modal-close" onclick="sluitModal('modal-todo')">✕</button>
      <h2 id="todo-titel-kop">Add task</h2>
      <label>Title</label><input id="td-titel" />
      <label>Description (optional)</label><textarea id="td-omschrijving" rows="2"></textarea>
      <label>Deadline (optional)</label><input id="td-deadline" type="date" style="max-width:200px;" />
      <label>Assign to (optional — leave blank = open task)</label>
      <div id="td-toegewezen-lijst"></div>
      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="todoOpslaan()">Save</button>
      </div>
    </div>
  </div>

  <!-- ===== Shared calendar ===== -->
  <div id="view-agenda" class="card">
    <div class="toolbar" style="margin-bottom:0;">
      <strong>Shared calendar</strong>
      <div class="right">
        <button class="secondary" id="btn-kalenderweergave" onclick="wisselAgendaWeergave('kalender')">📅 Calendar</button>
        <button class="secondary" id="btn-lijstweergave" onclick="wisselAgendaWeergave('lijst')">☰ List</button>
      </div>
    </div>

    <div id="agenda-kalender" style="margin-top:12px;">
      <div class="toolbar" style="margin-bottom:10px;">
        <div style="display:flex; align-items:center; gap:10px;">
          <button class="secondary" onclick="kalenderMaandWijzig(-1)">‹</button>
          <strong id="kalender-maand-label" style="min-width:150px; text-align:center;"></strong>
          <button class="secondary" onclick="kalenderMaandWijzig(1)">›</button>
        </div>
        <button class="secondary" onclick="kalenderNaarVandaag()">Today</button>
      </div>
      <div id="kalender-grid"></div>
      <div id="kalender-legenda" style="display:flex; flex-wrap:wrap; gap:10px 16px; margin-top:14px; font-size:12px; color:var(--grey);"></div>
      <div id="kalender-maand-strook" style="display:flex; gap:10px; overflow-x:auto; margin-top:14px; padding-bottom:6px;"></div>
    </div>

    <div id="agenda-lijst" style="display:none;"></div>
  </div>

  <!-- ===== Modal: day detail in calendar view ===== -->
  <div id="modal-dag-detail" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-dag-detail')">
    <div class="modal" style="max-width:500px;">
      <button class="modal-close" onclick="sluitModal('modal-dag-detail')">✕</button>
      <h2 id="dd-titel">Agenda items</h2>
      <div id="dd-inhoud"></div>
    </div>
  </div>

  <!-- ===== Outreach (sub-tabs: upcoming + requests) ===== -->
  <div id="view-voorlichting" class="card" style="display:none;">
    <div class="toolbar" style="margin-bottom:12px;">
      <div style="display:flex; gap:6px;">
        <button class="secondary" onclick="toonVoorlichtingSubView('aankomend')">Upcoming presentations</button>
        <button class="secondary" onclick="toonVoorlichtingSubView('aanvragen')">All requests</button>
      </div>
    </div>

    <div id="subview-aankomend">
      <div class="toolbar" style="margin-bottom:8px;">
        <span></span>
        <button class="secondary" id="btn-kaart-toggle" onclick="toggleVoorlichtingKaart()">🗺️ Show map</button>
      </div>
      <div id="voorlichting-kaart" style="display:none; height:220px; border-radius:8px; overflow:hidden; margin-bottom:12px;"></div>
      <div id="aankomend-lijst"></div>
    </div>

    <div id="subview-aanvragen" style="display:none;">
      <table>
        <thead><tr><th></th><th>Institution</th><th>Requester</th><th>Preferred date</th><th>Status</th><th></th></tr></thead>
        <tbody id="aanvragen-body"><tr><td colspan="6">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

</div>

<!-- ===== Modal: outreach detail overview (click an upcoming presentation) ===== -->
<div id="modal-voorlichting-detail" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-voorlichting-detail')">
  <div class="modal" style="max-width:560px;">
    <button class="modal-close" onclick="sluitModal('modal-voorlichting-detail')">✕</button>
    <h2 id="vld-titel">Outreach</h2>
    <div id="vld-inhoud"></div>
  </div>
</div>

<!-- ===== Modal: request detail / scheduling ===== -->
<div id="modal-aanvraag-detail" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-aanvraag-detail')">
  <div class="modal" style="max-width:640px;">
    <button class="modal-close" onclick="sluitModal('modal-aanvraag-detail')">✕</button>
    <h2 id="detail-titel">Outreach request</h2>
    <div id="detail-info" style="font-size:13.5px; color:var(--grey); margin-bottom:14px;"></div>

    <div id="detail-acties-open">
      <div class="toolbar" style="margin-bottom:10px;">
        <button class="secondary" onclick="oproepVersturen()">📣 Send call-out to all members</button>
        <a id="whatsapp-link" href="#" target="_blank" rel="noopener" class="btn-primary" style="display:none;">📱 Open in WhatsApp</a>
        <button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="toonAfwijzenForm()">Decline</button>
      </div>
      <div id="aanmeldingen-lijst" style="margin-bottom:14px;"></div>

      <div id="afwijzen-form" style="display:none; background:var(--primary-light-95); border-radius:8px; padding:12px; margin-bottom:14px;">
        <label>Reason (optional, goes to the requester)</label>
        <textarea id="afwijzen-opmerking" rows="2"></textarea>
        <div style="text-align:right; margin-top:8px;"><button class="primary" onclick="aanvraagAfwijzen()">Confirm decline</button></div>
      </div>

      <div id="inplan-sectie">
        <h3 style="color:var(--primary); margin:18px 0 8px;">Final scheduling</h3>
        <label>Address (street, number, postal code, city) <a href="#" onclick="openMapsVoorInplan(); return false;" style="margin-left:6px; font-weight:normal;">📍 Open in Maps</a></label>
        <input id="ip-adres" placeholder="for the map and directions" />
        <div class="form-grid">
          <div><label>Date</label><input id="ip-datum" type="date" /></div>
          <div><label>Time</label><input id="ip-tijd" type="time" /></div>
          <div><label>Duration (minutes)</label><input id="ip-duur" type="number" min="15" step="15" value="60" /></div>
          <div><label>Institution contact person</label><input id="ip-contactpersoon" /></div>
          <div><label>Phone</label><input id="ip-telefoon" /></div>
          <div><label>Email</label><input id="ip-email" /></div>
        </div>
        <label>Which PI members will give the presentation?</label>
        <div id="ip-leden-lijst"></div>
        <div style="display:flex; gap:6px; margin-top:6px;">
          <input id="ip-lid-overig" placeholder="Other member (name, not from sign-ups)" style="flex:1;" />
          <button class="secondary" onclick="voegOverigLidToe()">+ Add</button>
        </div>
        <label>Note (optional)</label><textarea id="ip-opmerking" rows="2"></textarea>
        <div style="margin-top:14px; text-align:right;">
          <button class="primary" onclick="aanvraagInplannen()">Finalise schedule &amp; update calendar</button>
        </div>
      </div>
    </div>

    <div id="detail-acties-lid" style="display:none;">
      <p style="color:var(--grey); font-size:13.5px;">A call-out has been sent for this outreach presentation. Want to take part?</p>
      <button class="primary" id="btn-aanmelden" onclick="aanmelden()">Sign me up</button>
      <button class="secondary" id="btn-afmelden" onclick="afmelden()" style="display:none;">Withdraw</button>
    </div>

    <div id="detail-ingepland" style="display:none;"></div>
  </div>
</div>

<!-- ===== Modal: request outreach (internal) ===== -->
<div id="modal-aanvraag-nieuw" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-aanvraag-nieuw')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-aanvraag-nieuw')">✕</button>
    <h2>Request outreach</h2>
    <label>Institution name</label><input id="na-instelling" />
    <label>Address (street, number, postal code, city)</label><input id="na-adres" placeholder="for the map and directions" />
    <label>Contact person</label><input id="na-contactpersoon" />
    <div class="form-grid">
      <div><label>Email</label><input id="na-email" type="email" /></div>
      <div><label>Phone</label><input id="na-telefoon" /></div>
    </div>
    <div class="form-grid">
      <div><label>Preferred date</label><input id="na-datum" type="date" /></div>
      <div><label>Preferred time</label><input id="na-tijd" type="time" /></div>
    </div>
    <label>Expected number of attendees</label><input id="na-aantal" type="number" min="1" style="max-width:200px;" />
    <label>Note</label><textarea id="na-toelichting" rows="2"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="nieuweAanvraagOpslaan()">Submit request</button>
    </div>
  </div>
</div>

<!-- ===== Modal: add agenda item ===== -->
<div id="modal-agenda" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-agenda')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-agenda')">✕</button>
    <h2>Add agenda item</h2>
    <label>Title</label><input id="ag-titel" />
    <label>Type</label>
    <select id="ag-type">
      <option value="evenement">Event</option>
      <option value="district_vergadering">District meeting</option>
      <option value="area_vergadering">Area meeting</option>
      <option value="overig">Other</option>
    </select>
    <div class="form-grid">
      <div><label>Date</label><input id="ag-datum" type="date" /></div>
      <div><label>Time (optional)</label><input id="ag-tijd" type="time" /></div>
    </div>
    <label>Location</label><input id="ag-locatie" />
    <label>Description</label><textarea id="ag-omschrijving" rows="2"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="agendaOpslaan()">Add</button>
    </div>
  </div>
</div>

<!-- ===== Modal: settings (IT only) ===== -->
<div id="modal-instellingen" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-instellingen')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-instellingen')">✕</button>
    <h2>Settings — Planning &amp; Calendar</h2>
    <label>Time display</label>
    <div class="checkbox-row"><input type="radio" name="is-tijdformaat" id="is-tijd-24" value="24" style="width:auto;" /><label style="margin:0;">24-hour (e.g. 14:30)</label></div>
    <div class="checkbox-row"><input type="radio" name="is-tijdformaat" id="is-tijd-12" value="12" style="width:auto;" /><label style="margin:0;">12-hour with AM/PM (e.g. 2:30 PM)</label></div>
    <label style="margin-top:10px;">Date order</label>
    <select id="is-datumformaat">
      <option value="dmy">Day-month-year (e.g. 12-09-2026)</option>
      <option value="mdy">Month-day-year (e.g. 09-12-2026)</option>
      <option value="ymd">Year-month-day (e.g. 2026-09-12)</option>
    </select>
    <p style="font-size:11.5px; color:var(--grey); margin-top:6px;">Applies to all members and everywhere in the portal — this is a district-wide setting, not personal.</p>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="instellingenOpslaan()">Save</button>
    </div>
  </div>
</div>

<script>
const isCoordinator = <?= $isCoordinator ? 'true' : 'false' ?>;
const eigenUserId = <?= json_encode($eigenUserId) ?>;
let aanvragenData = [];
let huidigeDetailAanvraag = null;
let tijdformaat24 = true;
let datumformaatVolgorde = 'dmy'; // 'dmy' | 'mdy' | 'ymd'

const EN_MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const EN_MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const EN_DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
const EN_DAYS_SHORT = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// Replaces every new Date(x).toLocaleDateString('en-GB', {...}) in this
// file — same options shape (month/weekday/year), but respects the
// chosen day-month-year order from settings. With no options, this
// returns a plain numeric date in that order (e.g. 12-09-2026).
function formatDatum(input, opts = {}) {
  const d = input instanceof Date ? input : new Date(input);
  if (isNaN(d.getTime())) return '';
  const dayNr = d.getDate();
  const year = d.getFullYear();
  const monthName = opts.month === 'long' ? EN_MONTHS[d.getMonth()] : (opts.month === 'short' ? EN_MONTHS_SHORT[d.getMonth()] : null);
  const dayName = opts.weekday === 'long' ? EN_DAYS[d.getDay()] : (opts.weekday === 'short' ? EN_DAYS_SHORT[d.getDay()] : null);

  if (!monthName) {
    const dd = String(dayNr).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    if (datumformaatVolgorde === 'mdy') return `${mm}-${dd}-${year}`;
    if (datumformaatVolgorde === 'ymd') return `${year}-${mm}-${dd}`;
    return `${dd}-${mm}-${year}`;
  }

  const core = datumformaatVolgorde === 'mdy'
    ? (opts.year ? `${monthName} ${dayNr}, ${year}` : `${monthName} ${dayNr}`)
    : (opts.year ? `${dayNr} ${monthName} ${year}` : `${dayNr} ${monthName}`);
  return dayName ? `${dayName} ${core}` : core;
}

function formatTijd(tijd) {
  if (!tijd) return '';
  if (tijdformaat24) return tijd;
  const delen = tijd.split(':');
  let u = parseInt(delen[0], 10);
  const m = delen[1] || '00';
  if (isNaN(u)) return tijd;
  const ampm = u >= 12 ? 'PM' : 'AM';
  u = u % 12; if (u === 0) u = 12;
  return `${u}:${m} ${ampm}`;
}

async function laadInstellingen() {
  const res = await fetch('api_members.php?action=instellingen_get', { cache: 'no-store' });
  const data = await res.json();
  tijdformaat24 = (data.instellingen.tijdformaat || '24') === '24';
  datumformaatVolgorde = data.instellingen.datumformaat || 'dmy';
}

function openInstellingenForm() {
  document.getElementById(tijdformaat24 ? 'is-tijd-24' : 'is-tijd-12').checked = true;
  document.getElementById('is-datumformaat').value = datumformaatVolgorde;
  document.getElementById('modal-instellingen').style.display = 'flex';
}

async function instellingenOpslaan() {
  const tijdformaat = document.querySelector('input[name="is-tijdformaat"]:checked')?.value || '24';
  const datumformaat = document.getElementById('is-datumformaat').value;
  await fetch('api_members.php?action=instellingen_bijwerken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ tijdformaat, datumformaat }),
  });
  tijdformaat24 = tijdformaat === '24';
  datumformaatVolgorde = datumformaat;
  sluitModal('modal-instellingen');
  // Re-render every date/time display with the new format.
  renderAankomendeVoorlichtingen();
  laadAanvragen();
  renderAgendaLijst();
  renderKalender();
  renderTodos();
}

function toonView(v) {
  ['agenda', 'voorlichting', 'todo'].forEach(x => document.getElementById('view-' + x).style.display = x === v ? '' : 'none');
  document.getElementById('agenda-acties').style.display = v === 'agenda' ? 'flex' : 'none';
  document.getElementById('voorlichting-acties').style.display = v === 'voorlichting' ? 'flex' : 'none';
  document.getElementById('todo-acties').style.display = v === 'todo' ? 'flex' : 'none';
}
function toonVoorlichtingSubView(v) {
  ['aankomend', 'aanvragen'].forEach(x => document.getElementById('subview-' + x).style.display = x === v ? '' : 'none');
}
function sluitModal(id) { document.getElementById(id).style.display = 'none'; }
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

function kopieerExterneLink() {
  const link = window.location.origin + window.location.pathname.replace(/outreach\.php$/, '') + 'external_request.php';
  navigator.clipboard.writeText(link).then(
    () => alert('Link copied to clipboard:\n' + link),
    () => prompt('Could not copy automatically — copy this link manually:', link)
  );
}

// ---- Requests ----------------------------------------------------

async function laadAanvragen() {
  const res = await fetch('api_outreach.php?action=aanvraag_list', { cache: 'no-store' });
  const data = await res.json();
  aanvragenData = data.aanvragen;
  const statusBadge = { open: 'geel', ingepland: 'groen', afgewezen: 'rood' };
  const statusLabel = { open: 'open', ingepland: 'scheduled', afgewezen: 'declined' };

  document.getElementById('aanvragen-body').innerHTML = aanvragenData.map(a => `
    <tr onclick='openDetail(${JSON.stringify(a.id)})'>
      <td>${a.extern ? '<span class="badge grijs">external</span>' : ''}</td>
      <td>${escapeHtml(a.instellingNaam)}</td>
      <td>${escapeHtml(a.aanvragerNaam || (a.contactpersoon || '—'))}</td>
      <td>${escapeHtml(a.gewensteDatum || '—')}${a.gewensteTijd ? ' at ' + formatTijd(a.gewensteTijd) : ''}</td>
      <td><span class="badge ${statusBadge[a.status]}">${statusLabel[a.status] || a.status}</span>${a.oproepVerstuurd && a.status === 'open' ? ' <span class="badge geel">call-out sent</span>' : ''}</td>
      <td>${isCoordinator ? `<button class="secondary" onclick="event.stopPropagation(); aanvraagVerwijderen('${a.id}')">✕</button>` : ''}</td>
    </tr>`).join('') || '<tr><td colspan="6">No requests yet.</td></tr>';

  renderAankomendeVoorlichtingen();
}

let voorlichtingKaart = null;
let voorlichtingKaartGeladen = false;

async function toggleVoorlichtingKaart() {
  const container = document.getElementById('voorlichting-kaart');
  const zichtbaar = container.style.display !== 'none';
  container.style.display = zichtbaar ? 'none' : 'block';
  document.getElementById('btn-kaart-toggle').textContent = zichtbaar ? '🗺️ Show map' : '🗺️ Hide map';
  if (!zichtbaar && !voorlichtingKaartGeladen) {
    voorlichtingKaartGeladen = true;
    await laadVoorlichtingKaart();
  } else if (!zichtbaar && voorlichtingKaart) {
    setTimeout(() => voorlichtingKaart.resize(), 50); // container was display:none, MapLibre needs to recalculate
  }
}

async function laadVoorlichtingKaart() {
  voorlichtingKaart = new maplibregl.Map({
    container: 'voorlichting-kaart',
    style: '<?= MAP_STYLE_URL ?>',
    center: [<?= MAP_CENTER_LNG ?>, <?= MAP_CENTER_LAT ?>],
    zoom: <?= MAP_ZOOM ?>,
  });
  voorlichtingKaart.addControl(new maplibregl.NavigationControl(), 'top-right');

  const vandaag = new Date().toISOString().slice(0, 10);
  const aankomend = aanvragenData.filter(a => a.status === 'ingepland' && a.definitief?.datum >= vandaag);

  const bounds = new maplibregl.LngLatBounds();
  let gevondenErgens = false;

  await Promise.all(aankomend.map(async (a) => {
    const zoekterm = a.adres || a.instellingNaam;
    if (!zoekterm) return;
    try {
      const res = await fetch('api_locations.php?action=geocode&adres=' + encodeURIComponent(zoekterm), { cache: 'no-store' });
      const data = await res.json();
      if (!data.gevonden) return;
      gevondenErgens = true;
      const el = document.createElement('div');
      el.style.width = '14px'; el.style.height = '14px'; el.style.borderRadius = '50%';
      el.style.border = '2px solid white'; el.style.boxShadow = '0 0 0 1px rgba(0,0,0,.15)';
      el.style.background = 'var(--primary)'; el.style.cursor = 'pointer';
      const popup = new maplibregl.Popup({ offset: 12 }).setHTML(
        `<strong>${escapeHtml(a.instellingNaam)}</strong><br>${formatDatum(a.definitief.datum, { month: 'short' })}${a.definitief.tijd ? ' at ' + formatTijd(a.definitief.tijd) : ''}`
      );
      new maplibregl.Marker({ element: el }).setLngLat([data.lng, data.lat]).setPopup(popup).addTo(voorlichtingKaart);
      bounds.extend([data.lng, data.lat]);
    } catch (e) { /* geocoding failed for this address — just skip it, don't block */ }
  }));

  if (gevondenErgens) voorlichtingKaart.fitBounds(bounds, { padding: 40, maxZoom: 12 });
}

function renderAankomendeVoorlichtingen() {
  const vandaag = new Date().toISOString().slice(0, 10);
  const aankomend = aanvragenData
    .filter(a => a.status === 'ingepland' && a.definitief?.datum >= vandaag)
    .sort((a, b) => (a.definitief.datum + (a.definitief.tijd || '')).localeCompare(b.definitief.datum + (b.definitief.tijd || '')));

  document.getElementById('aankomend-lijst').innerHTML = aankomend.map(a => `
    <div class="activity-item" style="display:flex; justify-content:space-between; align-items:center;">
      <div style="cursor:pointer; flex:1;" onclick='toonVoorlichtingDetail(${JSON.stringify(a.id)})'>
        <div><strong>${escapeHtml(a.instellingNaam)}</strong></div>
        <div class="meta">${formatDatum(a.definitief.datum, { weekday: 'short', month: 'short' })}${a.definitief.tijd ? ' at ' + formatTijd(a.definitief.tijd) : ''} — ${a.definitief.leden.length} member(s) scheduled</div>
      </div>
      ${isCoordinator ? `<button class="secondary" onclick="aanvraagVerwijderen('${a.id}')">✕</button>` : ''}
    </div>`).join('') || '<p style="color:var(--grey); font-size:13px;">No upcoming presentations scheduled.</p>';
}

function toonVoorlichtingDetail(id) {
  const a = aanvragenData.find(x => x.id === id);
  if (!a || !a.definitief) return;
  const d = a.definitief;
  const magBewerken = isCoordinator;

  document.getElementById('vld-titel').textContent = a.instellingNaam;
  const adresTekst = a.contactpersoon || a.contactTelefoon || a.contactEmail
    ? `${escapeHtml(a.contactpersoon || '')}${a.contactTelefoon ? ' — ' + escapeHtml(a.contactTelefoon) : ''}${a.contactEmail ? ' — ' + escapeHtml(a.contactEmail) : ''}`
    : '—';
  const zoekterm = a.adres || a.instellingNaam;
  const mapsLink = zoekterm ? `<a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(zoekterm)}" target="_blank" rel="noopener">📍 Open in Maps</a>` : '';

  document.getElementById('vld-inhoud').innerHTML = `
    <p style="margin:0 0 6px;"><strong>Date &amp; time:</strong> ${formatDatum(d.datum, { weekday: 'long', month: 'long', year: true })}${d.tijd ? ' at ' + formatTijd(d.tijd) : ''} (${d.duurMinuten} min)</p>
    <p style="margin:0 0 6px;"><strong>Institution / location:</strong> ${escapeHtml(a.instellingNaam)} ${mapsLink}</p>
    ${a.adres ? `<p style="margin:0 0 6px;"><strong>Address:</strong> ${escapeHtml(a.adres)}</p>` : ''}
    <p style="margin:0 0 6px;"><strong>Contact person:</strong> ${adresTekst}</p>
    <p style="margin:0 0 6px;"><strong>Expected number of attendees:</strong> ${escapeHtml(a.aantalDeelnemers || '—')}</p>
    <p style="margin:0 0 6px;"><strong>Members giving the presentation:</strong> ${d.leden.map(l => escapeHtml(l.naam)).join(', ') || '—'}</p>
    ${d.opmerking ? `<p style="margin:0 0 6px;"><strong>Note:</strong> ${escapeHtml(d.opmerking)}</p>` : ''}
    ${a.toelichting ? `<p style="margin:0 0 6px;"><strong>Request note:</strong> ${escapeHtml(a.toelichting)}</p>` : ''}
    ${magBewerken ? `<div style="margin-top:14px; text-align:right;"><button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="aanvraagVerwijderen('${a.id}')">Delete</button> <button class="secondary" onclick="sluitModal('modal-voorlichting-detail'); openDetail('${a.id}');">Edit</button></div>` : `<p style="margin-top:10px; font-size:11.5px; color:var(--grey);">Only the Presentation Outreach Coordinator or IT can change this.</p>`}
  `;
  document.getElementById('modal-voorlichting-detail').style.display = 'flex';
}

function toonInplanFormuliermodus(zichtbaar) {
  const el = document.getElementById('inplan-sectie');
  if (!el) return;
  el.style.display = zichtbaar ? '' : 'none';
  if (zichtbaar) {
    el.style.outline = '2px solid var(--primary)';
    el.style.borderRadius = '8px';
    el.style.padding = '10px';
    setTimeout(() => { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
    setTimeout(() => { el.style.outline = 'none'; el.style.padding = '0'; }, 2000);
  }
}

function openMapsVoorInplan() {
  const adres = document.getElementById('ip-adres').value.trim() || (huidigeDetailAanvraag?.instellingNaam || '');
  if (!adres) { alert('Enter an address or institution name first.'); return; }
  window.open('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(adres), '_blank');
}

function openDetail(id) {
  const a = aanvragenData.find(x => x.id === id);
  if (!a) return;
  huidigeDetailAanvraag = a;

  document.getElementById('detail-titel').textContent = a.instellingNaam;
  document.getElementById('detail-info').innerHTML = `
    ${a.adres ? 'Address: ' + escapeHtml(a.adres) + ' <a href="https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(a.adres) + '" target="_blank" rel="noopener">📍 Open in Maps</a><br>' : ''}
    ${a.contactpersoon ? 'Contact person: ' + escapeHtml(a.contactpersoon) + '<br>' : ''}
    ${a.contactEmail ? 'Email: ' + escapeHtml(a.contactEmail) + '<br>' : ''}
    ${a.contactTelefoon ? 'Phone: ' + escapeHtml(a.contactTelefoon) + '<br>' : ''}
    ${a.gewensteDatum ? 'Preferred date: ' + escapeHtml(formatDatum(a.gewensteDatum)) + (a.gewensteTijd ? ' at ' + formatTijd(a.gewensteTijd) : '') + '<br>' : ''}
    ${a.aantalDeelnemers ? 'Expected number of attendees: ' + escapeHtml(String(a.aantalDeelnemers)) + '<br>' : ''}
    ${a.toelichting ? 'Note: ' + escapeHtml(a.toelichting) : ''}
  `;

  document.getElementById('detail-acties-open').style.display = 'none';
  document.getElementById('detail-acties-lid').style.display = 'none';
  document.getElementById('detail-ingepland').style.display = 'none';
  document.getElementById('afwijzen-form').style.display = 'none';

  if (a.status === 'ingepland' && isCoordinator) {
    // The coordinator must also be able to change an already-scheduled
    // presentation (different date, different members, etc.) — not just view it read-only.
    const d = a.definitief;
    document.getElementById('detail-ingepland').style.display = 'block';
    document.getElementById('detail-ingepland').innerHTML = `<p><span class="badge groen">Scheduled</span> <button class="secondary" onclick="toonInplanFormuliermodus(true)" style="margin-left:8px;">Change schedule</button></p>`;
    document.getElementById('detail-acties-open').style.display = 'block';
    document.querySelector('#detail-acties-open > .toolbar').style.display = 'none'; // call-out/decline no longer apply here
    document.getElementById('afwijzen-form').style.display = 'none';
    renderAanmeldingen(a);
    document.getElementById('ip-datum').value = d.datum || '';
    document.getElementById('ip-tijd').value = d.tijd || '';
    document.getElementById('ip-duur').value = d.duurMinuten || 60;
    document.getElementById('ip-adres').value = a.adres || '';
    document.getElementById('ip-contactpersoon').value = a.contactpersoon || '';
    document.getElementById('ip-telefoon').value = a.contactTelefoon || '';
    document.getElementById('ip-email').value = a.contactEmail || '';
    document.getElementById('ip-opmerking').value = d.opmerking || '';
    renderLedenKeuze(a, d.leden || []);
    // Show members not in the sign-up list (e.g. added manually earlier)
    // as an extra checked row anyway.
    (d.leden || []).forEach(l => {
      const bestaatAl = l.userId
        ? document.querySelector(`#ip-leden-lijst input[data-user-id="${CSS.escape(l.userId)}"]`)
        : [...document.querySelectorAll('#ip-leden-lijst input[data-user-id=""]')].find(cb => cb.dataset.naam === l.naam);
      if (!bestaatAl) {
        document.getElementById('ip-leden-lijst').insertAdjacentHTML('beforeend', `
          <div class="checkbox-row">
            <input type="checkbox" class="ip-lid-checkbox" data-user-id="${l.userId || ''}" data-naam="${escapeHtml(l.naam)}" checked />
            <label style="margin:0;">${escapeHtml(l.naam)}</label>
          </div>`);
      }
    });
    document.getElementById('whatsapp-link').style.display = 'none';
    toonInplanFormuliermodus(false); // starts collapsed, only shown via the "Change schedule" button
  } else if (a.status === 'ingepland') {
    const d = a.definitief;
    document.getElementById('detail-ingepland').style.display = 'block';
    document.getElementById('detail-ingepland').innerHTML = `
      <p><span class="badge groen">Scheduled</span></p>
      <p><strong>${formatDatum(d.datum)}${d.tijd ? ' at ' + formatTijd(d.tijd) : ''}</strong> (${d.duurMinuten} min)</p>
      <p>Members: ${d.leden.map(l => escapeHtml(l.naam)).join(', ') || '—'}</p>
      ${d.opmerking ? '<p>Note: ' + escapeHtml(d.opmerking) + '</p>' : ''}
    `;
  } else if (a.status === 'afgewezen') {
    document.getElementById('detail-ingepland').style.display = 'block';
    document.getElementById('detail-ingepland').innerHTML = `<p><span class="badge rood">Declined</span></p>` +
      (a.opmerkingCoordinator ? `<p>${escapeHtml(a.opmerkingCoordinator)}</p>` : '');
  } else if (isCoordinator) {
    document.getElementById('detail-acties-open').style.display = 'block';
    const toolbarEl = document.querySelector('#detail-acties-open > .toolbar');
    if (toolbarEl) toolbarEl.style.display = 'flex';
    toonInplanFormuliermodus(true); // always shown right away for a still-open request
    renderAanmeldingen(a);
    document.getElementById('ip-datum').value = a.gewensteDatum || '';
    document.getElementById('ip-adres').value = a.adres || '';
    document.getElementById('ip-tijd').value = a.gewensteTijd || '';
    document.getElementById('ip-duur').value = 60;
    document.getElementById('ip-adres').value = a.adres || '';
    document.getElementById('ip-contactpersoon').value = a.contactpersoon || '';
    document.getElementById('ip-telefoon').value = a.contactTelefoon || '';
    document.getElementById('ip-email').value = a.contactEmail || '';
    document.getElementById('ip-opmerking').value = '';
    renderLedenKeuze(a);
    if (a.oproepVerstuurd) { toonWhatsappKnop(a); } else { document.getElementById('whatsapp-link').style.display = 'none'; }
  } else {
    document.getElementById('detail-acties-lid').style.display = 'block';
    const algAangemeld = a.aanmeldingen.some(x => x.userId === eigenUserId);
    document.getElementById('btn-aanmelden').style.display = algAangemeld ? 'none' : 'inline-block';
    document.getElementById('btn-afmelden').style.display = algAangemeld ? 'inline-block' : 'none';
    if (!a.oproepVerstuurd) {
      document.getElementById('detail-acties-lid').innerHTML = '<p style="color:var(--grey); font-size:13.5px;">No call-out has been sent for this request yet.</p>';
    }
  }

  document.getElementById('modal-aanvraag-detail').style.display = 'flex';
}

function renderAanmeldingen(a) {
  const el = document.getElementById('aanmeldingen-lijst');
  if (!a.aanmeldingen.length) { el.innerHTML = a.oproepVerstuurd ? '<p style="color:var(--grey); font-size:13px;">No sign-ups yet.</p>' : ''; return; }
  el.innerHTML = '<strong style="font-size:13px;">Sign-ups:</strong><br>' +
    a.aanmeldingen.map(x => escapeHtml(x.naam)).join(', ');
}

function renderLedenKeuze(a, voorgeselecteerd) {
  const el = document.getElementById('ip-leden-lijst');
  const magChecked = (userId, naam) => voorgeselecteerd
    ? voorgeselecteerd.some(l => (l.userId && l.userId === userId) || (!l.userId && l.naam === naam))
    : true; // scheduling for the first time: check everyone who signed up by default
  if (!a.aanmeldingen.length) { el.innerHTML = '<p style="color:var(--grey); font-size:13px;">No sign-ups yet — add members manually below.</p>'; return; }
  el.innerHTML = a.aanmeldingen.map(x => `
    <div class="checkbox-row">
      <input type="checkbox" class="ip-lid-checkbox" data-user-id="${x.userId}" data-naam="${escapeHtml(x.naam)}" ${magChecked(x.userId, x.naam) ? 'checked' : ''} />
      <label style="margin:0;">${escapeHtml(x.naam)}</label>
    </div>`).join('');
}

function voegOverigLidToe() {
  const naam = document.getElementById('ip-lid-overig').value.trim();
  if (!naam) return;
  document.getElementById('ip-leden-lijst').insertAdjacentHTML('beforeend', `
    <div class="checkbox-row">
      <input type="checkbox" class="ip-lid-checkbox" data-user-id="" data-naam="${escapeHtml(naam)}" checked />
      <label style="margin:0;">${escapeHtml(naam)} <span class="badge grijs">manual</span></label>
    </div>`);
  document.getElementById('ip-lid-overig').value = '';
}

async function aanvraagVerwijderen(id) {
  if (!confirm('Completely delete this outreach request? Any linked agenda item will also be removed.')) return;
  await fetch('api_outreach.php?action=aanvraag_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  sluitModal('modal-aanvraag-detail');
  laadAanvragen();
  laadAgenda();
}

async function oproepVersturen() {
  await fetch('api_outreach.php?action=oproep_versturen&id=' + encodeURIComponent(huidigeDetailAanvraag.id), { method: 'POST', credentials: 'same-origin' });
  await laadAanvragen();
  openDetail(huidigeDetailAanvraag.id);
  toonWhatsappKnop(huidigeDetailAanvraag);
}

function toonWhatsappKnop(a) {
  const portaalLink = window.location.origin + window.location.pathname + '?tab=aanvragen';
  let bericht = `📣 Who wants to co-present at *${a.instellingNaam}*?\n`;
  if (a.gewensteDatum) bericht += `Preferred date: ${a.gewensteDatum}${a.gewensteTijd ? ' at ' + formatTijd(a.gewensteTijd) : ''}\n`;
  if (a.toelichting) bericht += `${a.toelichting}\n`;
  bericht += `\nSign up in the portal: ${portaalLink}`;

  const link = document.getElementById('whatsapp-link');
  link.href = 'https://wa.me/?text=' + encodeURIComponent(bericht);
  link.style.display = 'inline-block';
}

function toonAfwijzenForm() { document.getElementById('afwijzen-form').style.display = 'block'; }

async function aanvraagAfwijzen() {
  const opmerking = document.getElementById('afwijzen-opmerking').value;
  await fetch('api_outreach.php?action=aanvraag_afwijzen&id=' + encodeURIComponent(huidigeDetailAanvraag.id), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ opmerking }),
  });
  sluitModal('modal-aanvraag-detail');
  laadAanvragen();
}

async function aanmelden() {
  const res = await fetch('api_outreach.php?action=aanmelden&id=' + encodeURIComponent(huidigeDetailAanvraag.id), { method: 'POST', credentials: 'same-origin' });
  const data = await res.json();
  huidigeDetailAanvraag = data.aanvraag;
  const idx = aanvragenData.findIndex(x => x.id === data.aanvraag.id);
  if (idx > -1) aanvragenData[idx] = data.aanvraag;
  openDetail(data.aanvraag.id);
}
async function afmelden() {
  const res = await fetch('api_outreach.php?action=afmelden&id=' + encodeURIComponent(huidigeDetailAanvraag.id), { method: 'POST', credentials: 'same-origin' });
  const data = await res.json();
  const idx = aanvragenData.findIndex(x => x.id === data.aanvraag.id);
  if (idx > -1) aanvragenData[idx] = data.aanvraag;
  openDetail(data.aanvraag.id);
}

async function aanvraagInplannen() {
  const leden = [...document.querySelectorAll('.ip-lid-checkbox')]
    .filter(cb => cb.checked)
    .map(cb => ({ userId: cb.dataset.userId || null, naam: cb.dataset.naam }));

  const body = {
    datum: document.getElementById('ip-datum').value,
    tijd: document.getElementById('ip-tijd').value,
    duurMinuten: document.getElementById('ip-duur').value,
    adres: document.getElementById('ip-adres').value,
    contactpersoon: document.getElementById('ip-contactpersoon').value,
    contactTelefoon: document.getElementById('ip-telefoon').value,
    contactEmail: document.getElementById('ip-email').value,
    opmerking: document.getElementById('ip-opmerking').value,
    leden,
  };
  if (!body.datum) { alert('Choose a date.'); return; }

  const res = await fetch('api_outreach.php?action=aanvraag_inplannen&id=' + encodeURIComponent(huidigeDetailAanvraag.id), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Scheduling failed.'); return; }
  sluitModal('modal-aanvraag-detail');
  laadAanvragen();
  laadAgenda();
}

// ---- New (internal) request ----------------------------------------------------

function openAanvraagForm() {
  ['na-instelling','na-adres','na-contactpersoon','na-email','na-telefoon','na-datum','na-tijd','na-aantal','na-toelichting'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('modal-aanvraag-nieuw').style.display = 'flex';
}

async function nieuweAanvraagOpslaan() {
  const body = {
    instellingNaam: document.getElementById('na-instelling').value,
    adres: document.getElementById('na-adres').value,
    contactpersoon: document.getElementById('na-contactpersoon').value,
    contactEmail: document.getElementById('na-email').value,
    contactTelefoon: document.getElementById('na-telefoon').value,
    gewensteDatum: document.getElementById('na-datum').value,
    gewensteTijd: document.getElementById('na-tijd').value,
    aantalDeelnemers: document.getElementById('na-aantal').value,
    toelichting: document.getElementById('na-toelichting').value,
  };
  if (!body.instellingNaam.trim()) { alert('The institution name is required.'); return; }
  const res = await fetch('api_outreach.php?action=aanvraag_create', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Submission failed.'); return; }
  sluitModal('modal-aanvraag-nieuw');
  laadAanvragen();
}

// ---- To-Do ----------------------------------------------------

let todosData = [];
let ledenLijstVoorTaken = [];
let huidigeTodoId = null;

async function laadTodos() {
  const res = await fetch('api_outreach.php?action=todo_list', { cache: 'no-store' });
  const data = await res.json();
  todosData = data.todos;
  renderTodos();
}

function renderTodos() {
  const vandaag = new Date().toISOString().slice(0, 10);
  document.getElementById('todo-lijst').innerHTML = todosData.map(t => {
    const afgerond = t.status === 'afgerond';
    const teLaat = !afgerond && t.deadline && t.deadline < vandaag;
    return `
    <div style="display:flex; align-items:flex-start; gap:10px; padding:10px 0; border-bottom:1px solid var(--border); ${afgerond ? 'opacity:0.55;' : ''}">
      <input type="checkbox" ${afgerond ? 'checked' : ''} onchange="todoToggle('${t.id}')" style="width:auto; margin-top:3px; cursor:pointer;" />
      <div style="flex:1; min-width:0; ${afgerond ? 'text-decoration:line-through; color:var(--grey);' : ''}">
        <strong>${escapeHtml(t.titel)}</strong>
        ${t.deadline ? ` <span class="badge ${afgerond ? 'grijs' : (teLaat ? 'rood' : 'geel')}">${formatDatum(t.deadline, { month: 'short' })}</span>` : ''}
        ${t.toegewezenAan.length ? `<div style="font-size:12px; color:var(--grey); margin-top:2px;">👤 ${t.toegewezenAan.map(l => escapeHtml(l.naam)).join(', ')}</div>` : '<div style="font-size:12px; color:var(--grey); margin-top:2px;">Open task — no one assigned</div>'}
        ${t.omschrijving ? `<div style="font-size:12.5px; margin-top:4px;">${escapeHtml(t.omschrijving)}</div>` : ''}
        ${afgerond ? `<div style="font-size:11px; color:var(--grey); margin-top:2px;">Completed by ${escapeHtml(t.afgerondDoor)}</div>` : ''}
      </div>
      <div style="flex:0 0 auto; white-space:nowrap;">
        <button class="secondary" onclick='openTodoForm(${JSON.stringify(t)})'>✎</button>
        <button class="secondary" onclick="todoVerwijderen('${t.id}')">✕</button>
      </div>
    </div>`;
  }).join('') || '<p style="color:var(--grey);">No tasks yet.</p>';
}

async function todoToggle(id) {
  await fetch('api_outreach.php?action=todo_toggle&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadTodos();
}

async function todoVerwijderen(id) {
  if (!confirm('Delete this task?')) return;
  const res = await fetch('api_outreach.php?action=todo_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Deletion failed.'); return; }
  laadTodos();
}

async function openTodoForm(bestaand) {
  huidigeTodoId = bestaand ? bestaand.id : null;
  document.getElementById('todo-titel-kop').textContent = bestaand ? 'Edit task' : 'Add task';
  document.getElementById('td-titel').value = bestaand ? bestaand.titel : '';
  document.getElementById('td-omschrijving').value = bestaand ? (bestaand.omschrijving || '') : '';
  document.getElementById('td-deadline').value = bestaand ? (bestaand.deadline || '') : '';

  if (!ledenLijstVoorTaken.length) {
    const res = await fetch('api_members.php?action=profielen_overzicht', { cache: 'no-store' });
    const data = await res.json();
    ledenLijstVoorTaken = data.leden;
  }
  const toegewezenIds = (bestaand?.toegewezenAan || []).map(l => l.userId);
  document.getElementById('td-toegewezen-lijst').innerHTML = ledenLijstVoorTaken.map(l => `
    <div class="checkbox-row">
      <input type="checkbox" class="td-lid-checkbox" data-user-id="${l.id}" data-naam="${escapeHtml(l.naam)}" ${toegewezenIds.includes(l.id) ? 'checked' : ''} />
      <label style="margin:0;">${escapeHtml(l.naam)}</label>
    </div>`).join('');

  document.getElementById('modal-todo').style.display = 'flex';
}

async function todoOpslaan() {
  const toegewezenAan = [...document.querySelectorAll('.td-lid-checkbox')]
    .filter(cb => cb.checked)
    .map(cb => ({ userId: cb.dataset.userId, naam: cb.dataset.naam }));

  const body = {
    titel: document.getElementById('td-titel').value,
    omschrijving: document.getElementById('td-omschrijving').value,
    deadline: document.getElementById('td-deadline').value,
    toegewezenAan,
  };
  if (!body.titel.trim()) { alert('Title is required.'); return; }
  const url = huidigeTodoId
    ? 'api_outreach.php?action=todo_update&id=' + encodeURIComponent(huidigeTodoId)
    : 'api_outreach.php?action=todo_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Save failed.'); return; }
  sluitModal('modal-todo');
  laadTodos();
}

// ---- Shared calendar ----------------------------------------------------

let kleurenData = {};
let agendaData = [];
let huidigeKalenderMaand = new Date(); huidigeKalenderMaand.setDate(1);

async function laadKleuren() {
  const res = await fetch('api_members.php?action=kleuren_list', { cache: 'no-store' });
  const data = await res.json();
  kleurenData = data.kleuren;
}

const typeLabel = { voorlichting: 'Outreach', evenement: 'Event', district_vergadering: 'District meeting', area_vergadering: 'Area meeting', overig: 'Other' };
const typeKleurSleutel = { voorlichting: 'voorlichting_event', district_vergadering: 'district_vergadering', area_vergadering: 'area_vergadering' };
function kleurVoorItem(item) { return kleurenData[typeKleurSleutel[item.type]] || '#9aa3a0'; }

async function laadAgenda() {
  if (!Object.keys(kleurenData).length) await laadKleuren();
  const res = await fetch('api_outreach.php?action=agenda_list', { cache: 'no-store' });
  const tekst = await res.text();
  let data;
  try { data = JSON.parse(tekst); } catch (err) {
    console.error('agenda_list did not return valid JSON:', tekst);
    return;
  }
  agendaData = data.agenda;

  renderAgendaLijst();
  renderKalender();
}

function renderAgendaLijst() {
  document.getElementById('agenda-lijst').innerHTML = agendaData.map(item => {
    const kleur = kleurVoorItem(item);
    const betrokkenen = item.betrokkenen || [];
    return `
    <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:10px 0; border-bottom:1px solid var(--border); border-left:4px solid ${kleur}; padding-left:10px;">
      <div>
        <span class="badge" style="background:${kleur}22; color:${kleur};">${typeLabel[item.type] || item.type || 'Other'}</span>
        <strong style="margin-left:6px;">${escapeHtml(item.titel || '(no title)')}</strong><br>
        <span style="font-size:12.5px; color:var(--grey);">
          ${item.datum ? formatDatum(item.datum, { weekday: 'short', month: 'short', year: true }) : '(no date)'}
          ${item.tijd ? ' at ' + formatTijd(item.tijd) : ''}
          ${item.locatie ? ' — ' + escapeHtml(item.locatie) : ''}
        </span><br>
        ${item.omschrijving ? `<span style="font-size:12.5px;">${escapeHtml(item.omschrijving)}</span><br>` : ''}
        ${betrokkenen.length ? `<span style="font-size:12px; color:var(--grey);">With: ${betrokkenen.map(b => escapeHtml(b.naam || b)).join(', ')}</span>` : ''}
      </div>
      <div style="white-space:nowrap;">
        ${!item.voorlichtingId ? `<button class="secondary" onclick='openAgendaForm(${JSON.stringify(item)})'>Edit</button> ` : ''}
        <button class="secondary" onclick="agendaVerwijderen('${item.id}')">✕</button>
      </div>
    </div>`;
  }).join('') || '<p style="color:var(--grey);">No agenda items yet.</p>';
}

function wisselAgendaWeergave(v) {
  document.getElementById('agenda-kalender').style.display = v === 'kalender' ? '' : 'none';
  document.getElementById('agenda-lijst').style.display = v === 'lijst' ? '' : 'none';
  document.getElementById('btn-kalenderweergave').classList.toggle('primary', v === 'kalender');
  document.getElementById('btn-kalenderweergave').classList.toggle('secondary', v !== 'kalender');
  document.getElementById('btn-lijstweergave').classList.toggle('primary', v === 'lijst');
  document.getElementById('btn-lijstweergave').classList.toggle('secondary', v !== 'lijst');
}

function kalenderMaandWijzig(delta) {
  huidigeKalenderMaand.setMonth(huidigeKalenderMaand.getMonth() + delta);
  renderKalender();
}
function kalenderNaarVandaag() {
  huidigeKalenderMaand = new Date(); huidigeKalenderMaand.setDate(1);
  renderKalender();
}

function renderKalender() {
  const jaar = huidigeKalenderMaand.getFullYear();
  const maand = huidigeKalenderMaand.getMonth();
  document.getElementById('kalender-maand-label').textContent = huidigeKalenderMaand.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

  try {
    // Group agenda items per day (YYYY-MM-DD -> list) — items without a
    // (valid) date are ignored, instead of crashing the whole calendar on
    // one broken/older item.
    const geldigeItems = agendaData.filter(item => typeof item?.datum === 'string' && item.datum.length >= 10);
    const perDag = {};
    geldigeItems.forEach(item => { (perDag[item.datum] = perDag[item.datum] || []).push(item); });

    const eersteVanMaand = new Date(jaar, maand, 1);
    const startWeekdag = (eersteVanMaand.getDay() + 6) % 7; // 0 = Monday
    const dagenInMaand = new Date(jaar, maand + 1, 0).getDate();
    const vandaagStr = new Date().toISOString().slice(0, 10);

    let html = '<div style="display:grid; grid-template-columns:repeat(7,1fr); gap:1px; background:var(--border); border:1px solid var(--border); border-radius:8px; overflow:hidden;">';
    ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(d => {
      html += `<div style="background:var(--primary-light-95); padding:6px; text-align:center; font-size:11.5px; font-weight:600; color:var(--primary);">${d}</div>`;
    });

    const totaalCellen = startWeekdag + dagenInMaand;
    const rijen = Math.ceil(totaalCellen / 7);
    for (let i = 0; i < rijen * 7; i++) {
      const dagNr = i - startWeekdag + 1;
      if (dagNr < 1 || dagNr > dagenInMaand) { html += '<div style="background:#fafbfa; min-height:76px;"></div>'; continue; }
      const datumStr = `${jaar}-${String(maand + 1).padStart(2, '0')}-${String(dagNr).padStart(2, '0')}`;
      const items = perDag[datumStr] || [];
      const isVandaag = datumStr === vandaagStr;
      html += `<div style="background:#fff; min-height:76px; min-width:0; overflow:hidden; box-sizing:border-box; padding:4px; cursor:${items.length ? 'pointer' : 'default'};" ${items.length ? `onclick="toonDagDetail('${datumStr}')"` : ''}>
        <div style="font-size:11.5px; ${isVandaag ? 'background:var(--primary); color:#fff; border-radius:50%; width:18px; height:18px; display:flex; align-items:center; justify-content:center;' : 'color:var(--grey);'}">${dagNr}</div>
        ${items.slice(0, 3).map(item => `<div style="font-size:10px; background:${kleurVoorItem(item)}22; color:${kleurVoorItem(item)}; border-radius:3px; padding:1px 4px; margin-top:2px; width:100%; box-sizing:border-box; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(item.titel || '(no title)')}</div>`).join('')}
        ${items.length > 3 ? `<div style="font-size:9.5px; color:var(--grey); margin-top:2px;">+${items.length - 3} more</div>` : ''}
      </div>`;
    }
    html += '</div>';
    document.getElementById('kalender-grid').innerHTML = html;

    // Legend: only types that actually occur this month
    const maandPrefix = `${jaar}-${String(maand + 1).padStart(2, '0')}`;
    const itemsDezeMaand = geldigeItems.filter(i => i.datum.startsWith(maandPrefix)).sort((a, b) => (a.datum + (a.tijd || '')).localeCompare(b.datum + (b.tijd || '')));
    const gebruikteTypes = [...new Set(itemsDezeMaand.map(i => i.type))];
    document.getElementById('kalender-legenda').innerHTML = gebruikteTypes.map(t => `
      <span style="display:flex; align-items:center; gap:4px;"><span style="width:10px; height:10px; border-radius:3px; background:${kleurenData[typeKleurSleutel[t]] || '#9aa3a0'}; display:inline-block;"></span>${typeLabel[t] || t}</span>
    `).join('') || '<span>No agenda items this month.</span>';

    // Content strip: every agenda item this month as its own card with
    // title, date/time, and location — not just a colour legend.
    document.getElementById('kalender-maand-strook').innerHTML = itemsDezeMaand.map(item => {
      const kleur = kleurVoorItem(item);
      return `
      <div onclick="toonDagDetail('${item.datum}')" style="min-width:180px; max-width:220px; flex:0 0 auto; cursor:pointer; border:1px solid var(--border); border-top:3px solid ${kleur}; border-radius:8px; padding:8px 10px;">
        <div style="font-size:11px; color:var(--grey);">${formatDatum(item.datum, { weekday: 'short', month: 'short' })}${item.tijd ? ' · ' + formatTijd(item.tijd) : ''}</div>
        <div style="font-size:13px; font-weight:600; margin:2px 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(item.titel || '(no title)')}</div>
        <div style="font-size:11.5px; color:${kleur};">${typeLabel[item.type] || item.type || 'Other'}</div>
        ${item.locatie ? `<div style="font-size:11.5px; color:var(--grey); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">📍 ${escapeHtml(item.locatie)}</div>` : ''}
      </div>`;
    }).join('') || '';
  } catch (err) {
    console.error('Calendar could not be rendered:', err);
    document.getElementById('kalender-grid').innerHTML = '<p style="color:var(--status-rood);">Something went wrong showing the calendar. Try the List view, or report this to IT.</p>';
    document.getElementById('kalender-legenda').innerHTML = '';
    document.getElementById('kalender-maand-strook').innerHTML = '';
  }
}

function toonDagDetail(datumStr) {
  const items = agendaData.filter(i => i.datum === datumStr);
  document.getElementById('dd-titel').textContent = formatDatum(datumStr, { weekday: 'long', month: 'long', year: true });
  document.getElementById('dd-inhoud').innerHTML = items.map(item => {
    const kleur = kleurVoorItem(item);
    const betrokkenen = item.betrokkenen || [];
    return `
    <div style="border-left:4px solid ${kleur}; padding:6px 0 6px 10px; margin-bottom:8px;">
      <span class="badge" style="background:${kleur}22; color:${kleur};">${typeLabel[item.type] || item.type || 'Other'}</span>
      <strong style="margin-left:6px;">${escapeHtml(item.titel || '(no title)')}</strong><br>
      <span style="font-size:12.5px; color:var(--grey);">${item.tijd ? 'at ' + formatTijd(item.tijd) : ''}${item.locatie ? ' — ' + escapeHtml(item.locatie) : ''}</span><br>
      ${item.omschrijving ? `<span style="font-size:12.5px;">${escapeHtml(item.omschrijving)}</span><br>` : ''}
      ${betrokkenen.length ? `<span style="font-size:12px; color:var(--grey);">With: ${betrokkenen.map(b => escapeHtml(b.naam || b)).join(', ')}</span>` : ''}
    </div>`;
  }).join('') || '<p style="color:var(--grey);">No agenda items on this day.</p>';
  document.getElementById('modal-dag-detail').style.display = 'flex';
}

let huidigBewerkAgendaId = null;

function openAgendaForm(bestaand) {
  huidigBewerkAgendaId = bestaand ? bestaand.id : null;
  document.getElementById('ag-titel').value = bestaand ? bestaand.titel : '';
  document.getElementById('ag-datum').value = bestaand ? bestaand.datum : '';
  document.getElementById('ag-tijd').value = bestaand ? (bestaand.tijd || '') : '';
  document.getElementById('ag-locatie').value = bestaand ? (bestaand.locatie || '') : '';
  document.getElementById('ag-omschrijving').value = bestaand ? (bestaand.omschrijving || '') : '';
  document.getElementById('ag-type').value = bestaand ? bestaand.type : 'evenement';
  document.querySelector('#modal-agenda h2').textContent = bestaand ? 'Edit agenda item' : 'Add agenda item';
  document.getElementById('modal-agenda').style.display = 'flex';
}

async function agendaOpslaan() {
  const body = {
    titel: document.getElementById('ag-titel').value,
    type: document.getElementById('ag-type').value,
    datum: document.getElementById('ag-datum').value,
    tijd: document.getElementById('ag-tijd').value,
    locatie: document.getElementById('ag-locatie').value,
    omschrijving: document.getElementById('ag-omschrijving').value,
  };
  if (!body.titel.trim() || !body.datum) { alert('Title and date are required.'); return; }
  const url = huidigBewerkAgendaId
    ? 'api_outreach.php?action=agenda_update&id=' + encodeURIComponent(huidigBewerkAgendaId)
    : 'api_outreach.php?action=agenda_create';
  const res = await fetch(url, {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Save failed.'); return; }
  sluitModal('modal-agenda');
  laadAgenda();
}

async function agendaVerwijderen(id) {
  if (!confirm('Delete this agenda item?')) return;
  await fetch('api_outreach.php?action=agenda_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadAgenda();
}

(async function initPlanningAgenda() {
  await laadInstellingen();
  laadAanvragen();
  laadAgenda();
  wisselAgendaWeergave('kalender');
  laadTodos();
})();

const params = new URLSearchParams(location.search);
if (params.get('tab')) toonView(params.get('tab'));
</script>
</body>
</html>
