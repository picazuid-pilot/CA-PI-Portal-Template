<?php
require __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Drukwerk bestellen — <?= htmlspecialchars(DISTRICT_NAME) ?> Public Information</title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
  <div class="topbar">
    <div class="brand">📋 <?= htmlspecialchars(DISTRICT_NAME) ?> — Public Information</div>
  </div>

  <div class="container" style="max-width:640px;">
    <div class="card">
      <h2 style="margin-top:0; color:var(--primary);">Drukwerk / literatuur bestellen</h2>
      <p style="color:var(--grey); font-size:13.5px;">
        Voor instellingen en professionals die zelf hun voorraad folders, flyers of posters van
        <?= htmlspecialchars(DISTRICT_NAME) ?> willen aanvullen. Vul in wat u nodig heeft — wij nemen
        het door en sturen het toe.
      </p>

      <div id="formulier">
        <label>Naam instantie *</label><input id="e-instelling" required />
        <label>Contactpersoon</label><input id="e-contactpersoon" />
        <div class="form-grid">
          <div><label>E-mailadres *</label><input id="e-email" type="email" required /></div>
          <div><label>Telefoonnummer</label><input id="e-telefoon" /></div>
        </div>
        <label>Afleveradres <span style="color:var(--grey); font-weight:400;">(verplicht bij een bestelling)</span></label><input id="e-adres" placeholder="Straat + huisnummer, postcode, plaats" />

        <h3 style="color:var(--primary); margin:22px 0 4px;">Literatuur / folders</h3>
        <p style="color:var(--grey); font-size:12.5px; margin:0 0 10px;">Vul per titel het gewenste aantal in (0 = niet nodig).</p>
        <div id="literatuur-lijst">Laden…</div>

        <h3 style="color:var(--primary); margin:22px 0 4px;">Flyers, posters &amp; overig</h3>
        <div class="form-grid">
          <div>
            <label>A5 Flyers (per 12 stuks)</label>
            <input id="e-flyers" type="number" min="0" step="12" value="0" />
          </div>
          <div>
            <label>A3 Posters (max. 2 per aanvraag)</label>
            <input id="e-posters" type="number" min="0" max="2" value="0" />
          </div>
          <div>
            <label>Visitekaartjes (per 12 stuks)</label>
            <input id="e-visitekaartjes" type="number" min="0" step="12" value="0" />
          </div>
          <div>
            <label>Chitcards / deelnamebewijzen (per 12 stuks)</label>
            <input id="e-chitcards" type="number" min="0" step="12" value="0" />
          </div>
        </div>

        <label>Opmerking (optioneel)</label>
        <textarea id="e-opmerking" rows="2"></textarea>

        <h3 style="color:var(--primary); margin:22px 0 4px;">Voorlichting aanvragen</h3>
        <div class="checkbox-row">
          <input type="checkbox" id="e-voorlichting" onchange="document.getElementById('voorlichting-velden').style.display = this.checked ? 'block' : 'none'" />
          <label style="margin:0;">Wij willen ook voorlichting voor ons personeel aanvragen</label>
        </div>
        <div id="voorlichting-velden" style="display:none;">
          <label>Adres waar de voorlichting plaatsvindt (indien anders dan hierboven)</label>
          <input id="e-vl-adres" placeholder="Straat, huisnummer, postcode, plaats" />
          <div class="form-grid">
            <div><label>Gewenste datum (bij benadering mag ook)</label><input id="e-vl-datum" type="date" /></div>
            <div><label>Gewenste tijd (optioneel)</label><input id="e-vl-tijd" type="time" /></div>
          </div>
          <label>Verwacht aantal deelnemers</label><input id="e-vl-aantal" type="number" min="1" style="max-width:200px;" />
          <label>Toelichting (optioneel)</label>
          <textarea id="e-vl-toelichting" rows="2" placeholder="bijv. voor nieuwe medewerkers, of specifieke onderwerpen waar interesse in is"></textarea>
        </div>

        <!-- Honeypot: voor mensen onzichtbaar, bots vullen dit vaak per ongeluk in -->
        <div style="position:absolute; left:-9999px;" aria-hidden="true">
          <label>Website</label><input id="e-website" tabindex="-1" autocomplete="off" />
        </div>

        <div class="error" id="e-error" style="margin-top:8px;"></div>
        <button class="primary" style="margin-top:10px; width:100%;" onclick="versturen()">Bestelling versturen</button>
      </div>

      <div id="succesMelding" style="display:none; text-align:center; padding:20px 0;">
        <p style="font-size:32px; margin:0;">✓</p>
        <p style="color:var(--primary); font-weight:600;">Bedankt! Uw bestelling is verstuurd.</p>
        <p style="color:var(--grey); font-size:13px;">We nemen 'm zo snel mogelijk in behandeling en sturen het toe.</p>
      </div>
    </div>
  </div>

<script>
async function laadLiteratuur() {
  const res = await fetch('api_drukwerk.php?action=pakket_list');
  const data = await res.json();
  const pakket = data.pakketten.find(p => p.type === 'literatuur_pakket');
  const el = document.getElementById('literatuur-lijst');
  if (!pakket) { el.innerHTML = '<p style="color:var(--grey);">Geen literatuurlijst beschikbaar — vul de opmerking hieronder in met wat u nodig heeft.</p>'; return; }
  el.innerHTML = pakket.items.map(it => `
    <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border);">
      <span style="font-size:13.5px;">${escapeHtml(it.naam)}</span>
      <input type="number" min="0" value="0" data-lit-naam="${escapeHtml(it.naam)}" style="width:70px;" />
    </div>`).join('');
}
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

async function versturen() {
  const errEl = document.getElementById('e-error');
  errEl.textContent = '';

  const instellingNaam = document.getElementById('e-instelling').value.trim();
  const email = document.getElementById('e-email').value.trim();
  const afleveradres = document.getElementById('e-adres').value.trim();
  if (!instellingNaam || !email) { errEl.textContent = 'Vul minstens naam en e-mailadres in.'; return; }

  const items = [];
  document.querySelectorAll('#literatuur-lijst input[data-lit-naam]').forEach(inp => {
    const aantal = parseInt(inp.value, 10);
    if (aantal > 0) items.push({ naam: inp.dataset.litNaam, aantal });
  });

  const flyers = parseInt(document.getElementById('e-flyers').value, 10) || 0;
  if (flyers > 0) {
    if (flyers % 12 !== 0) { errEl.textContent = 'A5 Flyers kunnen alleen per 12 stuks besteld worden (12, 24, 36, ...).'; return; }
    items.push({ naam: 'A5 Flyers', aantal: flyers });
  }
  const posters = parseInt(document.getElementById('e-posters').value, 10) || 0;
  if (posters > 0) {
    if (posters > 2) { errEl.textContent = 'Maximaal 2 A3 Posters per aanvraag.'; return; }
    items.push({ naam: 'A3 Posters', aantal: posters });
  }
  const visitekaartjes = parseInt(document.getElementById('e-visitekaartjes').value, 10) || 0;
  if (visitekaartjes > 0) {
    if (visitekaartjes % 12 !== 0) { errEl.textContent = 'Visitekaartjes kunnen alleen per 12 stuks besteld worden.'; return; }
    items.push({ naam: 'Visitekaartjes', aantal: visitekaartjes });
  }
  const chitcards = parseInt(document.getElementById('e-chitcards').value, 10) || 0;
  if (chitcards > 0) {
    if (chitcards % 12 !== 0) { errEl.textContent = 'Chitcards kunnen alleen per 12 stuks besteld worden.'; return; }
    items.push({ naam: 'Chitcards', aantal: chitcards });
  }

  const voorlichtingGewenst = document.getElementById('e-voorlichting').checked;
  if (!items.length && !voorlichtingGewenst) {
    errEl.textContent = 'Kies minstens 1 item met een aantal groter dan 0, of vraag voorlichting aan.';
    return;
  }
  if (items.length && !afleveradres) {
    errEl.textContent = 'Vul een afleveradres in voor uw bestelling.';
    return;
  }

  const honeypot = document.getElementById('e-website').value;
  const taken = [];

  if (items.length) {
    const body = {
      instellingNaam, email, items,
      contactpersoon: document.getElementById('e-contactpersoon').value,
      telefoon: document.getElementById('e-telefoon').value,
      afleveradres, opmerking: document.getElementById('e-opmerking').value,
      website: honeypot,
    };
    taken.push(fetch('api_drukwerk.php?action=aanvraag_extern_create', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    }));
  }

  if (voorlichtingGewenst) {
    const body = {
      instellingNaam, email,
      adres: document.getElementById('e-vl-adres').value,
      contactpersoon: document.getElementById('e-contactpersoon').value,
      telefoon: document.getElementById('e-telefoon').value,
      gewensteDatum: document.getElementById('e-vl-datum').value,
      gewensteTijd: document.getElementById('e-vl-tijd').value,
      aantalDeelnemers: document.getElementById('e-vl-aantal').value,
      toelichting: document.getElementById('e-vl-toelichting').value,
      website: honeypot,
    };
    taken.push(fetch('api_voorlichting.php?action=aanvraag_extern_create', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    }));
  }

  const resultaten = await Promise.all(taken);
  const fouten = [];
  for (const res of resultaten) {
    if (!res.ok) { const d = await res.json(); fouten.push(d.error || 'Versturen mislukt.'); }
  }
  if (fouten.length) { errEl.textContent = fouten.join(' '); return; }

  document.getElementById('formulier').style.display = 'none';
  document.getElementById('succesMelding').style.display = 'block';
}

laadLiteratuur();
</script>
</body>
</html>
