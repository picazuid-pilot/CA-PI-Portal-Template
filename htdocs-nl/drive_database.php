<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isIT = heeft_rol(['admin']); // database-bestanden en subcategorieën: alleen IT
$isDemo = is_demo_gebruiker();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Drive-database — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  .subcat-sectie { border:1px solid var(--border); border-radius:10px; margin-bottom:14px; overflow:hidden; background:#fff; }
  .subcat-sectie.drop-actief { outline:2px solid var(--primary); outline-offset:-2px; background:var(--primary-light-95); }
  .subcat-kop { display:flex; align-items:center; gap:10px; padding:10px 14px; background:var(--primary-light-95); cursor:pointer; user-select:none; }
  .subcat-pijl { width:14px; color:var(--grey); }
  .subcat-body { padding:0 14px; }
  .subcat-body .drive-item-rij:last-child { border-bottom:none !important; }
  .subcat-leeg { padding:16px 0; margin:12px 0; color:var(--grey); font-size:13px; text-align:center; border:1px dashed var(--border); border-radius:8px; }
  .subcat-acties { display:flex; gap:4px; }
  .subcat-acties button { padding:3px 9px; font-size:13px; }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <strong>Drive-database</strong>
    <?php if ($isIT): ?>
      <button class="secondary" id="btn-ledenmap" style="display:none;" onclick="ledenmapAanmaken()">+ Ledenmap “Bestanden door comité leden”</button>
      <button class="secondary" onclick="openSubcatForm()">+ Subcategorie</button>
      <button class="primary" onclick="openItemForm()">+ Bestand toevoegen</button>
    <?php endif; ?>
  </div>

  <div class="card">
    <p style="color:var(--grey); font-size:12.5px; margin:0 0 14px;">Overzicht van downloadbare bestanden (bijv. grafisch/AV-drukwerk). Beweeg over het voorbeeldvenster voor een groter previewvenster.<?php if ($isIT): ?> Maak subcategorieën aan (bijv. Manuals, Flyers, Folders) en sleep een bestand naar de juiste subcategorie.<?php endif; ?></p>
    <div id="items-lijst"></div>
  </div>

</div>

<!-- ===== Modal: item toevoegen/bewerken ===== -->
<div id="modal-item" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-item')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-item')">✕</button>
    <h2 id="item-titel-kop">Bestand toevoegen</h2>
    <label>Titel</label><input id="i-titel" />
    <label>Omschrijving (optioneel)</label><textarea id="i-omschrijving" rows="2"></textarea>
    <label>Download-link</label><input id="i-downloadurl" placeholder="https://... (bijv. link naar Drive/kDrive-bestand)" />
    <div id="i-subcat-rij"><label>Subcategorie</label><select id="i-subcat"></select></div>
    <label>Volgorde (laag = eerder in de lijst)</label><input id="i-volgorde" type="number" value="0" style="max-width:140px;" />
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="itemOpslaan()">Opslaan</button>
    </div>
  </div>
</div>

<!-- ===== Modal: subcategorie toevoegen/hernoemen ===== -->
<div id="modal-subcat" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-subcat')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-subcat')">✕</button>
    <h2 id="subcat-titel-kop">Subcategorie toevoegen</h2>
    <label>Naam</label><input id="sc-naam" maxlength="60" placeholder="bijv. Manuals, Flyers, Folders" onkeydown="if(event.key==='Enter') subcatOpslaan()" />
    <label style="display:flex; align-items:center; gap:8px; margin-top:12px; font-weight:400;"><input type="checkbox" id="sc-leden" style="width:auto;" /> Comité-leden mogen hier zelf bestanden uploaden</label>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="subcatOpslaan()">Opslaan</button>
    </div>
  </div>
</div>

<!-- ===== Modal: lid uploadt / bewerkt eigen bestand ===== -->
<div id="modal-lid" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-lid')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-lid')">✕</button>
    <h2 id="lid-kop">Bestand uploaden</h2>
    <div id="lid-bestand-rij">
      <label>Bestand</label><input type="file" id="lid-bestand" />
      <p id="lid-hint" style="font-size:11.5px; color:var(--grey); margin:4px 0 0;"></p>
    </div>
    <label>Titel</label><input id="lid-titel" maxlength="150" placeholder="(leeg = bestandsnaam)" />
    <label>Omschrijving</label><textarea id="lid-omschrijving" rows="2" maxlength="500"></textarea>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" id="lid-opslaan" onclick="lidOpslaan()">Opslaan</button>
    </div>
  </div>
</div>

<!-- ===== Groot voorbeeldvenster bij hover ===== -->
<div id="voorbeeld-overlay" style="display:none; position:fixed; z-index:999; pointer-events:none; background:#111; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,.35); overflow:hidden;">
  <img id="voorbeeld-img" style="display:none; max-width:420px; max-height:420px; display:block;" />
  <video id="voorbeeld-video" style="display:none; max-width:420px; max-height:420px; display:block;" muted loop playsinline></video>
  <iframe id="voorbeeld-pdf" style="display:none; width:340px; height:476px; margin-top:-36px; clip-path:inset(36px 0 0 0); border:none;"></iframe>
</div>

<script>
const isIT = <?= $isIT ? 'true' : 'false' ?>;
const isDemo = <?= $isDemo ? 'true' : 'false' ?>;
let limieten = { maxMb: 10, perLidMb: 50, gebruiktMb: 0, extensies: [] };
const LEDENMAP_NAAM = 'Bestanden door comité leden';
function fmtGrootte(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
const CATEGORIE = 'algemeen';
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
function veiligeUrl(u) { return /^https?:\/\//i.test(u || '') ? u : '#'; } // alleen http(s)-links klikbaar
function sluitModal(id) { document.getElementById(id).style.display = 'none'; }

const INGEKLAPT_KEY = 'drive_ingeklapt_' + CATEGORIE;
let itemsData = [];
let subcats = [];
let huidigBewerkItemId = null;
let huidigBewerkSubcatId = null;
let sleepId = null;

function leesIngeklapt() { try { return new Set(JSON.parse(localStorage.getItem(INGEKLAPT_KEY) || '[]')); } catch (e) { return new Set(); } }
function bewaarIngeklapt() { try { localStorage.setItem(INGEKLAPT_KEY, JSON.stringify([...ingeklapt])); } catch (e) {} }
let ingeklapt = leesIngeklapt();

async function postJson(url, body) {
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
  let data = {};
  try { data = await res.json(); } catch (e) {}
  return { ok: res.ok, data };
}

async function laadItems() {
  const [r1, r2] = await Promise.all([
    fetch('api_drive.php?action=item_list', { cache: 'no-store' }).then(r => r.json()),
    fetch('api_drive.php?action=subcat_list&categorie=' + CATEGORIE, { cache: 'no-store' }).then(r => r.json()).catch(() => ({})),
  ]);
  if (r1.limieten) limieten = r1.limieten;
  itemsData = (r1.items || []).filter(item => (item.categorie || 'algemeen') === CATEGORIE);
  subcats = r2.subcategorieen || [];
  const knop = document.getElementById('btn-ledenmap');
  if (knop) knop.style.display = subcats.some(c => c.ledenUpload) ? 'none' : '';
  renderItems();
}

// Een bestand hoort bij zijn subcategorie als die (nog) bestaat, anders bij "Ongesorteerd" ('').
function groepVan(item) { return subcats.some(c => c.id === item.subcategorieId) ? item.subcategorieId : ''; }

function renderItems() {
  const container = document.getElementById('items-lijst');
  if (!subcats.length) {
    // Nog geen subcategorieën: gewoon de platte lijst, zoals vroeger.
    container.innerHTML = `<div class="subcat-lijst" data-subcat="">${itemsData.map(renderRij).join('') || '<p style="color:var(--grey);">Nog geen bestanden toegevoegd.</p>'}</div>`;
    return;
  }
  const secties = subcats.map(c => ({ id: c.id, naam: c.naam, ledenUpload: !!c.ledenUpload, items: itemsData.filter(i => groepVan(i) === c.id) }));
  const los = itemsData.filter(i => groepVan(i) === '');
  if (los.length || isIT) secties.push({ id: '', naam: 'Ongesorteerd', items: los });
  container.innerHTML = secties.map((g, idx) => renderSectie(g, idx)).join('');
}

function renderSectie(g) {
  const sleutel = g.id || '_los';
  const dicht = ingeklapt.has(sleutel);
  const leeg = isIT ? 'Sleep bestanden hierheen' : 'Geen bestanden';
  const lidKnop = (g.ledenUpload && !isDemo) ? `<button class="primary" data-actie="lid-upload" data-id="${g.id}" title="Eigen bestand uploaden">⬆ Uploaden</button>` : '';
  const beheer = !isIT ? lidKnop : lidKnop + `
    <span class="subcat-acties">
      <button class="secondary" data-actie="nieuw-in" data-id="${g.id}" title="Bestand toevoegen aan deze subcategorie">+</button>
      ${g.id ? `
        <button class="secondary" data-actie="omhoog" data-id="${g.id}" title="Eerder in de lijst">↑</button>
        <button class="secondary" data-actie="omlaag" data-id="${g.id}" title="Later in de lijst">↓</button>
        <button class="secondary" data-actie="hernoem" data-id="${g.id}" title="Hernoemen">✎</button>
        <button class="secondary" data-actie="verwijder" data-id="${g.id}" title="Subcategorie verwijderen">✕</button>` : ''}
    </span>`;
  return `
    <section class="subcat-sectie" data-subcat="${g.id}" ondragover="sectieDragOver(event)" ondrop="sectieDrop(event)">
      <div class="subcat-kop" data-actie="toggle" data-id="${g.id}">
        <span class="subcat-pijl">${dicht ? '▸' : '▾'}</span>
        <strong>${escapeHtml(g.naam)}</strong>${g.ledenUpload ? ' <span class="badge grijs" title="Leden mogen hier eigen bestanden uploaden">leden</span>' : ''}
        <span class="badge grijs">${g.items.length}</span>
        <span style="flex:1;"></span>
        ${beheer}
      </div>
      ${dicht ? '' : `<div class="subcat-body">${g.items.map(renderRij).join('') || `<div class="subcat-leeg">${leeg}</div>`}</div>`}
    </section>`;
}

function renderRij(item) {
  return `
    <div class="drive-item-rij" draggable="${isIT}" data-id="${item.id}" ondragstart="itemDragStart(event)" ondragover="itemDragOver(event)" ondrop="itemDrop(event)" ondragend="itemDragEnd(event)" style="display:flex; align-items:center; gap:16px; padding:12px 0; border-bottom:1px solid var(--border); ${isIT ? 'cursor:grab;' : ''}">
      ${isIT ? `<span style="flex:0 0 auto; color:var(--grey); font-size:16px; user-select:none;" title="Sleep om te herordenen of naar een subcategorie te verplaatsen">⠿</span>` : ''}
      <div style="flex:1; min-width:0;">
        <strong>${escapeHtml(item.titel)}</strong>
        ${item.omschrijving ? `<div style="font-size:12.5px; color:var(--grey); margin-top:2px;">${escapeHtml(item.omschrijving)}</div>` : ''}
        ${item.bestand ? `<div style="font-size:11.5px; color:var(--grey); margin-top:2px;">${escapeHtml(item.bestand.naam)} · ${fmtGrootte(item.bestand.grootte)} · ${escapeHtml(item.createdBy || '')} · ${new Date(item.createdAt).toLocaleDateString('nl-NL')}</div>` : ''}
      </div>

      <div style="flex:0 0 auto;">
        ${renderThumbnail(item)}
      </div>

      <div style="flex:0 0 auto; display:flex; align-items:center; gap:6px;">
        ${item.bestand
          ? (isDemo ? `<span class="badge grijs">demo</span>` : `<a href="api_drive.php?action=bestand_download&id=${encodeURIComponent(item.id)}" class="btn-primary" title="Downloaden" style="font-size:18px; padding:8px 12px;">📎</a>`)
          : item.downloadUrl
          ? `<a href="${escapeHtml(veiligeUrl(item.downloadUrl))}" target="_blank" rel="noopener" class="btn-primary" title="Downloaden" style="font-size:18px; padding:8px 12px;">📎</a>`
          : `<span class="badge grijs" title="Nog geen downloadlink ingesteld">geen link</span>`}
        ${isIT ? `
          <input type="file" id="thumb-input-${item.id}" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.pdf" style="display:none;" onchange="thumbnailUploaden('${item.id}', this)" />
          <button class="secondary" onclick="document.getElementById('thumb-input-${item.id}').click()" title="Voorbeeldbeeld/video vervangen">🖼️</button>
          <button class="secondary" onclick="openItemForm(${escapeHtml(JSON.stringify(item))})" title="Bewerken">✎</button>
          <button class="secondary" onclick="itemVerwijderen('${item.id}')" title="Verwijderen">✕</button>
        ` : (item.eigen ? `
          <button class="secondary" onclick="openLidForm(${escapeHtml(JSON.stringify(item))})" title="Titel/omschrijving aanpassen">✎</button>
          <button class="secondary" onclick="itemVerwijderen(${escapeHtml(JSON.stringify(item.id))})" title="Mijn bestand verwijderen">✕</button>` : '')}
      </div>
    </div>`;
}

// ---- Klikken op sectiekoppen: in-/uitklappen en beheerknoppen (event delegation) ----------------------------------
document.getElementById('items-lijst').addEventListener('click', (e) => {
  const el = e.target.closest('[data-actie]');
  if (!el) return;
  const actie = el.dataset.actie, id = el.dataset.id || '';
  if (actie === 'toggle') {
    const sleutel = id || '_los';
    if (ingeklapt.has(sleutel)) ingeklapt.delete(sleutel); else ingeklapt.add(sleutel);
    bewaarIngeklapt(); renderItems();
    return;
  }
  if (actie === 'lid-upload') { openLidForm(null, id); return; }
  if (!isIT) return;
  if (actie === 'nieuw-in') openItemForm(null, id);
  else if (actie === 'omhoog') subcatVerschuiven(id, -1);
  else if (actie === 'omlaag') subcatVerschuiven(id, 1);
  else if (actie === 'hernoem') openSubcatForm(subcats.find(c => c.id === id));
  else if (actie === 'verwijder') subcatVerwijderen(id);
});

// ---- Slepen: bestanden herordenen én naar een subcategorie verplaatsen (alleen IT) ---------------------------------

function wisMarkeringen() {
  document.querySelectorAll('.drive-item-rij').forEach(r => { r.style.borderTop = ''; r.style.borderBottom = '1px solid var(--border)'; });
  document.querySelectorAll('.subcat-sectie.drop-actief').forEach(sec => sec.classList.remove('drop-actief'));
}

function itemDragStart(evt) {
  if (!isIT) { evt.preventDefault(); return; }
  sleepId = evt.currentTarget.dataset.id;
  evt.dataTransfer.effectAllowed = 'move';
  try { evt.dataTransfer.setData('text/plain', sleepId); } catch (e) {} // Firefox start het slepen anders niet
  evt.currentTarget.style.opacity = '0.4';
}

function itemDragOver(evt) {
  if (!isIT || !sleepId) return;
  evt.preventDefault(); // vereist om drop toe te staan
  evt.stopPropagation();
  wisMarkeringen();
  const rij = evt.currentTarget;
  const sec = rij.closest('.subcat-sectie');
  if (sec) sec.classList.add('drop-actief');
  if (rij.dataset.id === sleepId) return;
  const rect = rij.getBoundingClientRect();
  const voorHelft = (evt.clientY - rect.top) < rect.height / 2;
  rij.style.borderTop = voorHelft ? '2px solid var(--primary)' : '';
  rij.style.borderBottom = !voorHelft ? '2px solid var(--primary)' : '1px solid var(--border)';
}

async function itemDrop(evt) {
  if (!isIT || !sleepId) return;
  evt.preventDefault();
  evt.stopPropagation();
  const doelRij = evt.currentTarget;
  const doelId = doelRij.dataset.id;
  const sec = doelRij.closest('.subcat-sectie');
  const doelGroep = sec ? sec.dataset.subcat : '';
  const rect = doelRij.getBoundingClientRect();
  const voorHelft = (evt.clientY - rect.top) < rect.height / 2;
  wisMarkeringen();
  if (sleepId === doelId) return;
  await verplaatsItem(sleepId, doelGroep, doelId, voorHelft);
}

// Slepen over (of loslaten op) de kop of lege ruimte van een sectie: achteraan in die subcategorie.
function sectieDragOver(evt) {
  if (!isIT || !sleepId) return;
  evt.preventDefault();
  wisMarkeringen();
  evt.currentTarget.classList.add('drop-actief');
}

async function sectieDrop(evt) {
  if (!isIT || !sleepId) return;
  evt.preventDefault();
  const doelGroep = evt.currentTarget.dataset.subcat || '';
  wisMarkeringen();
  await verplaatsItem(sleepId, doelGroep, null, false);
}

function itemDragEnd(evt) {
  evt.currentTarget.style.opacity = '';
  wisMarkeringen();
  sleepId = null;
}

// Zet een bestand in doelGroep ('' = Ongesorteerd), vóór/na doelRijId (of achteraan), en bewaar de volgorde.
async function verplaatsItem(itemId, doelGroep, doelRijId, voor) {
  const item = itemsData.find(i => i.id === itemId);
  if (!item) return;
  const rest = itemsData.filter(i => i.id !== itemId);
  item.subcategorieId = doelGroep || null;
  const groep = rest.filter(i => groepVan(i) === doelGroep);
  let idx = groep.length;
  if (doelRijId) {
    const pos = groep.findIndex(i => i.id === doelRijId);
    if (pos >= 0) idx = voor ? pos : pos + 1;
  }
  const nieuweGroep = [...groep.slice(0, idx), item, ...groep.slice(idx)];
  itemsData = [...rest.filter(i => groepVan(i) !== doelGroep), ...nieuweGroep];
  renderItems();

  const { ok, data } = await postJson('api_drive.php?action=item_verplaatsen&id=' + encodeURIComponent(itemId),
    { subcategorieId: doelGroep, ids: nieuweGroep.map(i => i.id) });
  if (!ok) { alert(data.error || 'Verplaatsen mislukt.'); laadItems(); }
}

// ---- Subcategorieën beheren (alleen IT) --------------------------------------------------------------------------

function openSubcatForm(bestaand) {
  huidigBewerkSubcatId = bestaand ? bestaand.id : null;
  document.getElementById('subcat-titel-kop').textContent = bestaand ? 'Subcategorie hernoemen' : 'Subcategorie toevoegen';
  document.getElementById('sc-naam').value = bestaand ? bestaand.naam : '';
  document.getElementById('sc-leden').checked = !!(bestaand && bestaand.ledenUpload);
  document.getElementById('modal-subcat').style.display = 'flex';
  document.getElementById('sc-naam').focus();
}

async function subcatOpslaan() {
  const naam = document.getElementById('sc-naam').value.trim();
  if (!naam) { alert('Geef de subcategorie een naam.'); return; }
  const url = huidigBewerkSubcatId
    ? 'api_drive.php?action=subcat_update&id=' + encodeURIComponent(huidigBewerkSubcatId)
    : 'api_drive.php?action=subcat_create';
  const { ok, data } = await postJson(url, { naam, categorie: CATEGORIE, ledenUpload: document.getElementById('sc-leden').checked });
  if (!ok) { alert(data.error || 'Opslaan mislukt.'); return; }
  sluitModal('modal-subcat');
  laadItems();
}

async function subcatVerwijderen(id) {
  const c = subcats.find(x => x.id === id);
  if (!c) return;
  const aantal = itemsData.filter(i => groepVan(i) === id).length;
  if (!confirm(`Subcategorie "${c.naam}" verwijderen?` + (aantal ? `\n\nDe ${aantal} bestand(en) erin blijven bestaan en komen onder "Ongesorteerd".` : ''))) return;
  const { ok, data } = await postJson('api_drive.php?action=subcat_delete&id=' + encodeURIComponent(id));
  if (!ok) { alert(data.error || 'Verwijderen mislukt.'); return; }
  laadItems();
}

async function subcatVerschuiven(id, richting) {
  const i = subcats.findIndex(c => c.id === id);
  const j = i + richting;
  if (i < 0 || j < 0 || j >= subcats.length) return;
  [subcats[i], subcats[j]] = [subcats[j], subcats[i]];
  renderItems();
  const { ok, data } = await postJson('api_drive.php?action=subcats_herordenen', { ids: subcats.map(c => c.id), categorie: CATEGORIE });
  if (!ok) { alert(data.error || 'Opslaan van de volgorde mislukt.'); laadItems(); }
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
  return `<div style="${basis} display:flex; align-items:center; justify-content:center; color:var(--grey); font-size:11px;">geen voorbeeld</div>`;
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
    video.currentTime = 0; video.play().catch(() => {}); // autoplay kan geblokkeerd zijn, dan blijft het stilstaand beeld staan
  } else if (type === 'pdf') {
    pdf.src = src + '#toolbar=0&navpanes=0&scrollbar=0&view=FitH'; pdf.style.display = 'block'; // PDF-bovenbalk verbergen
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
  document.getElementById('voorbeeld-pdf').src = ''; // stopt het laden zodra je wegbeweegt
}

function vulSubcatKeuze(waarde) {
  const sel = document.getElementById('i-subcat');
  sel.innerHTML = '<option value="">— Geen (ongesorteerd) —</option>' +
    subcats.map(c => `<option value="${escapeHtml(c.id)}">${escapeHtml(c.naam)}</option>`).join('');
  sel.value = waarde || '';
  document.getElementById('i-subcat-rij').style.display = subcats.length ? '' : 'none';
}

function openItemForm(bestaand, voorSubcat) {
  huidigBewerkItemId = bestaand ? bestaand.id : null;
  vulSubcatKeuze(bestaand ? groepVan(bestaand) : (voorSubcat || ''));
  document.getElementById('item-titel-kop').textContent = bestaand ? 'Bestand bewerken' : 'Bestand toevoegen';
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
    subcategorieId: document.getElementById('i-subcat').value,
    categorie: CATEGORIE,
  };
  if (!body.titel.trim()) { alert('Titel is verplicht.'); return; }
  const url = huidigBewerkItemId
    ? 'api_drive.php?action=item_update&id=' + encodeURIComponent(huidigBewerkItemId)
    : 'api_drive.php?action=item_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Opslaan mislukt.'); return; }
  sluitModal('modal-item');
  laadItems();
}

async function itemVerwijderen(id) {
  if (!confirm('Dit item helemaal verwijderen?')) return;
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
  if (!res.ok) { alert(data.error || 'Upload mislukt.'); return; }
  laadItems();
}

// ---- Ledenmap: leden uploaden en beheren hun eigen bestanden ------------------------------------------------------
let lidSubcatId = '';
let lidBewerkId = null;

function openLidForm(bestaand, subcatId) {
  lidBewerkId = bestaand ? bestaand.id : null;
  lidSubcatId = subcatId || '';
  document.getElementById('lid-kop').textContent = bestaand ? 'Mijn bestand aanpassen' : 'Bestand uploaden';
  document.getElementById('lid-bestand-rij').style.display = bestaand ? 'none' : '';
  document.getElementById('lid-bestand').value = '';
  document.getElementById('lid-titel').value = bestaand ? bestaand.titel : '';
  document.getElementById('lid-omschrijving').value = bestaand ? (bestaand.omschrijving || '') : '';
  document.getElementById('lid-hint').textContent = `Max. ${limieten.maxMb} MB per bestand (jij hebt ${limieten.gebruiktMb} van ${limieten.perLidMb} MB gebruikt). Toegestaan: ${(limieten.extensies || []).join(', ')}.`;
  document.getElementById('lid-opslaan').disabled = false;
  document.getElementById('modal-lid').style.display = 'flex';
}

async function lidOpslaan() {
  const titel = document.getElementById('lid-titel').value;
  const omschrijving = document.getElementById('lid-omschrijving').value;
  const knop = document.getElementById('lid-opslaan');
  if (lidBewerkId) {
    if (!titel.trim()) { alert('Titel is verplicht.'); return; }
    const { ok, data } = await postJson('api_drive.php?action=item_update&id=' + encodeURIComponent(lidBewerkId), { titel, omschrijving });
    if (!ok) { alert(data.error || 'Opslaan mislukt.'); return; }
  } else {
    const f = document.getElementById('lid-bestand').files[0];
    if (!f) { alert('Kies eerst een bestand.'); return; }
    if (f.size > limieten.maxMb * 1048576) { alert('Bestand mag maximaal ' + limieten.maxMb + ' MB zijn.'); return; }
    const fd = new FormData();
    fd.append('bestand', f); fd.append('titel', titel); fd.append('omschrijving', omschrijving); fd.append('subcategorieId', lidSubcatId);
    knop.disabled = true;
    let res, data = {};
    try { res = await fetch('api_drive.php?action=lid_upload', { method: 'POST', credentials: 'same-origin', body: fd }); data = await res.json(); }
    catch (e) { knop.disabled = false; alert('Upload mislukt (verbinding of bestand te groot).'); return; }
    knop.disabled = false;
    if (!res.ok) { alert(data.error || 'Upload mislukt.'); return; }
  }
  sluitModal('modal-lid');
  laadItems();
}

// IT: met één klik de standaard ledenmap aanmaken.
async function ledenmapAanmaken() {
  const { ok, data } = await postJson('api_drive.php?action=subcat_create', { naam: LEDENMAP_NAAM, categorie: CATEGORIE, ledenUpload: true });
  if (!ok) { alert(data.error || 'Aanmaken mislukt.'); return; }
  laadItems();
}

laadItems();
</script>
</body>
</html>
