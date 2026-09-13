<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$magNotulen = heeft_rol(['admin', 'chair', 'secretary']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Minutes &amp; Agendas — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <strong>Minutes &amp; Agendas</strong>
    <?php if ($magNotulen): ?><button class="primary" onclick="openItemForm()">+ Add minutes/agenda</button><?php endif; ?>
  </div>

  <div class="card">
    <p style="color:var(--grey); font-size:12.5px; margin:0 0 14px;">Managed by the Secretary (and Chairperson/IT) — meeting minutes and agenda uploads. Hover over the preview thumbnail for a larger preview.</p>
    <div id="items-lijst"></div>
  </div>

</div>

<!-- ===== Modal: add/edit item ===== -->
<div id="modal-item" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-item')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-item')">✕</button>
    <h2 id="item-titel-kop">Add minutes/agenda</h2>
    <label>Title</label><input id="i-titel" />
    <label>Description (optional)</label><textarea id="i-omschrijving" rows="2"></textarea>
    <label>Download link</label><input id="i-downloadurl" placeholder="https://... (e.g. link to a Drive/kDrive file)" />
    <label>Order (lower = earlier in the list)</label><input id="i-volgorde" type="number" value="0" style="max-width:140px;" />
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="itemOpslaan()">Save</button>
    </div>
  </div>
</div>

<!-- ===== Large hover preview window ===== -->
<div id="voorbeeld-overlay" style="display:none; position:fixed; z-index:999; pointer-events:none; background:#111; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,.35); overflow:hidden;">
  <img id="voorbeeld-img" style="display:none; max-width:420px; max-height:420px; display:block;" />
  <video id="voorbeeld-video" style="display:none; max-width:420px; max-height:420px; display:block;" muted loop playsinline></video>
  <iframe id="voorbeeld-pdf" style="display:none; width:340px; height:440px; border:none;"></iframe>
</div>

<script>
const magNotulen = <?= $magNotulen ? 'true' : 'false' ?>;
const CATEGORIE = 'notulen_agenda';
let itemsData = [];
let huidigBewerkItemId = null;

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }
function sluitModal(id) { document.getElementById(id).style.display = 'none'; }

async function laadItems() {
  const res = await fetch('api_drive.php?action=item_list', { cache: 'no-store' });
  const data = await res.json();
  itemsData = data.items.filter(item => (item.categorie || 'algemeen') === CATEGORIE);
  renderItems();
}

function renderItems() {
  document.getElementById('items-lijst').innerHTML = itemsData.map(item => `
    <div class="drive-item-rij" draggable="${magNotulen}" data-id="${item.id}" ondragstart="itemDragStart(event)" ondragover="itemDragOver(event)" ondrop="itemDrop(event)" ondragend="itemDragEnd(event)" style="display:flex; align-items:center; gap:16px; padding:12px 0; border-bottom:1px solid var(--border); ${magNotulen ? 'cursor:grab;' : ''}">
      ${magNotulen ? `<span style="flex:0 0 auto; color:var(--grey); font-size:16px; user-select:none;" title="Drag to reorder">⠿</span>` : ''}
      <div style="flex:1; min-width:0;">
        <strong>${escapeHtml(item.titel)}</strong>
        ${item.omschrijving ? `<div style="font-size:12.5px; color:var(--grey); margin-top:2px;">${escapeHtml(item.omschrijving)}</div>` : ''}
      </div>

      <div style="flex:0 0 auto;">
        ${renderThumbnail(item)}
      </div>

      <div style="flex:0 0 auto; display:flex; align-items:center; gap:6px;">
        ${item.downloadUrl
          ? `<a href="${escapeHtml(item.downloadUrl)}" target="_blank" rel="noopener" class="btn-primary" title="Download" style="font-size:18px; padding:8px 12px;">⬇</a>`
          : `<span class="badge grijs" title="No download link set yet">no link</span>`}
        ${magNotulen ? `
          <input type="file" id="thumb-input-${item.id}" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.pdf" style="display:none;" onchange="thumbnailUploaden('${item.id}', this)" />
          <button class="secondary" onclick="document.getElementById('thumb-input-${item.id}').click()" title="Replace preview image/video">🖼️</button>
          <button class="secondary" onclick='openItemForm(${JSON.stringify(item)})' title="Edit">✎</button>
          <button class="secondary" onclick="itemVerwijderen('${item.id}')" title="Delete">✕</button>
        ` : ''}
      </div>
    </div>`).join('') || '<p style="color:var(--grey);">No minutes/agendas added yet.</p>';
}

// ---- Drag to reorder (only for those with edit rights) ----------------------------------------------------

let sleepId = null;

function itemDragStart(evt) {
  sleepId = evt.currentTarget.dataset.id;
  evt.currentTarget.style.opacity = '0.4';
}

function itemDragOver(evt) {
  evt.preventDefault(); // required to allow dropping
  const rij = evt.currentTarget;
  if (rij.dataset.id === sleepId) return;
  const rect = rij.getBoundingClientRect();
  const voorHelft = (evt.clientY - rect.top) < rect.height / 2;
  rij.style.borderTop = voorHelft ? '2px solid var(--primary)' : '';
  rij.style.borderBottom = !voorHelft ? '2px solid var(--primary)' : '';
}

async function itemDrop(evt) {
  evt.preventDefault();
  const doelRij = evt.currentTarget;
  const doelId = doelRij.dataset.id;
  doelRij.style.borderTop = ''; doelRij.style.borderBottom = '';
  if (!sleepId || sleepId === doelId) return;

  const rect = doelRij.getBoundingClientRect();
  const voorHelft = (evt.clientY - rect.top) < rect.height / 2;

  const zonderGesleepte = itemsData.filter(i => i.id !== sleepId);
  const gesleeptItem = itemsData.find(i => i.id === sleepId);
  const doelIndex = zonderGesleepte.findIndex(i => i.id === doelId);
  zonderGesleepte.splice(voorHelft ? doelIndex : doelIndex + 1, 0, gesleeptItem);
  itemsData = zonderGesleepte;
  renderItems();

  await fetch('api_drive.php?action=items_herordenen', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ids: itemsData.map(i => i.id) }),
  });
}

function itemDragEnd(evt) {
  evt.currentTarget.style.opacity = '';
  document.querySelectorAll('.drive-item-rij').forEach(r => { r.style.borderTop = ''; r.style.borderBottom = ''; });
  sleepId = null;
}

function renderThumbnail(item) {
  const basis = `width:72px; height:52px; border-radius:6px; object-fit:cover; background:var(--primary-light-95); cursor:pointer; display:block;`;
  if (item.voorbeeldType === 'afbeelding') {
    return `<img src="api_drive.php?action=thumbnail_stream&bestand=${encodeURIComponent(item.voorbeeldPad)}" style="${basis}" onmouseenter="toonGrootVoorbeeld(event, 'afbeelding', '${item.voorbeeldPad}')" onmouseleave="verbergGrootVoorbeeld()" onmousemove="verplaatsGrootVoorbeeld(event)" />`;
  }
  if (item.voorbeeldType === 'video') {
    return `<video src="api_drive.php?action=thumbnail_stream&bestand=${encodeURIComponent(item.voorbeeldPad)}" style="${basis}" muted onmouseenter="toonGrootVoorbeeld(event, 'video', '${item.voorbeeldPad}')" onmouseleave="verbergGrootVoorbeeld()" onmousemove="verplaatsGrootVoorbeeld(event)"></video>`;
  }
  if (item.voorbeeldType === 'pdf') {
    return `<div style="${basis} display:flex; align-items:center; justify-content:center; background:#fbeaea;" onmouseenter="toonGrootVoorbeeld(event, 'pdf', '${item.voorbeeldPad}')" onmouseleave="verbergGrootVoorbeeld()" onmousemove="verplaatsGrootVoorbeeld(event)">
      <span style="font-size:10.5px; font-weight:700; color:#c9432f; border:1.5px solid #c9432f; border-radius:3px; padding:1px 5px;">PDF</span>
    </div>`;
  }
  return `<div style="${basis} display:flex; align-items:center; justify-content:center; color:var(--grey); font-size:11px;">no preview</div>`;
}

function toonGrootVoorbeeld(evt, type, pad) {
  const overlay = document.getElementById('voorbeeld-overlay');
  const img = document.getElementById('voorbeeld-img');
  const video = document.getElementById('voorbeeld-video');
  const pdf = document.getElementById('voorbeeld-pdf');
  const src = 'api_drive.php?action=thumbnail_stream&bestand=' + encodeURIComponent(pad);

  img.style.display = 'none'; video.style.display = 'none'; video.pause(); pdf.style.display = 'none'; pdf.src = '';

  if (type === 'afbeelding') {
    img.src = src; img.style.display = 'block';
  } else if (type === 'video') {
    video.src = src; video.style.display = 'block';
    video.currentTime = 0; video.play().catch(() => {}); // autoplay may be blocked, in which case the still frame just stays
  } else if (type === 'pdf') {
    pdf.src = src; pdf.style.display = 'block';
  }
  overlay.style.display = 'block';
  verplaatsGrootVoorbeeld(evt);
}

function verplaatsGrootVoorbeeld(evt) {
  const overlay = document.getElementById('voorbeeld-overlay');
  const marge = 16;
  let x = evt.clientX + marge, y = evt.clientY + marge;
  if (x + 440 > window.innerWidth) x = evt.clientX - 440 - marge;
  if (y + 440 > window.innerHeight) y = evt.clientY - 440 - marge;
  overlay.style.left = Math.max(8, x) + 'px';
  overlay.style.top = Math.max(8, y) + 'px';
}

function verbergGrootVoorbeeld() {
  document.getElementById('voorbeeld-overlay').style.display = 'none';
  document.getElementById('voorbeeld-video').pause();
  document.getElementById('voorbeeld-pdf').src = ''; // stops loading as soon as you move away
}

function openItemForm(bestaand) {
  huidigBewerkItemId = bestaand ? bestaand.id : null;
  document.getElementById('item-titel-kop').textContent = bestaand ? 'Edit file' : 'Add minutes/agenda';
  document.getElementById('i-titel').value = bestaand ? bestaand.titel : '';
  document.getElementById('i-omschrijving').value = bestaand ? (bestaand.omschrijving || '') : '';
  document.getElementById('i-downloadurl').value = bestaand ? (bestaand.downloadUrl || '') : '';
  document.getElementById('i-volgorde').value = bestaand ? (bestaand.volgorde ?? 0) : 0;
  document.getElementById('modal-item').style.display = 'flex';
}

async function itemOpslaan() {
  const body = {
    titel: document.getElementById('i-titel').value,
    omschrijving: document.getElementById('i-omschrijving').value,
    downloadUrl: document.getElementById('i-downloadurl').value,
    volgorde: document.getElementById('i-volgorde').value,
    categorie: CATEGORIE,
  };
  if (!body.titel.trim()) { alert('Title is required.'); return; }
  const url = huidigBewerkItemId
    ? 'api_drive.php?action=item_update&id=' + encodeURIComponent(huidigBewerkItemId)
    : 'api_drive.php?action=item_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Save failed.'); return; }
  sluitModal('modal-item');
  laadItems();
}

async function itemVerwijderen(id) {
  if (!confirm('Completely delete this item?')) return;
  await fetch('api_drive.php?action=item_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadItems();
}

async function thumbnailUploaden(id, inputEl) {
  if (!inputEl.files.length) return;
  const fd = new FormData();
  fd.append('bestand', inputEl.files[0]);
  const res = await fetch('api_drive.php?action=thumbnail_upload&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  inputEl.value = '';
  if (!res.ok) { alert(data.error || 'Upload failed.'); return; }
  laadItems();
}

laadItems();
</script>
</body>
</html>
