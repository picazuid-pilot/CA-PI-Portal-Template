<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isCoordinator = heeft_rol(['admin', 'coordinator_distribution']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Print — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/papaparse@5.4.1/papaparse.min.js"></script>
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <div style="display:flex; gap:6px; flex-wrap:wrap;">
      <button class="secondary" onclick="toonView('voorraad')">Stock</button>
      <button class="secondary" onclick="toonView('aanvragen')">Requests</button>
      <button class="secondary" onclick="toonView('bestellingen')">Orders</button>
      <button class="secondary" onclick="toonView('pakketten')">Packages</button>
      <button class="secondary" onclick="toonView('leveranciers')">Suppliers &amp; pricing</button>
    </div>
  </div>

  <!-- ===== Stock ===== -->
  <div id="view-voorraad" class="card">
    <div class="toolbar">
      <strong>Regional stock</strong>
      <?php if ($isCoordinator): ?><button class="primary" onclick="openVoorraadItem()">+ Add item</button><?php endif; ?>
    </div>
    <table>
      <thead><tr><th>Preview</th><th>Item</th><th>Stock</th><th>Minimum</th><th>Value/unit</th><th>Total value</th><th></th><th></th></tr></thead>
      <tbody id="voorraad-body"><tr><td colspan="8">Loading…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Requests ===== -->
  <div id="view-aanvragen" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Print requests</strong>
      <div class="right">
        <button class="secondary" onclick="kopieerExterneLink()">🔗 Copy external order link</button>
        <button class="primary" onclick="openAanvraagForm()">+ Submit request</button>
      </div>
    </div>
    <table>
      <thead><tr><th>Requester</th><th>Items</th><th>Weight</th><th>Delivery address</th><th>Status</th><th>Date</th><th></th></tr></thead>
      <tbody id="aanvragen-body"><tr><td colspan="6">Loading…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Orders ===== -->
  <div id="view-bestellingen" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Orders from suppliers</strong>
      <button class="primary" onclick="openBestellingForm()">+ New order</button>
    </div>
    <table>
      <thead><tr><th>Supplier</th><th>Product</th><th>Quantity</th><th>Price</th><th>Status</th><th>Ordered by</th><th></th></tr></thead>
      <tbody id="bestellingen-body"><tr><td colspan="7">Loading…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Packages ===== -->
  <div id="view-pakketten" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Standard packages</strong>
      <?php if ($isCoordinator): ?>
      <div class="right">
        <a class="btn-primary" href="templates/drukwerk_pakketten_sjabloon.csv" download>⬇ CSV template</a>
        <button class="secondary" onclick="document.getElementById('pakketCsvInput').click()">⬆ Bulk import</button>
        <input type="file" id="pakketCsvInput" accept=".csv" style="display:none;" />
        <button class="primary" onclick="openPakketForm()">+ Add package</button>
      </div>
      <?php endif; ?>
    </div>
    <p style="color:var(--grey); font-size:12.5px;">Anyone can use these packages as a shortcut when submitting a print request — saves checking off dozens of items individually.</p>
    <div id="pakketten-lijst"></div>
  </div>

  <!-- ===== Suppliers & pricing ===== -->
  <div id="view-leveranciers" class="card" style="display:none;">
    <div class="toolbar">
      <strong>Suppliers &amp; pricing</strong>
      <?php if ($isCoordinator): ?>
      <div class="right">
        <a class="btn-primary" href="templates/drukwerk_leveranciers_sjabloon.csv" download>⬇ CSV template</a>
        <button class="secondary" onclick="document.getElementById('leverancierCsvInput').click()">⬆ Bulk import</button>
        <input type="file" id="leverancierCsvInput" accept=".csv" style="display:none;" />
        <button class="primary" onclick="openLeverancierForm()">+ Add price</button>
      </div>
      <?php endif; ?>
    </div>
    <p style="color:var(--grey); font-size:12.5px;">For products with multiple variants (e.g. 22 different tri-fold designs), the total amount for <em>all</em> variants combined is shown in brackets — calculated automatically, not entered separately.</p>
    <div id="leveranciers-tabel"></div>
  </div>

</div>

<!-- ===== Modal: stock item ===== -->
<div id="modal-voorraad" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-voorraad')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-voorraad')">✕</button>
    <h2 id="voorraad-titel">Stock item</h2>
    <label>Name</label><input id="v-naam" placeholder="e.g. Flyer A5 - What is CA" />
    <label>Unit</label><input id="v-eenheid" value="pieces" />
    <div class="form-grid">
      <div><label>Current stock</label><input id="v-huidig" type="number" min="0" /></div>
      <div><label>Minimum stock (warning threshold)</label><input id="v-minimum" type="number" min="0" /></div>
    </div>
    <label>Value per unit (in your local currency, what you paid for it at the time)</label>
    <input id="v-waarde" type="number" step="0.001" min="0" placeholder="e.g. 0.046" />
    <div class="form-grid">
      <div><label>Weight (grams, optional)</label><input id="v-gewicht" type="number" step="0.01" min="0" placeholder="e.g. 500" /></div>
      <div><label>...per how many units?</label><input id="v-gewicht-per" type="number" min="1" value="1" placeholder="e.g. 100" /></div>
    </div>
    <p style="font-size:11px; color:var(--grey); margin-top:-6px;">E.g. "500 grams per 100 units" for thin flyers — avoids tiny decimal weights per single unit.</p>
    <label>Note</label><textarea id="v-notitie" rows="2"></textarea>
    <div style="margin-top:14px; display:flex; justify-content:space-between; align-items:center;">
      <button id="v-geschiedenis-knop" class="secondary" style="display:none;" onclick="openVoorraadGeschiedenis()">🕘 History</button>
      <div style="display:flex; gap:8px; margin-left:auto;">
        <button class="secondary" onclick="sluitModal('modal-voorraad')">Cancel</button>
        <button class="primary" onclick="opslaanVoorraad()">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== Modal: stock history ===== -->
<div id="modal-voorraad-geschiedenis" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-voorraad-geschiedenis')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-voorraad-geschiedenis')">✕</button>
    <h2 id="vg-titel">History</h2>
    <p style="color:var(--grey); font-size:12.5px; margin:0 0 12px;">Every addition and deduction for this item: approved requests, received orders, and manual adjustments.</p>
    <div id="vg-lijst" style="max-height:420px; overflow-y:auto;"></div>
  </div>
</div>

<!-- ===== Modal: submit request ===== -->
<div id="modal-aanvraag" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-aanvraag')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-aanvraag')">✕</button>
    <h2 id="aanvraag-modal-titel">Request print materials</h2>
    <label>Quick-fill with a standard package (optional)</label>
    <select id="aan-pakket" onchange="pasPakketToe(this.value)">
      <option value="">— choose a package, or check off individual items below —</option>
    </select>
    <label>Delivery address</label>
    <input id="aan-adres" placeholder="Street + number, postal code, city" />
    <label>What do you need?</label>
    <div id="aan-items-lijst"></div>
    <div id="aan-gewicht-totaal" style="font-size:12px; color:var(--grey); margin-top:6px;"></div>
    <div style="display:flex; gap:6px; margin-top:8px;">
      <input id="aan-overig-naam" placeholder="Other item (not in the list)" style="flex:2;" />
      <input id="aan-overig-aantal" type="number" min="1" placeholder="quantity" style="flex:1;" oninput="werkAanvraagGewichtBij()" />
    </div>
    <label>Note (optional)</label><textarea id="aan-reden" rows="2" placeholder="e.g. for my own region/meeting"></textarea>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="sluitModal('modal-aanvraag')">Cancel</button>
      <button class="primary" onclick="opslaanAanvraag()">Submit request</button>
    </div>
  </div>
</div>

<!-- ===== Modal: review request ===== -->
<div id="modal-besluit" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-besluit')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-besluit')">✕</button>
    <h2 id="besluit-titel">Review request</h2>
    <p id="besluit-samenvatting" style="color:var(--grey); font-size:13.5px;"></p>
    <label>Note (optional, goes to the requester)</label>
    <textarea id="besluit-opmerking" rows="2"></textarea>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="besluitNemen('afgewezen')" style="color:#c9432f; border-color:#c9432f;">Reject</button>
      <button class="primary" onclick="besluitNemen('goedgekeurd')">Approve</button>
    </div>
  </div>
</div>

<!-- ===== Modal: new order ===== -->
<div id="modal-bestelling" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-bestelling')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-bestelling')">✕</button>
    <h2>New order</h2>
    <label>Supplier</label><input id="b-leverancier" />
    <label>Product</label><input id="b-product" />
    <div class="form-grid">
      <div><label>Quantity</label><input id="b-aantal" type="number" min="1" /></div>
      <div><label>Total price (optional)</label><input id="b-prijs" type="number" step="0.01" min="0" /></div>
    </div>
    <label>Note</label><textarea id="b-notitie" rows="2"></textarea>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="sluitModal('modal-bestelling')">Cancel</button>
      <button class="primary" onclick="opslaanBestelling()">Register order</button>
    </div>
  </div>
</div>

<!-- ===== Modal: edit supplier/price ===== -->
<div id="modal-leverancier" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-leverancier')">
  <div class="modal" style="max-width:640px;">
    <button class="modal-close" onclick="sluitModal('modal-leverancier')">✕</button>
    <h2 id="leverancier-titel">Add price</h2>
    <div class="form-grid">
      <div><label>Supplier</label><input id="l-leverancier" /></div>
      <div><label>Product / format</label><input id="l-product" /></div>
      <div><label>Number of variants (e.g. 22 for tri-fold)</label><input id="l-varianten" type="number" min="1" value="1" /></div>
      <div><label>Link to product page (optional)</label><input id="l-link" /></div>
    </div>
    <table style="margin-top:12px;">
      <thead><tr><th>Quantity</th><th>Total price</th><th>Price per unit</th></tr></thead>
      <tbody>
        <tr><td>500</td><td><input id="l-500-totaal" type="number" step="0.01" min="0" oninput="berekenPerStuk(500)" /></td><td><input id="l-500-perstuk" type="number" step="0.0001" min="0" /></td></tr>
        <tr><td>1,000</td><td><input id="l-1000-totaal" type="number" step="0.01" min="0" oninput="berekenPerStuk(1000)" /></td><td><input id="l-1000-perstuk" type="number" step="0.0001" min="0" /></td></tr>
        <tr><td>2,500</td><td><input id="l-2500-totaal" type="number" step="0.01" min="0" oninput="berekenPerStuk(2500)" /></td><td><input id="l-2500-perstuk" type="number" step="0.0001" min="0" /></td></tr>
        <tr><td>5,000</td><td><input id="l-5000-totaal" type="number" step="0.01" min="0" oninput="berekenPerStuk(5000)" /></td><td><input id="l-5000-perstuk" type="number" step="0.0001" min="0" /></td></tr>
      </tbody>
    </table>
    <p style="font-size:11px; color:var(--grey); margin-top:4px;">Price per unit is calculated automatically (total price ÷ quantity). You can still adjust/round it manually afterwards.</p>
    <label>Note</label><textarea id="l-notitie" rows="2"></textarea>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="sluitModal('modal-leverancier')">Cancel</button>
      <button class="primary" onclick="opslaanLeverancier()">Save</button>
    </div>
  </div>
</div>

<!-- ===== Modal: edit package ===== -->
<div id="modal-pakket" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-pakket')">
  <div class="modal" style="max-width:600px;">
    <button class="modal-close" onclick="sluitModal('modal-pakket')">✕</button>
    <h2 id="pakket-titel">Add package</h2>
    <div class="form-grid">
      <div><label>Name</label><input id="p-naam" placeholder="e.g. Standard PI Package" /></div>
      <div><label>Type</label>
        <select id="p-type">
          <option value="pi_pakket">PI Package (PI member's personal supply)</option>
          <option value="literatuur_pakket">Literature package (leaflet racks)</option>
        </select>
      </div>
    </div>
    <label>Items (one per line: "quantity, name")</label>
    <textarea id="p-items" rows="8" placeholder="240, A5 Flyers&#10;12, A3 Posters&#10;12, Business card with PI email address"></textarea>
    <div class="form-grid" style="margin-top:8px;">
      <div><label>Price per package — high</label><input id="p-prijs-hoog" type="number" step="0.01" min="0" /></div>
      <div><label>Price per package — medium</label><input id="p-prijs-middel" type="number" step="0.01" min="0" /></div>
      <div><label>Price per package — low</label><input id="p-prijs-laag" type="number" step="0.01" min="0" /></div>
    </div>
    <p style="color:var(--grey); font-size:11.5px;">High/medium/low = price depending on how many packages are ordered from the printer at once — purely for budgeting reference, the requester doesn't choose anything here.</p>
    <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:8px;">
      <button class="secondary" onclick="sluitModal('modal-pakket')">Cancel</button>
      <button class="primary" onclick="opslaanPakket()">Save</button>
    </div>
  </div>
</div>

<script>
const isCoordinator = <?= $isCoordinator ? 'true' : 'false' ?>;
const eigenUserId = <?= json_encode($_SESSION['user_id']) ?>;
let voorraadData = [];
let pakkettenData = [];
let huidigVoorraadId = null;
let huidigBesluitAanvraagId = null;
let huidigeAanvraagId = null;
let huidigLeverancierId = null;
let huidigPakketId = null;

function toonView(v) {
  ['voorraad','aanvragen','bestellingen','pakketten','leveranciers'].forEach(x => document.getElementById('view-' + x).style.display = x === v ? '' : 'none');
}
function sluitModal(id) { document.getElementById(id).style.display = 'none'; }
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

let datumformaatVolgorde = 'dmy';
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
async function laadInstellingenPrint() {
  const res = await fetch('api_members.php?action=instellingen_get', { cache: 'no-store' });
  const data = await res.json();
  datumformaatVolgorde = data.instellingen.datumformaat || 'dmy';
}
function euro(v) { return v === null || v === undefined ? '—' : Number(v).toFixed(2); }

// ---- Weight ----------------------------------------------------

function gewichtPerStukVan(item) {
  if (!item || item.gewicht === null || item.gewicht === undefined) return null;
  return item.gewicht / (item.gewichtPerAantal || 1);
}

function formatGewicht(gram) {
  if (gram === null || gram === undefined) return null;
  return gram >= 1000 ? (gram / 1000).toLocaleString('en-GB', { maximumFractionDigits: 2 }) + ' kg' : Math.round(gram) + ' g';
}

// Total weight of a list of request items ({naam, aantal}) — looks up each
// item's weight per unit in the stock list (by name, same as the stock
// deduction itself). Items with no known weight don't count towards the
// total, but are reported separately so it never becomes an unnoticed gap.
function berekenAanvraagGewicht(items) {
  let totaal = 0;
  let onbekend = [];
  items.forEach(it => {
    const voorraadItem = voorraadData.find(v => v.naam.toLowerCase() === it.naam.toLowerCase());
    const perStuk = voorraadItem ? gewichtPerStukVan(voorraadItem) : null;
    if (perStuk !== null) totaal += perStuk * it.aantal;
    else onbekend.push(it.naam);
  });
  return { totaal, onbekend };
}

function kopieerExterneLink() {
  const link = window.location.origin + window.location.pathname.replace(/print\.php$/, '') + 'external_request.php';
  navigator.clipboard.writeText(link).then(
    () => alert('Link copied to clipboard:\n' + link),
    () => prompt('Could not copy automatically — copy this link manually:', link)
  );
}

// ---- Stock ----------------------------------------------------

async function laadVoorraad() {
  const res = await fetch('api_print.php?action=voorraad_list');
  const data = await res.json();
  voorraadData = data.voorraad;
  document.getElementById('voorraad-body').innerHTML = voorraadData.map(v => {
    const laag = v.huidigeVoorraad <= v.minimumVoorraad;
    const totaal = v.waardePerStuk !== null ? v.huidigeVoorraad * v.waardePerStuk : null;
    return `<tr>
      <td>
        ${renderVoorraadThumbnail(v)}
        ${isCoordinator ? `<input type="file" id="vthumb-input-${v.id}" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.pdf" style="display:none;" onchange="voorraadThumbnailUploaden('${v.id}', this)" /><button class="secondary" style="font-size:10px; padding:2px 6px; margin-top:3px;" onclick="document.getElementById('vthumb-input-${v.id}').click()">🖼️</button>` : ''}
      </td>
      <td><a href="#" onclick="openVoorraadGeschiedenis('${v.id}'); return false;" style="color:var(--ink); text-decoration:none; border-bottom:1px dotted var(--grey);" title="Click for history">${escapeHtml(v.naam)}</a>${gewichtPerStukVan(v) !== null ? `<div style="font-size:10.5px; color:var(--grey);">⚖️ ${formatGewicht(gewichtPerStukVan(v))}/unit</div>` : ''}</td>
      <td>${laag ? '<span class="badge rood">' + v.huidigeVoorraad + ' ' + escapeHtml(v.eenheid) + '</span>' : v.huidigeVoorraad + ' ' + escapeHtml(v.eenheid)}</td>
      <td>${v.minimumVoorraad}</td>
      <td>${v.waardePerStuk !== null ? euro(v.waardePerStuk) : '—'}</td>
      <td>${totaal !== null ? euro(totaal) : '—'}</td>
      <td>${isCoordinator ? `<button class="secondary" onclick='openVoorraadItem(${JSON.stringify(v.id)})'>Edit</button>` : ''}</td>
      <td>${isCoordinator ? `<button class="secondary" onclick="verwijderVoorraad('${v.id}')">Delete</button>` : ''}</td>
    </tr>`;
  }).join('') || '<tr><td colspan="8">No stock items yet.</td></tr>';

}

function renderVoorraadThumbnail(v) {
  const basis = `width:56px; height:42px; border-radius:5px; object-fit:cover; background:var(--primary-light-95); cursor:pointer; display:block;`;
  const src = `api_print.php?action=voorraad_thumbnail_stream&bestand=${encodeURIComponent(v.voorbeeldPad || '')}`;
  if (v.voorbeeldType === 'afbeelding') {
    return `<img src="${src}" style="${basis}" onmouseenter="toonGrootVoorraadVoorbeeld(event, 'afbeelding', '${v.voorbeeldPad}')" onmouseleave="verbergGrootVoorraadVoorbeeld()" onmousemove="verplaatsGrootVoorraadVoorbeeld(event)" />`;
  }
  if (v.voorbeeldType === 'video') {
    return `<video src="${src}" style="${basis}" muted onmouseenter="toonGrootVoorraadVoorbeeld(event, 'video', '${v.voorbeeldPad}')" onmouseleave="verbergGrootVoorraadVoorbeeld()" onmousemove="verplaatsGrootVoorraadVoorbeeld(event)"></video>`;
  }
  if (v.voorbeeldType === 'pdf') {
    return `<div style="${basis} display:flex; align-items:center; justify-content:center; background:#fbeaea;" onmouseenter="toonGrootVoorraadVoorbeeld(event, 'pdf', '${v.voorbeeldPad}')" onmouseleave="verbergGrootVoorraadVoorbeeld()" onmousemove="verplaatsGrootVoorraadVoorbeeld(event)">
      <span style="font-size:9px; font-weight:700; color:#c9432f; border:1.5px solid #c9432f; border-radius:3px; padding:0 4px;">PDF</span>
    </div>`;
  }
  return `<div style="${basis} display:flex; align-items:center; justify-content:center; color:var(--grey); font-size:9px;">no<br>preview</div>`;
}

function toonGrootVoorraadVoorbeeld(evt, type, pad) {
  const overlay = document.getElementById('vvoorbeeld-overlay');
  const img = document.getElementById('vvoorbeeld-img');
  const video = document.getElementById('vvoorbeeld-video');
  const pdf = document.getElementById('vvoorbeeld-pdf');
  const src = 'api_print.php?action=voorraad_thumbnail_stream&bestand=' + encodeURIComponent(pad);

  img.style.display = 'none'; video.style.display = 'none'; video.pause(); pdf.style.display = 'none'; pdf.src = '';

  if (type === 'afbeelding') {
    img.src = src; img.style.display = 'block';
  } else if (type === 'video') {
    video.src = src; video.style.display = 'block';
    video.currentTime = 0; video.play().catch(() => {});
  } else if (type === 'pdf') {
    pdf.src = src; pdf.style.display = 'block';
  }
  overlay.style.display = 'block';
  verplaatsGrootVoorraadVoorbeeld(evt);
}

function verplaatsGrootVoorraadVoorbeeld(evt) {
  const overlay = document.getElementById('vvoorbeeld-overlay');
  const marge = 16;
  let x = evt.clientX + marge, y = evt.clientY + marge;
  if (x + 440 > window.innerWidth) x = evt.clientX - 440 - marge;
  if (y + 440 > window.innerHeight) y = evt.clientY - 440 - marge;
  overlay.style.left = Math.max(8, x) + 'px';
  overlay.style.top = Math.max(8, y) + 'px';
}

function verbergGrootVoorraadVoorbeeld() {
  document.getElementById('vvoorbeeld-overlay').style.display = 'none';
  document.getElementById('vvoorbeeld-video').pause();
  document.getElementById('vvoorbeeld-pdf').src = '';
}

async function voorraadThumbnailUploaden(id, inputEl) {
  if (!inputEl.files.length) return;
  const fd = new FormData();
  fd.append('bestand', inputEl.files[0]);
  const res = await fetch('api_print.php?action=voorraad_thumbnail_upload&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  inputEl.value = '';
  if (!res.ok) { alert(data.error || 'Upload failed.'); return; }
  laadVoorraad();
}

function openVoorraadItem(id) {
  huidigVoorraadId = id || null;
  const item = voorraadData.find(v => v.id === id);
  document.getElementById('voorraad-titel').textContent = item ? item.naam : 'New stock item';
  document.getElementById('v-naam').value = item?.naam || '';
  document.getElementById('v-eenheid').value = item?.eenheid || 'pieces';
  document.getElementById('v-huidig').value = item?.huidigeVoorraad ?? 0;
  document.getElementById('v-minimum').value = item?.minimumVoorraad ?? 0;
  document.getElementById('v-waarde').value = item?.waardePerStuk ?? '';
  document.getElementById('v-gewicht').value = item?.gewicht ?? '';
  document.getElementById('v-gewicht-per').value = item?.gewichtPerAantal ?? 1;
  document.getElementById('v-notitie').value = item?.notitie || '';
  document.getElementById('v-geschiedenis-knop').style.display = item ? '' : 'none';
  document.getElementById('modal-voorraad').style.display = 'flex';
}

const voorraadMutatieRedenLabels = {
  aanvraag_goedgekeurd: '📤 Request approved',
  bestelling_ontvangen: '📥 Order received',
  handmatige_aanpassing: '✎ Manually adjusted',
};

async function openVoorraadGeschiedenis(id) {
  const voorraadId = id || huidigVoorraadId;
  if (!voorraadId) return;
  const item = voorraadData.find(v => v.id === voorraadId);
  document.getElementById('vg-titel').textContent = 'History — ' + (item ? item.naam : '');
  document.getElementById('vg-lijst').innerHTML = '<p style="color:var(--grey); font-size:13px;">Loading…</p>';
  document.getElementById('modal-voorraad-geschiedenis').style.display = 'flex';

  const res = await fetch('api_print.php?action=voorraad_geschiedenis&id=' + encodeURIComponent(voorraadId), { cache: 'no-store' });
  const data = await res.json();
  if (!data.mutaties.length) {
    document.getElementById('vg-lijst').innerHTML = '<p style="color:var(--grey); font-size:13px;">No mutations recorded for this item yet.</p>';
    return;
  }
  document.getElementById('vg-lijst').innerHTML = data.mutaties.map(m => `
    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:9px 0; border-bottom:1px solid var(--border);">
      <div>
        <div style="font-size:13px;">${voorraadMutatieRedenLabels[m.reden] || m.reden}</div>
        <div style="font-size:12px; color:var(--grey); margin-top:2px;">${escapeHtml(m.omschrijving || '')}</div>
        <div style="font-size:11px; color:var(--grey); margin-top:2px;">${new Date(m.datum).toLocaleString('en-GB')} — by ${escapeHtml(m.door)}</div>
      </div>
      <div style="font-weight:700; white-space:nowrap; color:${m.delta > 0 ? '#2f8f4e' : '#c9432f'};">${m.delta > 0 ? '+' : ''}${m.delta}</div>
    </div>`).join('');
}

async function opslaanVoorraad() {
  const body = {
    id: huidigVoorraadId, naam: document.getElementById('v-naam').value,
    eenheid: document.getElementById('v-eenheid').value,
    huidigeVoorraad: document.getElementById('v-huidig').value,
    minimumVoorraad: document.getElementById('v-minimum').value,
    waardePerStuk: document.getElementById('v-waarde').value,
    gewicht: document.getElementById('v-gewicht').value,
    gewichtPerAantal: document.getElementById('v-gewicht-per').value,
    notitie: document.getElementById('v-notitie').value,
  };
  if (!body.naam.trim()) { alert('Name is required.'); return; }
  const res = await fetch('api_print.php?action=voorraad_upsert', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { alert('Save failed (do you have permission for this?).'); return; }
  sluitModal('modal-voorraad');
  laadVoorraad();
}

async function verwijderVoorraad(id) {
  if (!confirm('Delete this stock item?')) return;
  await fetch('api_print.php?action=voorraad_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadVoorraad();
}

// ---- Requests ----------------------------------------------------

async function laadAanvragen() {
  const res = await fetch('api_print.php?action=aanvraag_list');
  const data = await res.json();
  const statusBadge = { open: 'geel', goedgekeurd: 'groen', afgewezen: 'rood' };
  const statusLabel = { open: 'open', goedgekeurd: 'approved', afgewezen: 'rejected' };
  document.getElementById('aanvragen-body').innerHTML = data.aanvragen.map(a => {
    const isEigenOpen = a.aanvragerId === eigenUserId && a.status === 'open';
    return `<tr>
      <td>${escapeHtml(a.aanvragerNaam)}${a.extern ? ' <span class="badge grijs">external</span>' : ''}</td>
      <td>${a.items.map(it => escapeHtml(it.aantal + 'x ' + it.naam)).join(', ')}</td>
      <td>${(() => { const g = berekenAanvraagGewicht(a.items); return g.totaal > 0 ? formatGewicht(g.totaal) + (g.onbekend.length ? ' <span class="badge grijs" title="Weight unknown for: ' + g.onbekend.join(', ') + '">+?</span>' : '') : '—'; })()}</td>
      <td>${escapeHtml(a.afleveradres || '—')}</td>
      <td><span class="badge ${statusBadge[a.status]}">${statusLabel[a.status] || a.status}</span></td>
      <td>${formatDatum(a.aangemaaktOp)}</td>
      <td style="white-space:nowrap;">
        ${isCoordinator && a.status === 'open' ? `<button class="secondary" onclick='openBesluitForm(${JSON.stringify(a)})'>Review</button> ` : ''}
        ${isEigenOpen ? `<button class="secondary" onclick='openAanvraagForm(${JSON.stringify(a)})'>Correct</button> ` : ''}
        ${isCoordinator || isEigenOpen ? `<button class="secondary" onclick="verwijderAanvraag('${a.id}')">Delete</button>` : ''}
      </td>
    </tr>`;
  }).join('') || '<tr><td colspan="7">No requests yet.</td></tr>';
}

function openAanvraagForm(bestaandeAanvraag) {
  huidigeAanvraagId = bestaandeAanvraag?.id || null;
  document.getElementById('aanvraag-modal-titel').textContent = bestaandeAanvraag ? 'Correct request' : 'Request print materials';
  document.getElementById('aan-pakket').innerHTML = '<option value="">— choose a package, or check off individual items below —</option>' +
    pakkettenData.map(p => `<option value="${p.id}">${escapeHtml(p.naam)} (${p.type === 'literatuur_pakket' ? 'literature package' : 'PI package'})</option>`).join('');
  document.getElementById('aan-adres').value = bestaandeAanvraag?.afleveradres || '';
  document.getElementById('aan-reden').value = bestaandeAanvraag?.reden || '';
  document.getElementById('aan-overig-naam').value = '';
  document.getElementById('aan-overig-aantal').value = '';
  document.getElementById('aan-items-lijst').innerHTML = voorraadData.length
    ? voorraadData.map(v => `
      <div class="checkbox-row" data-naam="${escapeHtml(v.naam)}">
        <input type="checkbox" id="aan-check-${v.id}" onchange="document.getElementById('aan-aantal-${v.id}').style.display = this.checked ? 'inline-block' : 'none'" />
        <label style="margin:0; flex:1;">${escapeHtml(v.naam)}</label>
        <input type="number" min="1" value="10" id="aan-aantal-${v.id}" style="width:70px; display:none;" />
      </div>`).join('')
    : '<p style="color:var(--grey); font-size:13px;">No stock items known yet — enter an item manually below.</p>';

  // When correcting: pre-check the items from the existing request (reuses
  // the same logic as applying a package).
  if (bestaandeAanvraag) {
    pasItemsToe(bestaandeAanvraag.items);
  }
  werkAanvraagGewichtBij();

  document.getElementById('modal-aanvraag').style.display = 'flex';
}

function werkAanvraagGewichtBij() {
  const items = [];
  document.querySelectorAll('#aan-items-lijst .checkbox-row').forEach(rij => {
    const checkbox = rij.querySelector('input[type=checkbox]');
    const aantalInput = rij.querySelector('input[type=number]');
    if (checkbox.checked && parseInt(aantalInput.value, 10) > 0) {
      items.push({ naam: rij.dataset.naam, aantal: parseInt(aantalInput.value, 10) });
    }
  });
  const overigNaam = document.getElementById('aan-overig-naam').value.trim();
  const overigAantal = parseInt(document.getElementById('aan-overig-aantal').value, 10);
  if (overigNaam && overigAantal > 0) items.push({ naam: overigNaam, aantal: overigAantal });

  const el = document.getElementById('aan-gewicht-totaal');
  if (!items.length) { el.textContent = ''; return; }
  const g = berekenAanvraagGewicht(items);
  el.textContent = g.totaal > 0
    ? '⚖️ Estimated total weight: ' + formatGewicht(g.totaal) + (g.onbekend.length ? ' (weight unknown for: ' + g.onbekend.join(', ') + ')' : '')
    : '';
}
document.getElementById('aan-items-lijst').addEventListener('input', werkAanvraagGewichtBij);
document.getElementById('aan-items-lijst').addEventListener('change', werkAanvraagGewichtBij);

function pasItemsToe(items) {
  items.forEach(it => {
    let rij = [...document.querySelectorAll('#aan-items-lijst .checkbox-row')]
      .find(r => r.dataset.naam.toLowerCase() === it.naam.toLowerCase());

    if (!rij) {
      const tijdId = 'extra-' + it.naam.replace(/[^a-z0-9]/gi, '').toLowerCase();
      document.getElementById('aan-items-lijst').insertAdjacentHTML('beforeend', `
        <div class="checkbox-row" data-naam="${escapeHtml(it.naam)}">
          <input type="checkbox" id="aan-check-${tijdId}" onchange="document.getElementById('aan-aantal-${tijdId}').style.display = this.checked ? 'inline-block' : 'none'" />
          <label style="margin:0; flex:1;">${escapeHtml(it.naam)} <span class="badge grijs">not in stock list</span></label>
          <input type="number" min="1" value="${it.aantal}" id="aan-aantal-${tijdId}" style="width:70px; display:none;" />
        </div>`);
      rij = document.querySelector(`#aan-items-lijst .checkbox-row[data-naam="${CSS.escape(it.naam)}"]`);
    }

    const checkbox = rij.querySelector('input[type=checkbox]');
    const aantalInput = rij.querySelector('input[type=number]');
    checkbox.checked = true;
    aantalInput.value = it.aantal;
    aantalInput.style.display = 'inline-block';
  });
}

function pasPakketToe(pakketId) {
  if (!pakketId) return;
  const pakket = pakkettenData.find(p => p.id === pakketId);
  if (pakket) pasItemsToe(pakket.items);
  werkAanvraagGewichtBij();
}

async function opslaanAanvraag() {
  const items = [];
  document.querySelectorAll('#aan-items-lijst .checkbox-row').forEach(rij => {
    const checkbox = rij.querySelector('input[type=checkbox]');
    const aantalInput = rij.querySelector('input[type=number]');
    if (checkbox.checked && parseInt(aantalInput.value, 10) > 0) {
      items.push({ naam: rij.dataset.naam, aantal: parseInt(aantalInput.value, 10) });
    }
  });
  const overigNaam = document.getElementById('aan-overig-naam').value.trim();
  const overigAantal = parseInt(document.getElementById('aan-overig-aantal').value, 10);
  if (overigNaam && overigAantal > 0) items.push({ naam: overigNaam, aantal: overigAantal });

  if (!items.length) { alert('Choose at least 1 item with a quantity.'); return; }

  const pakketId = document.getElementById('aan-pakket').value;
  const pakketNaam = pakketId ? (pakkettenData.find(p => p.id === pakketId)?.naam || '') : '';
  const body = { afleveradres: document.getElementById('aan-adres').value, items, reden: document.getElementById('aan-reden').value, pakket: pakketNaam };

  const url = huidigeAanvraagId
    ? 'api_print.php?action=aanvraag_update&id=' + encodeURIComponent(huidigeAanvraagId)
    : 'api_print.php?action=aanvraag_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Save failed.'); return; }
  sluitModal('modal-aanvraag');
  toonView('aanvragen');
  laadAanvragen();
}

async function verwijderAanvraag(id) {
  if (!confirm('Delete this request?')) return;
  await fetch('api_print.php?action=aanvraag_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadAanvragen();
}

function openBesluitForm(aanvraag) {
  huidigBesluitAanvraagId = aanvraag.id;
  document.getElementById('besluit-titel').textContent = 'Request from ' + aanvraag.aanvragerNaam;
  const itemsTekst = aanvraag.items.map(it => it.aantal + 'x ' + it.naam).join(', ');
  const g = berekenAanvraagGewicht(aanvraag.items);
  const gewichtTekst = g.totaal > 0 ? ' — total weight: ' + formatGewicht(g.totaal) + (g.onbekend.length ? ' (weight unknown for: ' + g.onbekend.join(', ') + ')' : '') : '';
  document.getElementById('besluit-samenvatting').textContent = itemsTekst + gewichtTekst +
    (aanvraag.afleveradres ? ' — deliver to: ' + aanvraag.afleveradres : '') +
    (aanvraag.reden ? ' — ' + aanvraag.reden : '');
  document.getElementById('besluit-opmerking').value = '';
  document.getElementById('modal-besluit').style.display = 'flex';
}

async function besluitNemen(besluit) {
  const body = { besluit, opmerking: document.getElementById('besluit-opmerking').value };
  await fetch('api_print.php?action=aanvraag_besluit&id=' + encodeURIComponent(huidigBesluitAanvraagId), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  sluitModal('modal-besluit');
  laadAanvragen();
  laadVoorraad();
}

// ---- Orders ----------------------------------------------------

async function laadBestellingen() {
  const res = await fetch('api_print.php?action=bestelling_list');
  const data = await res.json();
  const statusBadge = { aangevraagd: 'geel', afgewezen: 'rood', besteld: 'geel', onderweg: 'geel', ontvangen: 'groen' };
  const statusLabel = { aangevraagd: 'requested', afgewezen: 'rejected', besteld: 'ordered', onderweg: 'on the way', ontvangen: 'received' };
  document.getElementById('bestellingen-body').innerHTML = data.bestellingen.map(b => `
    <tr>
      <td>${escapeHtml(b.leverancier)}</td>
      <td>${escapeHtml(b.product)}</td>
      <td>${b.aantal}</td>
      <td>${euro(b.totaalprijs)}</td>
      <td>
        ${b.status === 'aangevraagd' && isCoordinator ? `
          <span class="badge geel">requested</span>
          <button class="secondary" onclick="bestellingGoedkeuren('${b.id}')">✓ Approve</button>
          <button class="secondary" onclick="bestellingAfwijzen('${b.id}')">✕ Reject</button>
        ` : b.status === 'aangevraagd' ? `<span class="badge geel">requested — awaiting approval</span>`
          : b.status === 'afgewezen' ? `<span class="badge rood">rejected</span>`
          : isCoordinator ? `
          <select onchange="wijzigBestellingStatus('${b.id}', this.value)">
            <option value="besteld" ${b.status === 'besteld' ? 'selected' : ''}>ordered</option>
            <option value="onderweg" ${b.status === 'onderweg' ? 'selected' : ''}>on the way</option>
            <option value="ontvangen" ${b.status === 'ontvangen' ? 'selected' : ''}>received</option>
          </select>` : `<span class="badge ${statusBadge[b.status]}">${statusLabel[b.status] || b.status}</span>`}
      </td>
      <td>${escapeHtml(b.besteldDoor)}</td>
      <td></td>
    </tr>`).join('') || '<tr><td colspan="7">No orders yet.</td></tr>';
}

async function bestellingGoedkeuren(id) {
  await fetch('api_print.php?action=bestelling_goedkeuren&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadBestellingen();
}
async function bestellingAfwijzen(id) {
  if (!confirm('Reject this order?')) return;
  await fetch('api_print.php?action=bestelling_afwijzen&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadBestellingen();
}

function openBestellingForm() {
  document.getElementById('b-leverancier').value = '';
  document.getElementById('b-product').value = '';
  document.getElementById('b-aantal').value = '';
  document.getElementById('b-prijs').value = '';
  document.getElementById('b-notitie').value = '';
  document.getElementById('modal-bestelling').style.display = 'flex';
}

async function opslaanBestelling() {
  const body = {
    leverancier: document.getElementById('b-leverancier').value, product: document.getElementById('b-product').value,
    aantal: document.getElementById('b-aantal').value, totaalprijs: document.getElementById('b-prijs').value,
    notitie: document.getElementById('b-notitie').value,
  };
  if (!body.product.trim()) { alert('Product is required.'); return; }
  await fetch('api_print.php?action=bestelling_create', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  sluitModal('modal-bestelling');
  laadBestellingen();
}

async function wijzigBestellingStatus(id, status) {
  await fetch('api_print.php?action=bestelling_update_status&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }) });
  laadVoorraad();
}

// ---- Suppliers & pricing ----------------------------------------------------

async function laadLeveranciers() {
  const res = await fetch('api_print.php?action=leverancier_list');
  const data = await res.json();
  const perProduct = {};
  data.leveranciers.forEach(l => { (perProduct[l.product] ??= []).push(l); });

  let html = '';
  Object.keys(perProduct).sort().forEach(product => {
    html += `<h3 style="color:var(--primary); margin:22px 0 8px;">${escapeHtml(product)}</h3>
      <table>
        <thead><tr><th>Supplier</th><th>500</th><th>1,000</th><th>2,500</th><th>5,000</th><th></th></tr></thead>
        <tbody>`;
    perProduct[product].forEach(l => {
      const cel = (tier) => {
        const p = l.prijzen[tier];
        if (!p || (p.totaal === null && p.perStuk === null)) return '—';
        let tekst = p.totaal !== null ? euro(p.totaal) : '';
        if (p.perStuk !== null) tekst += (tekst ? ' ' : '') + `<span style="color:var(--grey);">(${euro(p.perStuk)}/unit)</span>`;
        if (l.aantalVarianten > 1 && p.totaal !== null) {
          tekst += `<br><span style="color:var(--grey); font-size:11px;">×${l.aantalVarianten} = ${euro(p.totaal * l.aantalVarianten)}</span>`;
        }
        return tekst;
      };
      html += `<tr>
        <td>${l.link ? `<a href="${escapeHtml(l.link)}" target="_blank" rel="noopener">${escapeHtml(l.leverancier)}</a>` : escapeHtml(l.leverancier)}${l.aantalVarianten > 1 ? ` <span class="badge grijs">${l.aantalVarianten} variants</span>` : ''}</td>
        <td>${cel('500')}</td><td>${cel('1000')}</td><td>${cel('2500')}</td><td>${cel('5000')}</td>
        <td>${isCoordinator ? `<button class="secondary" onclick='openLeverancierForm(${JSON.stringify(l)})'>Edit</button> <button class="secondary" onclick="verwijderLeverancier('${l.id}')">✕</button>` : ''}</td>
      </tr>`;
    });
    html += `</tbody></table>`;
  });
  document.getElementById('leveranciers-tabel').innerHTML = html || '<p style="color:var(--grey);">No pricing entered yet.</p>';
}

function berekenPerStuk(aantal) {
  const totaalEl = document.getElementById('l-' + aantal + '-totaal');
  const perStukEl = document.getElementById('l-' + aantal + '-perstuk');
  const totaal = parseFloat(totaalEl.value);
  if (!isNaN(totaal) && totaal >= 0) {
    perStukEl.value = Math.round((totaal / aantal) * 10000) / 10000; // 4 decimals, precise enough for small per-unit amounts
  } else {
    perStukEl.value = '';
  }
}

function openLeverancierForm(l) {
  huidigLeverancierId = l?.id || null;
  document.getElementById('leverancier-titel').textContent = l ? 'Edit price' : 'Add price';
  document.getElementById('l-leverancier').value = l?.leverancier || '';
  document.getElementById('l-product').value = l?.product || '';
  document.getElementById('l-varianten').value = l?.aantalVarianten || 1;
  document.getElementById('l-link').value = l?.link || '';
  document.getElementById('l-notitie').value = l?.notitie || '';
  ['500', '1000', '2500', '5000'].forEach(t => {
    document.getElementById('l-' + t + '-totaal').value = l?.prijzen?.[t]?.totaal ?? '';
    document.getElementById('l-' + t + '-perstuk').value = l?.prijzen?.[t]?.perStuk ?? '';
  });
  document.getElementById('modal-leverancier').style.display = 'flex';
}

async function opslaanLeverancier() {
  const prijzen = {};
  ['500', '1000', '2500', '5000'].forEach(t => {
    prijzen[t] = { totaal: document.getElementById('l-' + t + '-totaal').value, perStuk: document.getElementById('l-' + t + '-perstuk').value };
  });
  const body = {
    id: huidigLeverancierId, leverancier: document.getElementById('l-leverancier').value, product: document.getElementById('l-product').value,
    aantalVarianten: document.getElementById('l-varianten').value, link: document.getElementById('l-link').value,
    notitie: document.getElementById('l-notitie').value, prijzen,
  };
  if (!body.leverancier.trim() || !body.product.trim()) { alert('Supplier and product are required.'); return; }
  const res = await fetch('api_print.php?action=leverancier_upsert', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { alert('Save failed (do you have permission for this?).'); return; }
  sluitModal('modal-leverancier');
  laadLeveranciers();
}

async function verwijderLeverancier(id) {
  if (!confirm('Delete this price entry?')) return;
  await fetch('api_print.php?action=leverancier_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadLeveranciers();
}

// ---- Packages ----------------------------------------------------

async function laadPakketten() {
  const res = await fetch('api_print.php?action=pakket_list');
  const data = await res.json();
  pakkettenData = data.pakketten;

  document.getElementById('pakketten-lijst').innerHTML = pakkettenData.map(p => `
    <div class="card" style="margin-bottom:12px; padding:14px;">
      <div class="toolbar" style="margin-bottom:6px;">
        <strong>${escapeHtml(p.naam)} <span class="badge grijs">${p.type === 'literatuur_pakket' ? 'literature package' : 'PI package'}</span></strong>
        ${isCoordinator ? `<div class="right"><button class="secondary" onclick='openPakketForm(${JSON.stringify(p)})'>Edit</button> <button class="secondary" onclick="verwijderPakket('${p.id}')">Delete</button></div>` : ''}
      </div>
      <p style="font-size:13px; color:var(--grey); margin:4px 0;">${p.items.map(it => escapeHtml(it.aantal + 'x ' + it.naam)).join(', ')}</p>
      ${(p.prijsHoog || p.prijsMiddel || p.prijsLaag) ? `<p style="font-size:12px; color:var(--grey); margin:4px 0;">Price per package — high: ${euro(p.prijsHoog)}, medium: ${euro(p.prijsMiddel)}, low: ${euro(p.prijsLaag)}</p>` : ''}
    </div>`).join('') || '<p style="color:var(--grey);">No packages created yet.</p>';
}

function openPakketForm(p) {
  huidigPakketId = p?.id || null;
  document.getElementById('pakket-titel').textContent = p ? 'Edit package' : 'Add package';
  document.getElementById('p-naam').value = p?.naam || '';
  document.getElementById('p-type').value = p?.type || 'pi_pakket';
  document.getElementById('p-items').value = p ? p.items.map(it => it.aantal + ', ' + it.naam).join('\n') : '';
  document.getElementById('p-prijs-hoog').value = p?.prijsHoog ?? '';
  document.getElementById('p-prijs-middel').value = p?.prijsMiddel ?? '';
  document.getElementById('p-prijs-laag').value = p?.prijsLaag ?? '';
  document.getElementById('modal-pakket').style.display = 'flex';
}

async function opslaanPakket() {
  const items = document.getElementById('p-items').value.split('\n').map(regel => {
    const m = regel.match(/^\s*(\d+)\s*,\s*(.+?)\s*$/);
    return m ? { aantal: parseInt(m[1], 10), naam: m[2] } : null;
  }).filter(Boolean);

  const body = {
    id: huidigPakketId, naam: document.getElementById('p-naam').value, type: document.getElementById('p-type').value, items,
    prijsHoog: document.getElementById('p-prijs-hoog').value, prijsMiddel: document.getElementById('p-prijs-middel').value, prijsLaag: document.getElementById('p-prijs-laag').value,
  };
  if (!body.naam.trim() || !items.length) { alert('Name and at least 1 item (in the form "quantity, name") are required.'); return; }
  const res = await fetch('api_print.php?action=pakket_upsert', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { alert('Save failed (do you have permission for this?).'); return; }
  sluitModal('modal-pakket');
  laadPakketten();
}

async function verwijderPakket(id) {
  if (!confirm('Delete this package?')) return;
  await fetch('api_print.php?action=pakket_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadPakketten();
}

const pakketCsvInput = document.getElementById('pakketCsvInput');
if (pakketCsvInput) {
  pakketCsvInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    Papa.parse(file, {
      header: true, skipEmptyLines: true,
      complete: async (results) => {
        if (!confirm(`Import ${results.data.length} rows?`)) return;
        await fetch('api_print.php?action=pakket_bulk_import', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rijen: results.data }) });
        pakketCsvInput.value = '';
        laadPakketten();
      },
    });
  });
}

let laatstIngelezenLeverancierRijen = [];
const leverancierCsvInput = document.getElementById('leverancierCsvInput');
if (leverancierCsvInput) {
  leverancierCsvInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    Papa.parse(file, {
      header: true, skipEmptyLines: true,
      complete: async (results) => {
        laatstIngelezenLeverancierRijen = results.data;
        if (!confirm(`Import ${laatstIngelezenLeverancierRijen.length} rows?`)) return;
        await fetch('api_print.php?action=leverancier_bulk_import', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rijen: laatstIngelezenLeverancierRijen }) });
        leverancierCsvInput.value = '';
        laadLeveranciers();
      },
    });
  });
}

(async function init() {
  await laadInstellingenPrint();
  await Promise.all([laadVoorraad(), laadPakketten()]);
  laadAanvragen();
  laadBestellingen();
  laadLeveranciers();

  const params = new URLSearchParams(location.search);
  if (params.get('tab')) toonView(params.get('tab'));
  if (params.get('tab') === 'aanvragen' && params.get('nieuw') !== '0') {
    // The dashboard's "Submit a request" button opens the form right away.
    openAanvraagForm();
  }
})();
</script>
<!-- ===== Large hover preview window (stock) ===== -->
<div id="vvoorbeeld-overlay" style="display:none; position:fixed; z-index:999; pointer-events:none; background:#111; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,.35); overflow:hidden;">
  <img id="vvoorbeeld-img" style="display:none; max-width:420px; max-height:420px; display:block;" />
  <video id="vvoorbeeld-video" style="display:none; max-width:420px; max-height:420px; display:block;" muted loop playsinline></video>
  <iframe id="vvoorbeeld-pdf" style="display:none; width:340px; height:440px; border:none;"></iframe>
</div>

</body>
</html>
