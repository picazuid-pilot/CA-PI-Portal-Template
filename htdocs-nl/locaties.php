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
<title>PI Locaties — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://unpkg.com/papaparse@5.4.1/papaparse.min.js"></script>
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <div style="display:flex; gap:6px;">
      <button class="secondary" data-view="lijst" onclick="toonView('lijst')">Klantenbestand</button>
      <button class="secondary" data-view="bulk" onclick="toonView('bulk')">Bulk-import</button>
      <button class="secondary" data-view="campagnes" onclick="toonView('campagnes')">Campagnes</button>
      <button class="secondary" data-view="targets" onclick="toonView('targets')">Targets</button>
      <button class="secondary" data-view="campagne_ideeen" onclick="toonView('campagne_ideeen')">Ideeontwikkeling</button>
    </div>
    <div class="right">
      <input id="zoek" placeholder="Zoek op naam of plaats…" style="width:220px;" oninput="renderLijst()" />
      <select id="sorteer" onchange="renderLijst()" style="width:auto;">
        <option value="instantie-az">Instantie A-Z</option>
        <option value="instantie-za">Instantie Z-A</option>
        <option value="plaats-az">Plaats A-Z</option>
        <option value="plaats-za">Plaats Z-A</option>
        <option value="toegevoegd-nieuw">Datum toegevoegd (nieuw-oud)</option>
        <option value="toegevoegd-oud">Datum toegevoegd (oud-nieuw)</option>
        <option value="activiteit-nieuw">Datum laatste activiteit (nieuw-oud)</option>
        <option value="activiteit-oud">Datum laatste activiteit (oud-nieuw)</option>
        <option value="activiteiten-hoog">Activiteiten hoog-laag</option>
        <option value="activiteiten-laag">Activiteiten laag-hoog</option>
      </select>
      <?php if (heeft_rol(['admin'])): ?>
      <button class="secondary" onclick="checkDuplicaten()">🧹 Duplicaten opruimen</button>
      <?php endif; ?>
      <button class="primary" onclick="openNieuweLocatie()">+ Nieuwe locatie</button>
    </div>
  </div>

  <!-- ===== Klantenbestand ===== -->
  <div id="view-lijst" class="card">
    <table>
      <thead>
        <tr><th></th><th>Instantie</th><th>Plaats</th><th>Contactpersoon</th><th>Laatste activiteit</th><th># activiteiten</th></tr>
      </thead>
      <tbody id="lijst-body"><tr><td colspan="6">Laden…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Bulk-import ===== -->
  <div id="view-bulk" class="card" style="display:none;">
    <h3 style="margin-top:0;">Bulk-locaties importeren</h3>
    <p style="color:var(--grey); font-size:13.5px;">
      Download eerst het CSV-sjabloon, vul het aan (Excel/Google Sheets), en upload het hieronder.
      Bestaande locaties worden niet herkend/samengevoegd — gebruik dit dus voor nieuwe locaties.
    </p>
    <a class="btn-primary" href="templates/locaties_bulk_sjabloon.csv" download style="margin-bottom:14px; display:inline-block;">⬇ Download CSV-sjabloon</a>
    <div>
      <input type="file" id="csvInput" accept=".csv" />
    </div>
    <div id="bulk-preview" style="margin-top:14px;"></div>
  </div>

  <!-- ===== Campagnes ===== -->
  <div id="view-campagnes" class="card" style="display:none;">
    <div class="toolbar" style="margin-bottom:0;">
      <strong>Campagnes — Fysiek</strong>
      <button class="primary" onclick="openNieuweCampagne('fysiek')">+ Nieuwe campagne (fysiek)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Drukwerk, pers, media — goedkeuring door de Media Coördinator.</p>
    <table>
      <thead><tr><th>Campagne</th><th>Datum</th><th>Kanaal</th><th># deelnemers</th><th>Status</th></tr></thead>
      <tbody id="campagnes-body-fysiek"><tr><td colspan="5">Laden…</td></tr></tbody>
    </table>

    <div class="toolbar" style="margin-top:26px; margin-bottom:0;">
      <strong>Campagnes — Online</strong>
      <button class="primary" onclick="openNieuweCampagne('online')">+ Nieuwe campagne (online)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Social media, Laposta e.d. — goedkeuring door de Coördinator Social Media.</p>
    <table>
      <thead><tr><th>Campagne</th><th>Datum</th><th>Kanaal</th><th># deelnemers</th><th>Status</th></tr></thead>
      <tbody id="campagnes-body-online"><tr><td colspan="5">Laden…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Modal: campagne afwijzen ===== -->
  <div id="modal-campagne-afwijzen" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-campagne-afwijzen')">
    <div class="modal">
      <button class="modal-close" onclick="sluitModal('modal-campagne-afwijzen')">✕</button>
      <h2>Campagne afwijzen</h2>
      <label>Toelichting (optioneel, gaat naar de indiener)</label>
      <textarea id="ca-opmerking" rows="2"></textarea>
      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="campagneAfwijzenBevestigen()">Bevestig afwijzen</button>
      </div>
    </div>
  </div>

  <!-- ===== Targets (instanties voor mediacampagnes) ===== -->
  <div id="view-targets" class="card" style="display:none;">
    <div class="toolbar" style="margin-bottom:0;">
      <strong>Targets — Fysiek</strong>
      <button class="primary" onclick="openTargetForm('fysiek')">+ Target toevoegen (fysiek)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Drukwerk/media-instanties (bijv. Interplay Media).</p>
    <table>
      <thead><tr><th>Naam</th><th>Omschrijving</th><th></th></tr></thead>
      <tbody id="targets-body-fysiek"><tr><td colspan="3">Laden…</td></tr></tbody>
    </table>

    <div class="toolbar" style="margin-top:26px; margin-bottom:0;">
      <strong>Targets — Online</strong>
      <button class="primary" onclick="openTargetForm('online')">+ Target toevoegen (online)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Social-mediakanalen/-instanties.</p>
    <table>
      <thead><tr><th>Naam</th><th>Omschrijving</th><th></th></tr></thead>
      <tbody id="targets-body-online"><tr><td colspan="3">Laden…</td></tr></tbody>
    </table>
  </div>

  <!-- ===== Modal: target toevoegen/bewerken ===== -->
  <div id="modal-target" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-target')">
    <div class="modal">
      <button class="modal-close" onclick="sluitModal('modal-target')">✕</button>
      <h2 id="target-titel-kop">Target toevoegen</h2>
      <label>Naam</label><input id="tg-naam" />
      <label>Omschrijving</label><textarea id="tg-omschrijving" rows="3"></textarea>
      <label>Bijlagen</label>
      <input type="file" id="tg-bijlage-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt,.eml,.msg" style="display:none;" onchange="bijlageUploaden('tg')" />
      <button type="button" class="secondary" onclick="document.getElementById('tg-bijlage-input').click()">+ Bijlage toevoegen</button>
      <div id="tg-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
      <div id="tg-bijlage-lijst" style="margin-top:6px;"></div>
      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="targetOpslaan()">Opslaan</button>
      </div>
    </div>
  </div>

  <!-- ===== Ideeontwikkeling ===== -->
  <div id="view-campagne_ideeen" class="card" style="display:none;">
    <div class="toolbar" style="margin-bottom:0;">
      <strong>Ideeontwikkeling — Fysiek</strong>
      <button class="primary" onclick="openIdeeForm('fysiek')">+ Idee indienen (fysiek)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Uitvoering door de Media Coördinator.</p>
    <div id="ideeen-lijst-fysiek"></div>

    <div class="toolbar" style="margin-top:26px; margin-bottom:0;">
      <strong>Ideeontwikkeling — Online</strong>
      <button class="primary" onclick="openIdeeForm('online')">+ Idee indienen (online)</button>
    </div>
    <p style="color:var(--grey); font-size:12px; margin:4px 0 10px;">Uitvoering door de Coördinator Social Media.</p>
    <div id="ideeen-lijst-online"></div>
  </div>

  <!-- ===== Modal: idee indienen/bewerken ===== -->
  <div id="modal-idee" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-idee')">
    <div class="modal" style="max-width:640px;">
      <button class="modal-close" onclick="sluitModal('modal-idee')">✕</button>
      <h2 id="idee-titel-kop">Idee indienen</h2>

      <label>Plan (bijv. "Advertentie en/of artikel indienen aan Interplay Media")</label>
      <input id="id-titel" />

      <label>Target (instantie, optioneel)</label>
      <div class="form-grid">
        <div><select id="id-target-select" onchange="targetSelectGewijzigd()"><option value="">— kies een bestaande target —</option></select></div>
        <div><input id="id-target-vrij" placeholder="Of typ een naam (nog geen target)" /></div>
      </div>

      <div class="form-grid" style="margin-top:8px;">
        <div><label>Deadline voor indienen (optioneel)</label><input id="id-deadline" type="date" /></div>
        <div><label>Datum eerste contact (optioneel)</label><input id="id-eerstecontact" type="date" /></div>
      </div>
      <label style="display:flex; align-items:center; gap:6px; margin-top:8px;">
        <input type="checkbox" id="id-benaderd" style="width:auto;" /> Al benaderd
      </label>

      <label style="margin-top:10px;">Materialen (advertentie-concept, brief, artikel, correspondentie — meerdere mogelijk)</label>
      <input type="file" id="id-materiaal-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt,.eml,.msg" style="display:none;" onchange="materiaalUploaden()" />
      <button type="button" class="secondary" onclick="document.getElementById('id-materiaal-input').click()">+ Materiaal toevoegen</button>
      <div id="id-materiaal-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
      <div id="id-materialen-lijst" style="margin-top:6px;"></div>

      <label style="margin-top:10px;">Vervolgacties</label>
      <div id="id-vervolgacties-lijst"></div>
      <button type="button" class="secondary" onclick="voegVervolgactieToe()">+ Vervolgactie toevoegen</button>

      <div style="margin-top:14px; text-align:right;">
        <button class="primary" onclick="ideeOpslaan()">Opslaan</button>
      </div>
    </div>
  </div>

</div>

<!-- ===== Modal: locatie detail / bewerken ===== -->
<div id="modal-locatie" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-locatie')">
  <div class="modal" style="max-width:680px;">
    <button class="modal-close" onclick="sluitModal('modal-locatie')">✕</button>
    <h2 id="loc-titel">Nieuwe locatie</h2>

    <div class="form-grid">
      <div class="full"><label>Naam instantie *</label><input id="f-naam" /></div>
      <div><label>Type</label><input id="f-type" placeholder="bijv. huisartsenpraktijk" /></div>
      <div><label>Plaats</label><input id="f-plaats" /></div>
      <div class="full"><label>Adres</label><input id="f-adres" /></div>
      <div><label>Postcode</label><input id="f-postcode" /></div>
      <div><label>Coördinaten (voor de kaart)</label>
        <div style="display:flex; gap:6px;">
          <input id="f-lat" placeholder="lat" />
          <input id="f-lng" placeholder="lng" />
          <button type="button" class="secondary" style="white-space:nowrap; padding:8px 10px;" onclick="zoekCoordinaten()">📍 Zoeken</button>
        </div>
        <div id="geocode-status" style="font-size:11.5px; color:var(--grey); margin-top:3px;"></div>
      </div>

      <div class="full"><hr style="border:none; border-top:1px solid var(--border); margin:14px 0;"></div>
      <div><label>Receptie telefoonnummer</label><input id="f-receptieTelefoon" /></div>
      <div><label>Algemeen e-mailadres</label><input id="f-algemeenEmail" /></div>
      <div><label>Naam contactpersoon</label><input id="f-contactpersoonNaam" /></div>
      <div><label>Telefoon contactpersoon</label><input id="f-contactpersoonTelefoon" /></div>
      <div class="full"><label>E-mail contactpersoon</label><input id="f-contactpersoonEmail" /></div>

      <div class="full"><hr style="border:none; border-top:1px solid var(--border); margin:14px 0;"></div>
      <div class="full">
        <div class="checkbox-row"><input type="checkbox" id="f-flyersToegestaan" /><label style="margin:0;">Flyers plaatsen toegestaan</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-posterToegestaan" /><label style="margin:0;">Poster ophangen toegestaan</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-voorlichtingPersoneelInteresse" /><label style="margin:0;">Interesse in voorlichting voor personeel</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-folderClientenInteresse" /><label style="margin:0;">Interesse in informatiefolders voor cliënten</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-infoPakketGeleverd" /><label style="margin:0;">Heeft een C.A. infopakket ontvangen (balie/brievenbus of fysiek)</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-heeftFolderrek" /><label style="margin:0;">Heeft al een folderrek</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-wilFolderrek" /><label style="margin:0;">Wil een folderrek</label></div>
        <div class="checkbox-row">
          <input type="checkbox" id="f-contactOnderhoudGewenst" /><label style="margin:0;">Regelmatig contact onderhouden</label>
          <select id="f-contactOnderhoudMethode" style="width:auto; margin-left:8px;">
            <option value="">methode…</option><option value="email">e-mail</option><option value="telefoon">telefoon</option>
          </select>
        </div>
        <div class="checkbox-row">
          <input type="checkbox" id="f-schermAanwezig" /><label style="margin:0;">Scherm aanwezig (bijv. wachtkamer)</label>
          <select id="f-schermOrientatie" style="width:auto; margin-left:8px;">
            <option value="">oriëntatie…</option><option value="horizontaal">horizontaal</option><option value="verticaal">verticaal</option>
          </select>
        </div>
        <div class="checkbox-row"><input type="checkbox" id="f-schermAnimatieInteresse" /><label style="margin:0;">Interesse in animatie op scherm</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-isInrichting" /><label style="margin:0;">Betreft een inrichting</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-heeftClientenBibliotheek" /><label style="margin:0;">Heeft cliëntenbibliotheek</label></div>
        <div class="checkbox-row"><input type="checkbox" id="f-literatuurBehoefteCA_AA" /><label style="margin:0;">Behoefte aan CA/AA-literatuur</label></div>
      </div>

      <div class="full"><label>Notitie</label><textarea id="f-notitie" rows="2"></textarea></div>
    </div>

    <div style="margin-top:16px; display:flex; gap:8px; justify-content:space-between;">
      <button id="btn-verwijder-locatie" class="secondary" style="display:none; color:#c9432f; border-color:#c9432f;" onclick="verwijderLocatie()">Verwijderen</button>
      <div style="display:flex; gap:8px; margin-left:auto;">
        <button class="secondary" onclick="sluitModal('modal-locatie')">Annuleren</button>
        <button class="primary" onclick="opslaanLocatie()">Opslaan</button>
      </div>
    </div>

    <div id="detail-activiteiten" style="display:none; margin-top:22px; border-top:1px solid var(--border); padding-top:16px;">
      <div class="toolbar" style="margin-bottom:6px;">
        <strong>Activiteiten-tijdlijn</strong>
        <button class="secondary" onclick="toonActiviteitForm()">+ Activiteit toevoegen</button>
      </div>
      <div id="activiteit-form" style="display:none; background:var(--primary-light-95); border-radius:8px; padding:12px; margin-bottom:12px;">
        <div class="form-grid">
          <div><label>Datum</label><input type="date" id="a-datum" /></div>
          <div><label>Type</label>
            <select id="a-type">
              <option value="bezoek">Bezoek</option>
              <option value="folders_bijgevuld">Folders bijgevuld</option>
              <option value="poster_gecontroleerd">Poster gecontroleerd</option>
              <option value="telefonisch_contact">Telefonisch contact</option>
              <option value="voorlichting_gegeven">Voorlichting gegeven</option>
              <option value="wijziging_gesignaleerd">Wijziging gesignaleerd (bijv. nieuw scherm/personeel)</option>
              <option value="anders">Anders</option>
            </select>
          </div>
          <div class="full"><label>Omschrijving</label><textarea id="a-omschrijving" rows="2"></textarea></div>
        </div>
        <div style="text-align:right; margin-top:8px;"><button class="primary" onclick="opslaanActiviteit()">Toevoegen</button></div>
      </div>
      <div id="activiteiten-lijst"></div>
    </div>
  </div>
</div>

<!-- ===== Modal: nieuwe campagne ===== -->
<div id="modal-campagne" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) sluitModal('modal-campagne')">
  <div class="modal">
    <button class="modal-close" onclick="sluitModal('modal-campagne')">✕</button>
    <h2 id="c-kop">Nieuwe campagne</h2>
    <label>Naam</label><input id="c-naam" />
    <label>Datum</label><input id="c-datum" type="date" />
    <label>Kanaal</label><input id="c-kanaal" value="Laposta" />
    <label>Omschrijving</label><textarea id="c-omschrijving" rows="2"></textarea>
    <label>Bijlagen</label>
    <input type="file" id="c-bijlage-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.txt,.eml,.msg" style="display:none;" onchange="bijlageUploaden('c')" />
    <button type="button" class="secondary" onclick="document.getElementById('c-bijlage-input').click()">+ Bijlage toevoegen</button>
    <div id="c-bijlage-status" style="font-size:12px; color:var(--grey); margin-top:4px;"></div>
    <div id="c-bijlage-lijst" style="margin-top:6px;"></div>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" id="c-opslaan" onclick="opslaanCampagne()">Aanmaken</button>
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

// ---- Klantenbestand ----------------------------------------------------

async function laadLocaties() {
  const res = await fetch('api_locaties.php?action=list');
  const data = await res.json();
  locaties = data.locaties;
  renderLijst();
}

function renderLijst() {
  const zoek = document.getElementById('zoek').value.toLowerCase();
  const sortering = document.getElementById('sorteer').value;
  const sorteerFuncties = {
    'instantie-az': (a, b) => a.naam.localeCompare(b.naam, 'nl'),
    'instantie-za': (a, b) => b.naam.localeCompare(a.naam, 'nl'),
    'plaats-az': (a, b) => (a.plaats || '').localeCompare(b.plaats || '', 'nl'),
    'plaats-za': (a, b) => (b.plaats || '').localeCompare(a.plaats || '', 'nl'),
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
        <td>${l.laatsteActiviteit ? formatDatum(l.laatsteActiviteit) + ' (' + l.dagenSindsLaatsteActiviteit + 'd)' : '<span class="badge grijs">nog geen</span>'}</td>
        <td>${l.aantalActiviteiten}</td>
      </tr>`).join('');
  document.getElementById('lijst-body').innerHTML = rows || '<tr><td colspan="6">Geen locaties gevonden.</td></tr>';
}

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

let datumformaatVolgorde = 'dmy'; // 'dmy' | 'mdy' | 'ymd' — geladen via laadInstellingenLocaties()
function formatDatum(iso) {
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '';
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const jj = d.getFullYear();
  if (datumformaatVolgorde === 'mdy') return `${mm}-${dd}-${jj}`;
  if (datumformaatVolgorde === 'ymd') return `${jj}-${mm}-${dd}`;
  return `${dd}-${mm}-${jj}`;
}
async function laadInstellingenLocaties() {
  const res = await fetch('api_leden.php?action=instellingen_get', { cache: 'no-store' });
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
  document.getElementById('loc-titel').textContent = 'Nieuwe locatie';
  document.getElementById('detail-activiteiten').style.display = 'none';
  document.getElementById('btn-verwijder-locatie').style.display = 'none';
  document.getElementById('modal-locatie').style.display = 'flex';
}

async function openLocatie(id) {
  const res = await fetch('api_locaties.php?action=get&id=' + encodeURIComponent(id));
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
  if (!adres && !plaats) { statusEl.textContent = 'Vul eerst een adres of plaats in.'; return; }

  statusEl.textContent = 'Zoeken…';
  const params = new URLSearchParams({ adres, postcode, plaats });
  try {
    const res = await fetch('api_locaties.php?action=geocode&' + params.toString());
    const data = await res.json();
    if (data.gevonden) {
      document.getElementById('f-lat').value = data.lat;
      document.getElementById('f-lng').value = data.lng;
      statusEl.textContent = '✓ Gevonden: ' + (data.label || (data.lat + ', ' + data.lng));
    } else {
      statusEl.textContent = 'Niet gevonden — vul de coördinaten zo nodig handmatig in.';
    }
  } catch (e) {
    statusEl.textContent = 'Zoeken mislukt — vul de coördinaten zo nodig handmatig in.';
  }
}

async function opslaanLocatie() {
  const body = verzamelFormulier();
  if (!body.naam) { alert('Naam van de instantie is verplicht.'); return; }

  // Automatisch geocoden als er nog geen coördinaten zijn ingevuld, zodat
  // de locatie ook op de kaart verschijnt zonder dat iemand het zelf hoeft
  // op te zoeken. Lukt het niet, dan slaan we gewoon op zonder coördinaten.
  if (!body.lat || !body.lng) {
    try {
      const params = new URLSearchParams({ adres: body.adres, postcode: body.postcode, plaats: body.plaats });
      const res = await fetch('api_locaties.php?action=geocode&' + params.toString());
      const data = await res.json();
      if (data.gevonden) { body.lat = data.lat; body.lng = data.lng; }
    } catch (e) { /* geen probleem, gewoon zonder coördinaten opslaan */ }
  }

  const url = huidigeLocatieId
    ? 'api_locaties.php?action=update&id=' + encodeURIComponent(huidigeLocatieId)
    : 'api_locaties.php?action=create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { alert('Opslaan mislukt.'); return; }
  sluitModal('modal-locatie');
  laadLocaties();
}

async function verwijderLocatie() {
  if (!huidigeLocatieId) return;
  if (!confirm('Deze locatie definitief verwijderen? Alle activiteiten die hierbij horen gaan ook mee weg.')) return;
  await fetch('api_locaties.php?action=delete&id=' + encodeURIComponent(huidigeLocatieId), { method: 'POST', credentials: 'same-origin' });
  sluitModal('modal-locatie');
  laadLocaties();
}

// ---- Duplicaten opruimen ---------------------------------------------

async function checkDuplicaten() {
  const res = await fetch('api_locaties.php?action=duplicaten_zoeken');
  const data = await res.json();
  if (!data.groepen.length) { alert('Geen duplicaten gevonden (zelfde naam + postcode + plaats).'); return; }

  const aantalTeVerwijderen = data.groepen.reduce((som, g) => som + g.length - 1, 0);
  const overzicht = data.groepen.map(g => `- "${g[0].naam}" (${g.length}x)`).join('\n');
  const bevestiging = confirm(
    `${data.groepen.length} groep(en) met duplicaten gevonden, ${aantalTeVerwijderen} locatie(s) worden verwijderd:\n\n${overzicht}\n\n` +
    `Per groep blijft de locatie met de meeste activiteiten staan (bij gelijke stand: de oudste). Doorgaan?`
  );
  if (!bevestiging) return;

  const verwRes = await fetch('api_locaties.php?action=duplicaten_verwijderen', { method: 'POST', credentials: 'same-origin' });
  const verwData = await verwRes.json();
  alert(verwData.verwijderd + ' duplicaat/duplicaten verwijderd.');
  laadLocaties();
}

// ---- Activiteiten --------------------------------------------------

function renderActiviteiten(activiteiten) {
  const labels = {
    bezoek: 'Bezoek', folders_bijgevuld: 'Folders bijgevuld', poster_gecontroleerd: 'Poster gecontroleerd',
    telefonisch_contact: 'Telefonisch contact', voorlichting_gegeven: 'Voorlichting gegeven',
    wijziging_gesignaleerd: 'Wijziging gesignaleerd', anders: 'Anders',
  };
  const el = document.getElementById('activiteiten-lijst');
  if (!activiteiten.length) { el.innerHTML = '<p style="color:var(--grey); font-size:13px;">Nog geen activiteiten geregistreerd.</p>'; return; }
  el.innerHTML = activiteiten.map(a => `
    <div class="activity-item">
      <div><strong>${labels[a.type] || a.type}</strong> — ${formatDatum(a.datum)}</div>
      ${a.omschrijving ? `<div>${escapeHtml(a.omschrijving)}</div>` : ''}
      <div class="meta">door ${escapeHtml(a.door)}</div>
    </div>`).join('');
}

function toonActiviteitForm() {
  document.getElementById('a-datum').value = new Date().toISOString().slice(0, 10);
  document.getElementById('activiteit-form').style.display = '';
}

async function opslaanActiviteit() {
  const body = { datum: document.getElementById('a-datum').value, type: document.getElementById('a-type').value, omschrijving: document.getElementById('a-omschrijving').value };
  const res = await fetch('api_locaties.php?action=activity_add&id=' + encodeURIComponent(huidigeLocatieId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  const data = await res.json();
  if (!res.ok) { alert(data.error || 'Opslaan mislukt.'); return; }
  document.getElementById('activiteit-form').style.display = 'none';
  document.getElementById('a-omschrijving').value = '';
  renderActiviteiten(data.locatie.activiteiten.sort((a, b) => b.datum.localeCompare(a.datum)));
  laadLocaties();
}

// ---- Bulk-import (CSV) -----------------------------------------------

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
        <p>${rijen.length} rijen gevonden. Controleer de eerste paar en klik op importeren.</p>
        <table><thead><tr><th>Naam</th><th>Plaats</th><th>Contactpersoon</th></tr></thead>
        <tbody>${rijen.slice(0, 5).map(r => `<tr><td>${escapeHtml(r.naam || '')}</td><td>${escapeHtml(r.plaats || '')}</td><td>${escapeHtml(r.contactpersoonNaam || '')}</td></tr>`).join('')}</tbody></table>
        <button class="primary" id="btn-importeer" style="margin-top:12px;">Importeer ${rijen.length} locaties</button>
      `;
      document.getElementById('btn-importeer').addEventListener('click', importeerRijen);
    },
  });
});

async function importeerRijen() {
  const rijen = laatstIngelezenRijen;
  const res = await fetch('api_locaties.php?action=bulk_import', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rijen }),
  });
  const data = await res.json();
  document.getElementById('bulk-preview').innerHTML = `<p>${data.aangemaakt} locaties geïmporteerd.` +
    (data.fouten.length ? `<br>Fouten: ${data.fouten.join(', ')}` : '') + `</p>`;
  document.getElementById('csvInput').value = '';
  laadLocaties();
}

// ---- Bijlagen-widget (campagnes en targets; ideeën hebben hun eigen, zelfde endpoint) ----------
const bijlagen = { c: [], tg: [] };
function bijlagenHtml(lijst) {
  if (!lijst || !lijst.length) return '';
  return '<div style="font-size:12px; margin-top:3px;">' + lijst.map(m => `📎 <a href="api_locaties.php?action=materiaal_stream&bestand=${encodeURIComponent(m.pad)}&naam=${encodeURIComponent(m.bestandsnaam)}">${escapeHtml(m.bestandsnaam)}</a>`).join(' &nbsp; ') + '</div>';
}
function bijlageStart(p, lijst) {
  bijlagen[p] = [...(lijst || [])];
  document.getElementById(p + '-bijlage-status').textContent = '';
  document.getElementById(p + '-bijlage-input').value = '';
  bijlageRender(p);
}
function bijlageRender(p) {
  document.getElementById(p + '-bijlage-lijst').innerHTML = bijlagen[p].map((m, i) => `
    <div style="display:flex; justify-content:space-between; align-items:center; padding:3px 0; font-size:12.5px;">
      <span>📎 ${escapeHtml(m.bestandsnaam)}</span>
      <button type="button" class="secondary" style="padding:1px 8px;" onclick="bijlagen['${p}'].splice(${i},1); bijlageRender('${p}');">✕</button>
    </div>`).join('');
}
async function bijlageUploaden(p) {
  const inputEl = document.getElementById(p + '-bijlage-input');
  if (!inputEl.files.length) return;
  const statusEl = document.getElementById(p + '-bijlage-status');
  statusEl.textContent = 'Uploaden…';
  const fd = new FormData();
  fd.append('bestand', inputEl.files[0]);
  let res, data = {};
  try { res = await fetch('api_locaties.php?action=materiaal_upload', { method: 'POST', credentials: 'same-origin', body: fd }); data = await res.json(); }
  catch (e) { statusEl.textContent = 'Upload mislukt (verbinding of bestand te groot).'; inputEl.value = ''; return; }
  inputEl.value = '';
  if (!res.ok) { statusEl.textContent = data.error || 'Upload mislukt.'; return; }
  statusEl.textContent = '✓ toegevoegd: ' + data.bestandsnaam;
  bijlagen[p].push(data);
  bijlageRender(p);
}

// ---- Campagnes ----------------------------------------------------

async function laadCampagnes() {
  const res = await fetch('api_locaties.php?action=campaign_list', { cache: 'no-store' });
  const data = await res.json();
  const statusBadge = { wacht_op_goedkeuring: 'geel', goedgekeurd: 'groen', afgewezen: 'rood' };
  const statusLabel = { wacht_op_goedkeuring: 'wacht op goedkeuring', goedgekeurd: 'goedgekeurd', afgewezen: 'afgewezen' };

  const renderRij = (c, magGoedkeuren) => `
    <tr>
      <td>${escapeHtml(c.naam)}${bijlagenHtml(c.materialen)}</td><td>${formatDatum(c.datum)}</td><td>${escapeHtml(c.kanaal)}</td><td>${c.deelnemers.length}</td>
      <td>
        <span class="badge ${statusBadge[c.status] || 'grijs'}">${statusLabel[c.status] || c.status}</span>
        <button class="secondary" onclick="openNieuweCampagne(${escapeHtml(JSON.stringify(c.type || 'fysiek'))}, ${escapeHtml(JSON.stringify(c))})" title="Bewerken / bijlagen toevoegen">✎</button>
        ${c.status === 'wacht_op_goedkeuring' && magGoedkeuren ? `
          <button class="secondary" onclick="campagneGoedkeuren('${c.id}')">✓ Goedkeuren</button>
          <button class="secondary" onclick="campagneAfwijzenOpenen('${c.id}')">✕ Afwijzen</button>
        ` : ''}
      </td>
    </tr>`;

  document.getElementById('campagnes-body-fysiek').innerHTML = data.campagnes.filter(c => (c.type || 'fysiek') === 'fysiek').map(c => renderRij(c, magFysiekGoedkeuren)).join('') || '<tr><td colspan="5">Nog geen campagnes.</td></tr>';
  document.getElementById('campagnes-body-online').innerHTML = data.campagnes.filter(c => c.type === 'online').map(c => renderRij(c, magOnlineGoedkeuren)).join('') || '<tr><td colspan="5">Nog geen campagnes.</td></tr>';
}

async function campagneGoedkeuren(id) {
  await fetch('api_locaties.php?action=campaign_goedkeuren&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  laadCampagnes();
}

let huidigAfTeWijzenCampagneId = null;
function campagneAfwijzenOpenen(id) {
  huidigAfTeWijzenCampagneId = id;
  document.getElementById('ca-opmerking').value = '';
  document.getElementById('modal-campagne-afwijzen').style.display = 'flex';
}
async function campagneAfwijzenBevestigen() {
  await fetch('api_locaties.php?action=campaign_afwijzen&id=' + encodeURIComponent(huidigAfTeWijzenCampagneId), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ opmerking: document.getElementById('ca-opmerking').value }),
  });
  sluitModal('modal-campagne-afwijzen');
  laadCampagnes();
}

let huidigCampagneType = 'fysiek';
let huidigBewerkCampagneId = null;
function openNieuweCampagne(type, bestaand) {
  huidigCampagneType = type;
  huidigBewerkCampagneId = bestaand ? bestaand.id : null;
  document.getElementById('c-kop').textContent = bestaand ? 'Campagne bewerken' : 'Nieuwe campagne';
  document.getElementById('c-opslaan').textContent = bestaand ? 'Opslaan' : 'Aanmaken';
  document.getElementById('c-naam').value = bestaand ? bestaand.naam : '';
  document.getElementById('c-datum').value = bestaand ? (bestaand.datum || '') : new Date().toISOString().slice(0, 10);
  document.getElementById('c-kanaal').value = bestaand ? (bestaand.kanaal || '') : (type === 'online' ? 'Social media' : 'Laposta');
  document.getElementById('c-omschrijving').value = bestaand ? (bestaand.omschrijving || '') : '';
  bijlageStart('c', bestaand ? bestaand.materialen : []);
  document.getElementById('modal-campagne').style.display = 'flex';
}

async function opslaanCampagne() {
  const body = { naam: document.getElementById('c-naam').value, datum: document.getElementById('c-datum').value, kanaal: document.getElementById('c-kanaal').value, omschrijving: document.getElementById('c-omschrijving').value, type: huidigCampagneType, materialen: bijlagen.c };
  if (!body.naam.trim()) { alert('Naam is verplicht.'); return; }
  const url = huidigBewerkCampagneId ? 'api_locaties.php?action=campaign_update&id=' + encodeURIComponent(huidigBewerkCampagneId) : 'api_locaties.php?action=campaign_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Opslaan mislukt.'); return; }
  sluitModal('modal-campagne');
  laadCampagnes();
}

laadInstellingenLocaties().then(laadLocaties);

// ---- Targets ----------------------------------------------------

let targetsData = [];

async function laadTargets() {
  const res = await fetch('api_locaties.php?action=target_list', { cache: 'no-store' });
  const data = await res.json();
  targetsData = data.targets;
  const renderRij = (t) => `
    <tr>
      <td>${escapeHtml(t.naam)}${bijlagenHtml(t.materialen)}</td>
      <td>${escapeHtml(t.omschrijving || '—')}</td>
      <td style="white-space:nowrap;">
        <button class="secondary" onclick="openTargetForm(${escapeHtml(JSON.stringify(t.type || 'fysiek'))}, ${escapeHtml(JSON.stringify(t))})">✎</button>
        <button class="secondary" onclick="targetVerwijderen('${t.id}')">✕</button>
      </td>
    </tr>`;
  document.getElementById('targets-body-fysiek').innerHTML = targetsData.filter(t => (t.type || 'fysiek') === 'fysiek').map(renderRij).join('') || '<tr><td colspan="3">Nog geen targets.</td></tr>';
  document.getElementById('targets-body-online').innerHTML = targetsData.filter(t => t.type === 'online').map(renderRij).join('') || '<tr><td colspan="3">Nog geen targets.</td></tr>';
}

let huidigBewerkTargetId = null;
let huidigTargetType = 'fysiek';
function openTargetForm(type, bestaand) {
  huidigTargetType = type;
  huidigBewerkTargetId = bestaand ? bestaand.id : null;
  document.getElementById('target-titel-kop').textContent = bestaand ? 'Target bewerken' : 'Target toevoegen (' + (type === 'online' ? 'online' : 'fysiek') + ')';
  document.getElementById('tg-naam').value = bestaand ? bestaand.naam : '';
  document.getElementById('tg-omschrijving').value = bestaand ? (bestaand.omschrijving || '') : '';
  bijlageStart('tg', bestaand ? bestaand.materialen : []);
  document.getElementById('modal-target').style.display = 'flex';
}

async function targetOpslaan() {
  const body = { naam: document.getElementById('tg-naam').value, omschrijving: document.getElementById('tg-omschrijving').value, type: huidigTargetType, materialen: bijlagen.tg };
  if (!body.naam.trim()) { alert('Naam is verplicht.'); return; }
  const url = huidigBewerkTargetId
    ? 'api_locaties.php?action=target_update&id=' + encodeURIComponent(huidigBewerkTargetId)
    : 'api_locaties.php?action=target_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Opslaan mislukt.'); return; }
  sluitModal('modal-target');
  laadTargets();
}

async function targetVerwijderen(id) {
  if (!confirm('Deze target verwijderen?')) return;
  const res = await fetch('api_locaties.php?action=target_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Verwijderen mislukt.'); return; }
  laadTargets();
}

// ---- Ideeontwikkeling ----------------------------------------------------

let ideeenData = [];
let huidigBewerkIdeeId = null;
let materialenHuidigIdee = [];
let vervolgactiesHuidigIdee = [];

async function laadIdeeen() {
  if (!targetsData.length) await laadTargets();
  const res = await fetch('api_locaties.php?action=idee_list', { cache: 'no-store' });
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
          <span class="badge ${idee.status === 'afgerond' ? 'groen' : (idee.benaderd ? 'geel' : 'grijs')}">${idee.status === 'afgerond' ? 'afgerond' : (idee.benaderd ? 'benaderd' : 'nog niet benaderd')}</span>
          ${teLaat ? '<span class="badge rood">deadline verstreken</span>' : ''}
          ${idee.targetNaam ? `<div style="font-size:12.5px; color:var(--grey); margin-top:2px;">Target: ${escapeHtml(idee.targetNaam)}</div>` : ''}
          <div style="font-size:12px; color:var(--grey); margin-top:2px;">
            Geregistreerd: ${formatDatum(idee.datumRegistratie)}
            ${idee.datumEersteContact ? ' — Eerste contact: ' + formatDatum(idee.datumEersteContact) : ''}
            ${idee.deadline ? ' — Deadline: ' + formatDatum(idee.deadline) : ''}
          </div>
          ${idee.materialen.length ? `<div style="font-size:12.5px; margin-top:6px;">📎 ${idee.materialen.map(m => `<a href="api_locaties.php?action=materiaal_stream&bestand=${encodeURIComponent(m.pad)}&naam=${encodeURIComponent(m.bestandsnaam)}" target="_blank">${escapeHtml(m.bestandsnaam)}</a>`).join(', ')}</div>` : ''}
          ${idee.vervolgacties.length ? `
            <div style="margin-top:8px;">
              <strong style="font-size:12.5px;">Vervolgacties (${idee.vervolgacties.length - openVervolgacties}/${idee.vervolgacties.length} afgerond):</strong>
              <ul style="margin:4px 0 0; padding-left:18px; font-size:12.5px;">
                ${idee.vervolgacties.map(v => `<li style="${v.voltooid ? 'text-decoration:line-through; color:var(--grey);' : ''}">${escapeHtml(v.tekst)}${v.datum ? ' — ' + formatDatum(v.datum) : ''}${v.voltooid ? ' ✓ (' + formatDatum(v.voltooidOp) + ')' : ''}</li>`).join('')}
              </ul>
            </div>` : ''}
        </div>
        <div style="white-space:nowrap;">
          ${magAfronden ? `<button class="secondary" onclick="ideeStatusWijzigen('${idee.id}', '${idee.status === 'afgerond' ? 'open' : 'afgerond'}')">${idee.status === 'afgerond' ? '↺ Heropenen' : '✓ Afronden'}</button>` : ''}
          <button class="secondary" onclick="openIdeeForm(${escapeHtml(JSON.stringify(idee.type || 'fysiek'))}, ${escapeHtml(JSON.stringify(idee))})">✎</button>
          <button class="secondary" onclick="ideeVerwijderen('${idee.id}')">✕</button>
        </div>
      </div>
    </div>`;
  };

  document.getElementById('ideeen-lijst-fysiek').innerHTML = ideeenData.filter(i => (i.type || 'fysiek') === 'fysiek').map(i => maakKaart(i, magFysiekGoedkeuren)).join('') || '<p style="color:var(--grey);">Nog geen ideeën ingediend.</p>';
  document.getElementById('ideeen-lijst-online').innerHTML = ideeenData.filter(i => i.type === 'online').map(i => maakKaart(i, magOnlineGoedkeuren)).join('') || '<p style="color:var(--grey);">Nog geen ideeën ingediend.</p>';
}

async function ideeStatusWijzigen(id, status) {
  const res = await fetch('api_locaties.php?action=idee_update&id=' + encodeURIComponent(id), {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Wijzigen mislukt.'); return; }
  laadIdeeen();
}

let huidigIdeeType = 'fysiek';
async function openIdeeForm(type, bestaand) {
  huidigIdeeType = bestaand ? (bestaand.type || 'fysiek') : type;
  huidigBewerkIdeeId = bestaand ? bestaand.id : null;
  document.getElementById('idee-titel-kop').textContent = bestaand ? 'Idee bewerken' : 'Idee indienen (' + (huidigIdeeType === 'online' ? 'online' : 'fysiek') + ')';
  document.getElementById('id-titel').value = bestaand ? bestaand.titel : '';
  document.getElementById('id-deadline').value = bestaand ? (bestaand.deadline || '') : '';
  document.getElementById('id-eerstecontact').value = bestaand ? (bestaand.datumEersteContact || '') : '';
  document.getElementById('id-benaderd').checked = bestaand ? bestaand.benaderd : false;
  document.getElementById('id-target-vrij').value = bestaand ? (bestaand.targetId ? '' : (bestaand.targetNaam || '')) : '';

  if (!targetsData.length) await laadTargets();
  const relevanteTargets = targetsData.filter(t => (t.type || 'fysiek') === huidigIdeeType);
  document.getElementById('id-target-select').innerHTML = '<option value="">— kies een bestaande target —</option>' +
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
  statusEl.textContent = 'Uploaden…';
  const fd = new FormData();
  fd.append('bestand', inputEl.files[0]);
  const res = await fetch('api_locaties.php?action=materiaal_upload', { method: 'POST', credentials: 'same-origin', body: fd });
  const data = await res.json();
  inputEl.value = '';
  if (!res.ok) { statusEl.textContent = data.error || 'Upload mislukt.'; return; }
  statusEl.textContent = '✓ toegevoegd: ' + data.bestandsnaam;
  materialenHuidigIdee.push(data);
  renderMaterialenLijst();
}

function renderVervolgactiesLijst() {
  document.getElementById('id-vervolgacties-lijst').innerHTML = vervolgactiesHuidigIdee.map((v, i) => `
    <div class="checkbox-row" data-idx="${i}">
      <input type="checkbox" ${v.voltooid ? 'checked' : ''} onchange="vervolgactieToggle(${i}, this.checked)" style="width:auto;" />
      <input class="vv-tekst" placeholder="Actie (bijv. herinneringsmail sturen)" value="${escapeHtml(v.tekst)}" oninput="vervolgactiesHuidigIdee[${i}].tekst = this.value" style="flex:2; ${v.voltooid ? 'text-decoration:line-through; color:var(--grey);' : ''}" />
      <input type="date" class="vv-datum" value="${v.datum || ''}" onchange="vervolgactiesHuidigIdee[${i}].datum = this.value" style="width:150px;" />
      ${v.voltooid ? `<span class="badge groen" style="white-space:nowrap;">✓ ${v.voltooidOp ? formatDatum(v.voltooidOp) : ''}</span>` : ''}
      <button type="button" class="secondary" onclick="vervolgactiesHuidigIdee.splice(${i},1); renderVervolgactiesLijst();">✕</button>
    </div>`).join('');
}

function vervolgactieToggle(i, voltooid) {
  vervolgactiesHuidigIdee[i].voltooid = voltooid;
  vervolgactiesHuidigIdee[i].voltooidOp = voltooid ? new Date().toISOString().slice(0, 10) : null;
  renderVervolgactiesLijst(); // hertekenen zodat doorstreping/badge meteen klopt; blijft gewoon aanpasbaar (checkbox uitzetten maakt 'm weer open)
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
  if (!body.titel.trim()) { alert('Plan/titel is verplicht.'); return; }
  const url = huidigBewerkIdeeId
    ? 'api_locaties.php?action=idee_update&id=' + encodeURIComponent(huidigBewerkIdeeId)
    : 'api_locaties.php?action=idee_create';
  const res = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Opslaan mislukt.'); return; }
  sluitModal('modal-idee');
  laadIdeeen();
}

async function ideeVerwijderen(id) {
  if (!confirm('Dit idee helemaal verwijderen? Geüploade materialen worden dan ook verwijderd.')) return;
  const res = await fetch('api_locaties.php?action=idee_delete&id=' + encodeURIComponent(id), { method: 'POST', credentials: 'same-origin' });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Verwijderen mislukt.'); return; }
  laadIdeeen();
}

// URL-parameters vanaf het dashboard (?nieuw=1, ?tab=bulk, ?tab=campagnes, ?open=locatie-id)
const params = new URLSearchParams(location.search);
if (params.get('nieuw') === '1') openNieuweLocatie();
if (params.get('tab')) toonView(params.get('tab'));
if (params.get('open')) openLocatie(params.get('open'));
</script>
</body>
</html>
