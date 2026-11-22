<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>PI Werkportaal — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
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
      <h3>📍 PI Locaties</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="locaties.php">Klantenbestand</a>
        <a class="sub-btn" href="locaties.php?nieuw=1">Nieuwe locatie</a>
        <a class="sub-btn" href="locaties.php?tab=bulk">Bulk-import</a>
        <a class="sub-btn" href="locaties.php?tab=campagnes">Campagnes</a>
        <a class="sub-btn" href="locaties.php?tab=targets">Targets</a>
        <a class="sub-btn" href="locaties.php?tab=campagne_ideeen">Ideeontwikkeling</a>
      </div>
    </div>

    <div class="category-card">
      <h3>📦 Drukwerk</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="drukwerk.php?tab=voorraad">Voorraad</a>
        <a class="sub-btn" href="drukwerk.php?tab=bestellingen">Bestellingen</a>
        <a class="sub-btn" href="drukwerk.php?tab=aanvragen">Aanvraag indienen</a>
        <a class="sub-btn" href="drukwerk.php?tab=leveranciers">Leveranciers &amp; prijzen</a>
      </div>
    </div>

    <div class="category-card">
      <h3>📅 Planning &amp; Agenda</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="voorlichting.php?tab=agenda">Gezamenlijke agenda</a>
        <a class="sub-btn" href="voorlichting.php?tab=voorlichting">Voorlichting</a>
        <a class="sub-btn" href="voorlichting.php?tab=todo">To-Do</a>
      </div>
    </div>

    <div class="category-card">
      <h3>💶 Financiën</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="financien.php?tab=balans">Overzicht</a>
        <a class="sub-btn" href="financien.php?tab=aanvragen">Declaratie indienen</a>
      </div>
    </div>

    <div class="category-card">
      <h3>💾 Drive-database</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="drive_database.php">Bestanden</a>
        <a class="sub-btn" href="notulen_agenda.php">Notulen &amp; Agenda's</a>
      </div>
    </div>

    <div class="category-card">
      <h3>👥 Leden</h3>
      <div class="sub-buttons">
        <a class="sub-btn" href="ledenoverzicht.php">Profielen</a>
        <a class="sub-btn" href="committee_rollen.php">Committee service rollen</a>
        <?php if (heeft_rol(['admin', 'voorzitter'])): ?>
        <a class="sub-btn" href="ledenbeheer.php">Ledenbeheer</a>
        <?php else: ?>
        <button disabled title="Alleen voor IT en voorzitter">Ledenbeheer</button>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <div class="card" style="margin-bottom:20px;">
    <div class="toolbar">
      <strong>Overzichtskaart — locaties &amp; status</strong>
      <div style="display:flex; gap:16px; align-items:center; font-size:12.5px; flex-wrap:wrap;">
        <label style="display:flex; align-items:center; gap:5px; margin:0; cursor:pointer;">
          <input type="checkbox" id="map-toggle-locaties" checked onchange="toggleLocatiesLaag()" style="width:auto;" /> Locaties met kleur
        </label>
        <label style="display:flex; align-items:center; gap:5px; margin:0; cursor:pointer;">
          <input type="checkbox" id="map-toggle-leden" checked onchange="toggleLedenLaag()" style="width:auto;" /> PI-leden
        </label>
        <label style="display:flex; align-items:center; gap:5px; margin:0; cursor:pointer;">
          <input type="checkbox" id="map-toggle-straal" onchange="toggleLedenStraal()" style="width:auto;" /> Straal om leden
          <input type="number" id="map-straal-km" value="<?= LID_REGIO_STRAAL_KM ?>" min="1" max="500" step="1" style="width:56px; padding:3px 6px;" onchange="bijwerkenLedenStraal()" />
          <span style="color:var(--grey);">km</span>
        </label>
      </div>
    </div>
    <div id="map"></div>
    <div id="map-legenda-stippen" style="margin-top:10px; font-size:12.5px; color:var(--grey);">
      <span class="status-dot donkergroen"></span>&lt; 30 dagen &nbsp;
      <span class="status-dot lichtgroen"></span>30–60 dagen &nbsp;
      <span class="status-dot geelgroen"></span>60–90 dagen &nbsp;
      <span class="status-dot geel"></span>90 dagen – 6 mnd &nbsp;
      <span class="status-dot oranjegeel"></span>6–9 mnd &nbsp;
      <span class="status-dot rood"></span>9–12 mnd &nbsp;
      <span class="status-dot donkerrood"></span>&gt; 12 mnd &nbsp;
      <span class="status-dot grijs"></span>nog geen activiteit
    </div>
  </div>

  <div class="card">
    <strong>Recente activiteit</strong>
    <div id="feed" style="margin-top:10px;"></div>
  </div>

</div>

<script>
const statusKleuren = {
  donkergroen: '#1f6e38', lichtgroen: '#6fbf73', geelgroen: '#a9c93f', geel: '#d4a417',
  oranjegeel: '#e0922f', rood: '#c9432f', donkerrood: '#7a1f1f', grijs: '#9aa3a0',
};

const map = new maplibregl.Map({
  container: 'map',
  style: '<?= MAP_STYLE_URL ?>',
  center: [<?= MAP_CENTER_LNG ?>, <?= MAP_CENTER_LAT ?>],
  zoom: <?= MAP_ZOOM ?>,
});
map.addControl(new maplibregl.NavigationControl(), 'top-right');

// Markers worden in deze arrays bijgehouden zodat de aan/uit-knoppen ze
// achteraf kunnen tonen/verbergen zonder de kaart opnieuw te laden.
let locatieMarkers = [];
let ledenMarkersEnPosities = []; // [{ marker, lat, lng }]

map.on('load', async () => {
  const res = await fetch('api_locaties.php?action=geojson');
  const geojson = await res.json();
  geojson.features.forEach(f => {
    const el = document.createElement('div');
    el.style.width = '14px'; el.style.height = '14px'; el.style.borderRadius = '50%';
    el.style.border = '2px solid white'; el.style.boxShadow = '0 0 0 1px rgba(0,0,0,.15)';
    el.style.background = statusKleuren[f.properties.statusKleur] || statusKleuren.grijs;
    el.style.cursor = 'pointer';
    const popup = new maplibregl.Popup({ offset: 12 }).setHTML(
      `<strong>${f.properties.naam}</strong><br>${f.properties.plaats || ''}<br>` +
      (f.properties.dagenSindsLaatsteActiviteit === null ? 'Nog geen activiteit' : f.properties.dagenSindsLaatsteActiviteit + ' dagen geleden actief') +
      `<br><a href="locaties.php?open=${encodeURIComponent(f.properties.id)}" style="display:inline-block; margin-top:6px; background:var(--primary); color:#fff; text-decoration:none; padding:4px 10px; border-radius:5px; font-size:12px; font-weight:600;">Bewerken</a>`
    );
    const marker = new maplibregl.Marker({ element: el }).setLngLat(f.geometry.coordinates).setPopup(popup).addTo(map);
    locatieMarkers.push(marker);
  });

  laadLedenOpKaart();
});

// ---- Aan/uit-knoppen (locatiestippen, PI-leden, straal om leden) ---------

function toggleLocatiesLaag() {
  const tonen = document.getElementById('map-toggle-locaties').checked;
  locatieMarkers.forEach(m => { m.getElement().style.display = tonen ? '' : 'none'; });
  document.getElementById('map-legenda-stippen').style.display = tonen ? '' : 'none';
}

function toggleLedenLaag() {
  const tonen = document.getElementById('map-toggle-leden').checked;
  ledenMarkersEnPosities.forEach(({ marker }) => { marker.getElement().style.display = tonen ? 'flex' : 'none'; });
  // Leden verbergen verbergt ook alles wat relatief daaraan getoond wordt.
  if (!tonen) {
    verbergWerkgebiedCirkel();
    toonLedenStraalZichtbaar(false);
  } else if (document.getElementById('map-toggle-straal').checked) {
    toonLedenStraalZichtbaar(true);
  }
}

// ---- PI-leden met een werkgebied erbij op dezelfde kaart -------------------
// Ander icoontje (blauw, initiaal) dan de groene locatie-stipjes hierboven.
// De cirkel van het bereikbare werkgebied verschijnt bewust alleen bij
// hoveren — niet continu, om de kaart rustig te houden.

const LID_REGIO_STRAAL_KM = <?= LID_REGIO_STRAAL_KM ?>;

function maakCirkelPolygon(lat, lng, straalKm, punten = 64) {
  const aardeRadiusKm = 6371;
  const coords = [];
  for (let i = 0; i <= punten; i++) {
    const hoek = (i / punten) * (2 * Math.PI);
    const dx = straalKm * Math.cos(hoek);
    const dy = straalKm * Math.sin(hoek);
    const deltaLat = (dy / aardeRadiusKm) * (180 / Math.PI);
    const deltaLng = (dx / (aardeRadiusKm * Math.cos((lat * Math.PI) / 180))) * (180 / Math.PI);
    coords.push([lng + deltaLng, lat + deltaLat]);
  }
  return { type: 'Feature', geometry: { type: 'Polygon', coordinates: [coords] } };
}

const CIRKEL_BRON_ID = 'lid-werkgebied-cirkel';
function toonWerkgebiedCirkel(lat, lng) {
  const data = maakCirkelPolygon(lat, lng, LID_REGIO_STRAAL_KM);
  if (map.getSource(CIRKEL_BRON_ID)) {
    map.getSource(CIRKEL_BRON_ID).setData(data);
    map.setLayoutProperty(CIRKEL_BRON_ID + '-fill', 'visibility', 'visible');
    map.setLayoutProperty(CIRKEL_BRON_ID + '-lijn', 'visibility', 'visible');
  } else {
    map.addSource(CIRKEL_BRON_ID, { type: 'geojson', data });
    map.addLayer({ id: CIRKEL_BRON_ID + '-fill', type: 'fill', source: CIRKEL_BRON_ID, paint: { 'fill-color': '#3b6fa0', 'fill-opacity': 0.5 } });
    map.addLayer({ id: CIRKEL_BRON_ID + '-lijn', type: 'line', source: CIRKEL_BRON_ID, paint: { 'line-color': '#3b6fa0', 'line-width': 1.5, 'line-opacity': 0.7 } });
  }
}
function verbergWerkgebiedCirkel() {
  if (map.getLayer(CIRKEL_BRON_ID + '-fill')) map.setLayoutProperty(CIRKEL_BRON_ID + '-fill', 'visibility', 'none');
  if (map.getLayer(CIRKEL_BRON_ID + '-lijn')) map.setLayoutProperty(CIRKEL_BRON_ID + '-lijn', 'visibility', 'none');
}

// ---- Instelbare straal om ALLE leden heen, los van de hover-cirkel -------
// Eigen aan/uit-knop en eigen km-waarde; blijft staan onafhankelijk van
// waar de muis zich bevindt.

const LEDEN_STRAAL_BRON_ID = 'leden-straal-cirkels';

function herbouwLedenStraalLaag() {
  const km = parseFloat(document.getElementById('map-straal-km').value) || 0;
  const data = {
    type: 'FeatureCollection',
    features: ledenMarkersEnPosities.map(({ lat, lng }) => maakCirkelPolygon(lat, lng, km)),
  };
  if (map.getSource(LEDEN_STRAAL_BRON_ID)) {
    map.getSource(LEDEN_STRAAL_BRON_ID).setData(data);
  } else {
    map.addSource(LEDEN_STRAAL_BRON_ID, { type: 'geojson', data });
    map.addLayer({ id: LEDEN_STRAAL_BRON_ID + '-fill', type: 'fill', source: LEDEN_STRAAL_BRON_ID, paint: { 'fill-color': '#3b6fa0', 'fill-opacity': 0.12 } });
    map.addLayer({ id: LEDEN_STRAAL_BRON_ID + '-lijn', type: 'line', source: LEDEN_STRAAL_BRON_ID, paint: { 'line-color': '#3b6fa0', 'line-width': 1.5, 'line-opacity': 0.5 } });
  }
}

function toonLedenStraalZichtbaar(zichtbaar) {
  if (!map.getLayer(LEDEN_STRAAL_BRON_ID + '-fill')) return;
  const v = zichtbaar ? 'visible' : 'none';
  map.setLayoutProperty(LEDEN_STRAAL_BRON_ID + '-fill', 'visibility', v);
  map.setLayoutProperty(LEDEN_STRAAL_BRON_ID + '-lijn', 'visibility', v);
}

function toggleLedenStraal() {
  const tonen = document.getElementById('map-toggle-straal').checked;
  if (tonen) { herbouwLedenStraalLaag(); toonLedenStraalZichtbaar(true); }
  else toonLedenStraalZichtbaar(false);
}

function bijwerkenLedenStraal() {
  if (document.getElementById('map-toggle-straal').checked) herbouwLedenStraalLaag();
}

async function laadLedenOpKaart() {
  const res = await fetch('api_leden.php?action=profielen_overzicht', { cache: 'no-store' });
  const data = await res.json();
  const metRegio = data.leden.filter(l => l.regio && l.regio.trim());

  await Promise.all(metRegio.map(async (l) => {
    try {
      const geoRes = await fetch('api_locaties.php?action=geocode&adres=' + encodeURIComponent(l.regio), { cache: 'no-store' });
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
        `<strong>${l.naam}</strong><br><span style="font-size:11.5px; color:#666;">${l.roles.map(r => data.rollen[r] || r).join(', ')}</span><br>📍 ${l.regio}<br><a href="profiel.php?id=${encodeURIComponent(l.id)}" style="font-size:11.5px;">Bekijk profiel →</a>`
      );

      // Cirkel verschijnt bij hoveren (even snel zien waar iemands gebied is),
      // maar het infovenster gaat pas open bij een klik — anders verdwijnt
      // het venster zodra je je muis richting "Bekijk profiel" beweegt.
      let popupOpen = false;
      el.addEventListener('mouseenter', () => toonWerkgebiedCirkel(geo.lat, geo.lng));
      el.addEventListener('mouseleave', () => verbergWerkgebiedCirkel());
      el.addEventListener('click', (e) => {
        e.stopPropagation();
        popupOpen = !popupOpen;
        if (popupOpen) popup.setLngLat([geo.lng, geo.lat]).addTo(map);
        else popup.remove();
      });
      popup.on('close', () => { popupOpen = false; });

      const marker = new maplibregl.Marker({ element: el }).setLngLat([geo.lng, geo.lat]).addTo(map);
      if (!document.getElementById('map-toggle-leden').checked) el.style.display = 'none';
      ledenMarkersEnPosities.push({ marker, lat: geo.lat, lng: geo.lng });
    } catch (e) { /* geocoding mislukt voor deze regio — gewoon overslaan */ }
  }));

  // Alle leden zijn nu gegeocodeerd en staan in ledenMarkersEnPosities —
  // bouw de instelbare straal-laag nu op als de knop al aanstond.
  if (document.getElementById('map-toggle-straal').checked) {
    herbouwLedenStraalLaag();
    toonLedenStraalZichtbaar(true);
  }
}

let datumformaatVolgorde = 'dmy'; // 'dmy' | 'mdy' | 'ymd'
const NL_MAANDEN_KORT = ['jan','feb','mrt','apr','mei','jun','jul','aug','sep','okt','nov','dec'];
function formatDatumKort(iso) {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const dagNr = d.getDate();
  const maand = NL_MAANDEN_KORT[d.getMonth()];
  return datumformaatVolgorde === 'mdy' ? `${maand} ${dagNr}` : `${dagNr} ${maand}`;
}

async function laadFeed() {
  const instRes = await fetch('api_leden.php?action=instellingen_get', { cache: 'no-store' });
  const instData = await instRes.json();
  datumformaatVolgorde = instData.instellingen.datumformaat || 'dmy';

  const res = await fetch('api_locaties.php?action=feed');
  const data = await res.json();
  const el = document.getElementById('feed');
  if (!data.items.length) { el.innerHTML = '<p style="color:var(--grey); font-size:13px;">Nog geen activiteit geregistreerd.</p>'; return; }
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
