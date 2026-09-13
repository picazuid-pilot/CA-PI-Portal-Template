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
<title>PI Work Portal — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.css" rel="stylesheet" />
<script src="https://unpkg.com/maplibre-gl@3.6.2/dist/maplibre-gl.js"></script>
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="grid-6">

    <div class="category-card">
      <h3>📍 PI Locations</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="locations.php">Customer directory</a>
        <a class="sub-btn" href="locations.php?nieuw=1">New location</a>
        <a class="sub-btn" href="locations.php?tab=bulk">Bulk import</a>
        <a class="sub-btn" href="locations.php?tab=campagnes">Campaigns</a>
        <a class="sub-btn" href="locations.php?tab=targets">Targets</a>
        <a class="sub-btn" href="locations.php?tab=campagne_ideeen">Campaign ideas</a>
      </div>
    </div>

    <div class="category-card">
      <h3>📦 Print</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="print.php?tab=voorraad">Stock</a>
        <a class="sub-btn" href="print.php?tab=bestellingen">Orders</a>
        <a class="sub-btn" href="print.php?tab=aanvragen">Submit a request</a>
        <a class="sub-btn" href="print.php?tab=leveranciers">Suppliers &amp; pricing</a>
      </div>
    </div>

    <div class="category-card">
      <h3>📅 Planning &amp; Calendar</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="outreach.php?tab=agenda">Shared calendar</a>
        <a class="sub-btn" href="outreach.php?tab=voorlichting">Outreach</a>
        <a class="sub-btn" href="outreach.php?tab=todo">To-do</a>
      </div>
    </div>

    <div class="category-card">
      <h3>💶 Finance</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="finance.php?tab=balans">Overview</a>
        <a class="sub-btn" href="finance.php?tab=aanvragen">Submit a reimbursement</a>
      </div>
    </div>

    <div class="category-card">
      <h3>💾 Drive Database</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="drive_files.php">Files</a>
        <a class="sub-btn" href="minutes_agendas.php">Minutes &amp; Agendas</a>
      </div>
    </div>

    <div class="category-card">
      <h3>👥 Members</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="members.php">Profiles</a>
        <a class="sub-btn" href="committee_roles.php">Committee Service Roles</a>
        <?php if (heeft_rol(['admin'])): ?>
        <a class="sub-btn" href="member_management.php">Member Management</a>
        <?php else: ?>
        <button disabled title="IT/admin only">Member Management (IT)</button>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <div class="card" style="margin-bottom:20px;">
    <div class="toolbar"><strong>Overview map — locations &amp; status</strong></div>
    <div id="map"></div>
    <div style="margin-top:10px; font-size:12.5px; color:var(--grey);">
      <span class="status-dot groen"></span>&lt; 30 days &nbsp;
      <span class="status-dot geel"></span>30–60 days &nbsp;
      <span class="status-dot oranje"></span>60–90 days &nbsp;
      <span class="status-dot rood"></span>&gt; 90 days &nbsp;
      <span class="status-dot grijs"></span>no activity yet
    </div>
  </div>

  <div class="card">
    <strong>Recent activity</strong>
    <div id="feed" style="margin-top:10px;"></div>
  </div>

</div>

<script>
const statusKleuren = { groen: '#2f8f4e', geel: '#d4a417', oranje: '#e07a2f', rood: '#c9432f', grijs: '#9aa3a0' };

const map = new maplibregl.Map({
  container: 'map',
  style: '<?= MAP_STYLE_URL ?>',
  center: [<?= MAP_CENTER_LNG ?>, <?= MAP_CENTER_LAT ?>],
  zoom: <?= MAP_ZOOM ?>,
});
map.addControl(new maplibregl.NavigationControl(), 'top-right');

map.on('load', async () => {
  const res = await fetch('api_locations.php?action=geojson');
  const geojson = await res.json();
  geojson.features.forEach(f => {
    const el = document.createElement('div');
    el.style.width = '14px'; el.style.height = '14px'; el.style.borderRadius = '50%';
    el.style.border = '2px solid white'; el.style.boxShadow = '0 0 0 1px rgba(0,0,0,.15)';
    el.style.background = statusKleuren[f.properties.statusKleur] || statusKleuren.grijs;
    el.style.cursor = 'pointer';
    const popup = new maplibregl.Popup({ offset: 12 }).setHTML(
      `<strong>${f.properties.naam}</strong><br>${f.properties.plaats || ''}<br>` +
      (f.properties.dagenSindsLaatsteActiviteit === null ? 'No activity yet' : f.properties.dagenSindsLaatsteActiviteit + ' days since last activity') +
      `<br><a href="locations.php?open=${encodeURIComponent(f.properties.id)}" style="display:inline-block; margin-top:6px; background:var(--primary); color:#fff; text-decoration:none; padding:4px 10px; border-radius:5px; font-size:12px; font-weight:600;">Edit</a>`
    );
    new maplibregl.Marker({ element: el }).setLngLat(f.geometry.coordinates).setPopup(popup).addTo(map);
  });

  loadMembersOnMap();
});

// ---- PI members with a coverage region also shown on this map -------------------
// A different icon (blue, with initial) than the green location dots above.
// The coverage-area circle deliberately only appears on hover — not
// permanently — to keep the map uncluttered.

const MEMBER_REGION_RADIUS_KM_JS = <?= MEMBER_REGION_RADIUS_KM ?>;

function makeCirclePolygon(lat, lng, radiusKm, points = 64) {
  const earthRadiusKm = 6371;
  const coords = [];
  for (let i = 0; i <= points; i++) {
    const angle = (i / points) * (2 * Math.PI);
    const dx = radiusKm * Math.cos(angle);
    const dy = radiusKm * Math.sin(angle);
    const deltaLat = (dy / earthRadiusKm) * (180 / Math.PI);
    const deltaLng = (dx / (earthRadiusKm * Math.cos((lat * Math.PI) / 180))) * (180 / Math.PI);
    coords.push([lng + deltaLng, lat + deltaLat]);
  }
  return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [coords] } };
}

const CIRCLE_SOURCE_ID = 'member-coverage-circle';
function showCoverageCircle(lat, lng) {
  const data = makeCirclePolygon(lat, lng, MEMBER_REGION_RADIUS_KM_JS);
  if (map.getSource(CIRCLE_SOURCE_ID)) {
    map.getSource(CIRCLE_SOURCE_ID).setData(data);
    map.setLayoutProperty(CIRCLE_SOURCE_ID + '-fill', 'visibility', 'visible');
    map.setLayoutProperty(CIRCLE_SOURCE_ID + '-line', 'visibility', 'visible');
  } else {
    map.addSource(CIRCLE_SOURCE_ID, { type: 'geojson', data });
    map.addLayer({ id: CIRCLE_SOURCE_ID + '-fill', type: 'fill', source: CIRCLE_SOURCE_ID, paint: { 'fill-color': '#3b6fa0', 'fill-opacity': 0.5 } });
    map.addLayer({ id: CIRCLE_SOURCE_ID + '-line', type: 'line', source: CIRCLE_SOURCE_ID, paint: { 'line-color': '#3b6fa0', 'line-width': 1.5, 'line-opacity': 0.7 } });
  }
}
function hideCoverageCircle() {
  if (map.getLayer(CIRCLE_SOURCE_ID + '-fill')) map.setLayoutProperty(CIRCLE_SOURCE_ID + '-fill', 'visibility', 'none');
  if (map.getLayer(CIRCLE_SOURCE_ID + '-line')) map.setLayoutProperty(CIRCLE_SOURCE_ID + '-line', 'visibility', 'none');
}

async function loadMembersOnMap() {
  const res = await fetch('api_members.php?action=profielen_overzicht', { cache: 'no-store' });
  const data = await res.json();
  const withRegion = data.leden.filter(l => l.regio && l.regio.trim());

  await Promise.all(withRegion.map(async (l) => {
    try {
      const geoRes = await fetch('api_locations.php?action=geocode&adres=' + encodeURIComponent(l.regio), { cache: 'no-store' });
      const geo = await geoRes.json();
      if (!geo.gevonden) return;

      const el = document.createElement('div');
      el.style.width = '26px'; el.style.height = '26px'; el.style.borderRadius = '50%';
      el.style.background = '#3b6fa0'; el.style.border = '2px solid white'; el.style.boxShadow = '0 0 0 1px rgba(0,0,0,.2)';
      el.style.display = 'flex'; el.style.alignItems = 'center'; el.style.justifyContent = 'center';
      el.style.color = 'white'; el.style.fontSize = '11px'; el.style.fontWeight = '700'; el.style.cursor = 'pointer';
      el.textContent = l.naam.charAt(0).toUpperCase();
      el.title = l.naam + ' — ' + l.regio;

      const popup = new maplibregl.Popup({ offset: 15 }).setHTML(
        `<strong>${l.naam}</strong><br><span style="font-size:11.5px; color:#666;">${l.roles.map(r => data.rollen[r] || r).join(', ')}</span><br>📍 ${l.regio}<br><a href="profile.php?id=${encodeURIComponent(l.id)}" style="font-size:11.5px;">View profile →</a>`
      );

      // The circle appears on hover (a quick way to see someone's coverage
      // area), but the info popup only opens on click — otherwise it would
      // vanish the moment you move your mouse toward "View profile".
      let popupOpen = false;
      el.addEventListener('mouseenter', () => showCoverageCircle(geo.lat, geo.lng));
      el.addEventListener('mouseleave', () => hideCoverageCircle());
      el.addEventListener('click', (e) => {
        e.stopPropagation();
        popupOpen = !popupOpen;
        if (popupOpen) popup.setLngLat([geo.lng, geo.lat]).addTo(map);
        else popup.remove();
      });
      popup.on('close', () => { popupOpen = false; });

      new maplibregl.Marker({ element: el }).setLngLat([geo.lng, geo.lat]).addTo(map);
    } catch (e) { /* geocoding failed for this region — just skip it */ }
  }));
}

let datumformaatVolgorde = 'dmy'; // 'dmy' | 'mdy' | 'ymd'
const EN_MONTHS_SHORT = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function formatDatumKort(iso) {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const dayNr = d.getDate();
  const month = EN_MONTHS_SHORT[d.getMonth()];
  return datumformaatVolgorde === 'mdy' ? `${month} ${dayNr}` : `${dayNr} ${month}`;
}

async function laadFeed() {
  const instRes = await fetch('api_members.php?action=instellingen_get', { cache: 'no-store' });
  const instData = await instRes.json();
  datumformaatVolgorde = instData.instellingen.datumformaat || 'dmy';

  const res = await fetch('api_locations.php?action=feed');
  const data = await res.json();
  const el = document.getElementById('feed');
  if (!data.items.length) { el.innerHTML = '<p style="color:var(--grey); font-size:13px;">No activity recorded yet.</p>'; return; }
  el.innerHTML = data.items.map(i => `
    <div class="feed-item">
      <span class="tijd">${formatDatumKort(i.tijd)}</span>
      <span>${i.tekst}</span>
    </div>
  `).join('');
}
laadFeed();
</script>
</body>
</html>
