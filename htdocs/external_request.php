<?php
require __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Order print materials — <?= htmlspecialchars(DISTRICT_NAME) ?> Public Information</title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
  <div class="topbar">
    <div class="brand">📋 <?= htmlspecialchars(DISTRICT_NAME) ?> — Public Information</div>
  </div>

  <div class="container" style="max-width:640px;">
    <div class="card">
      <h2 style="margin-top:0; color:var(--primary);">Order print materials / literature</h2>
      <p style="color:var(--grey); font-size:13.5px;">
        For institutions and professionals who'd like to restock their own supply of leaflets, flyers, or posters from
        <?= htmlspecialchars(DISTRICT_NAME) ?>. Fill in what you need — we'll review it and send it out.
      </p>

      <div id="formulier">
        <label>Institution name *</label><input id="e-instelling" required />
        <label>Contact person</label><input id="e-contactpersoon" />
        <div class="form-grid">
          <div><label>Email address *</label><input id="e-email" type="email" required /></div>
          <div><label>Phone number</label><input id="e-telefoon" /></div>
        </div>
        <label>Delivery address <span style="color:var(--grey); font-weight:400;">(required for an order)</span></label><input id="e-adres" placeholder="Street + number, postal code, city" />

        <h3 style="color:var(--primary); margin:22px 0 4px;">Literature / leaflets</h3>
        <p style="color:var(--grey); font-size:12.5px; margin:0 0 10px;">Enter the desired quantity per title (0 = not needed).</p>
        <div id="literatuur-lijst">Loading…</div>

        <h3 style="color:var(--primary); margin:22px 0 4px;">Flyers, posters &amp; other</h3>
        <div class="form-grid">
          <div>
            <label>A5 Flyers (in batches of 12)</label>
            <input id="e-flyers" type="number" min="0" step="12" value="0" />
          </div>
          <div>
            <label>A3 Posters (max. 2 per request)</label>
            <input id="e-posters" type="number" min="0" max="2" value="0" />
          </div>
          <div>
            <label>Business cards (in batches of 12)</label>
            <input id="e-visitekaartjes" type="number" min="0" step="12" value="0" />
          </div>
          <div>
            <label>Chit cards / attendance cards (in batches of 12)</label>
            <input id="e-chitcards" type="number" min="0" step="12" value="0" />
          </div>
        </div>

        <label>Note (optional)</label>
        <textarea id="e-opmerking" rows="2"></textarea>

        <h3 style="color:var(--primary); margin:22px 0 4px;">Request an outreach presentation</h3>
        <div class="checkbox-row">
          <input type="checkbox" id="e-voorlichting" onchange="document.getElementById('voorlichting-velden').style.display = this.checked ? 'block' : 'none'" />
          <label style="margin:0;">We'd also like to request an outreach presentation for our staff</label>
        </div>
        <div id="voorlichting-velden" style="display:none;">
          <label>Address where the presentation will take place (if different from above)</label>
          <input id="e-vl-adres" placeholder="Street, number, postal code, city" />
          <div class="form-grid">
            <div><label>Preferred date (an approximate date is fine)</label><input id="e-vl-datum" type="date" /></div>
            <div><label>Preferred time (optional)</label><input id="e-vl-tijd" type="time" /></div>
          </div>
          <label>Expected number of attendees</label><input id="e-vl-aantal" type="number" min="1" style="max-width:200px;" />
          <label>Additional notes (optional)</label>
          <textarea id="e-vl-toelichting" rows="2" placeholder="e.g. for new staff, or specific topics of interest"></textarea>
        </div>

        <!-- Honeypot: invisible to people, bots often fill this in by accident -->
        <div style="position:absolute; left:-9999px;" aria-hidden="true">
          <label>Website</label><input id="e-website" tabindex="-1" autocomplete="off" />
        </div>

        <div class="error" id="e-error" style="margin-top:8px;"></div>
        <button class="primary" style="margin-top:10px; width:100%;" onclick="versturen()">Submit order</button>
      </div>

      <div id="succesMelding" style="display:none; text-align:center; padding:20px 0;">
        <p style="font-size:32px; margin:0;">✓</p>
        <p style="color:var(--primary); font-weight:600;">Thank you! Your order has been submitted.</p>
        <p style="color:var(--grey); font-size:13px;">We'll process it as soon as possible and send it out.</p>
      </div>
    </div>
  </div>

<script>
async function laadLiteratuur() {
  const res = await fetch('api_print.php?action=pakket_list');
  const data = await res.json();
  const pakket = data.pakketten.find(p => p.type === 'literatuur_pakket');
  const el = document.getElementById('literatuur-lijst');
  if (!pakket) { el.innerHTML = '<p style="color:var(--grey);">No literature list available — please describe what you need in the note field below.</p>'; return; }
  el.innerHTML = pakket.items.map(it => `
    <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 0; border-bottom:1px solid var(--border);">
      <span style="font-size:13.5px;">${escapeHtml(it.naam)}</span>
      <input type="number" min="0" value="0" data-lit-naam="${escapeHtml(it.naam)}" style="width:70px;" />
    </div>`).join('');
}
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

async function versturen() {
  const errEl = document.getElementById('e-error');
  errEl.textContent = '';

  const instellingNaam = document.getElementById('e-instelling').value.trim();
  const email = document.getElementById('e-email').value.trim();
  const afleveradres = document.getElementById('e-adres').value.trim();
  if (!instellingNaam || !email) { errEl.textContent = 'Enter at least a name and email address.'; return; }

  const items = [];
  document.querySelectorAll('#literatuur-lijst input[data-lit-naam]').forEach(inp => {
    const aantal = parseInt(inp.value, 10);
    if (aantal > 0) items.push({ naam: inp.dataset.litNaam, aantal });
  });

  const flyers = parseInt(document.getElementById('e-flyers').value, 10) || 0;
  if (flyers > 0) {
    if (flyers % 12 !== 0) { errEl.textContent = 'A5 Flyers can only be ordered in batches of 12 (12, 24, 36, ...).'; return; }
    items.push({ naam: 'A5 Flyers', aantal: flyers });
  }
  const posters = parseInt(document.getElementById('e-posters').value, 10) || 0;
  if (posters > 0) {
    if (posters > 2) { errEl.textContent = 'Maximum of 2 A3 Posters per request.'; return; }
    items.push({ naam: 'A3 Posters', aantal: posters });
  }
  const visitekaartjes = parseInt(document.getElementById('e-visitekaartjes').value, 10) || 0;
  if (visitekaartjes > 0) {
    if (visitekaartjes % 12 !== 0) { errEl.textContent = 'Business cards can only be ordered in batches of 12.'; return; }
    items.push({ naam: 'Business cards', aantal: visitekaartjes });
  }
  const chitcards = parseInt(document.getElementById('e-chitcards').value, 10) || 0;
  if (chitcards > 0) {
    if (chitcards % 12 !== 0) { errEl.textContent = 'Chit cards can only be ordered in batches of 12.'; return; }
    items.push({ naam: 'Chit cards', aantal: chitcards });
  }

  const voorlichtingGewenst = document.getElementById('e-voorlichting').checked;
  if (!items.length && !voorlichtingGewenst) {
    errEl.textContent = 'Choose at least 1 item with a quantity greater than 0, or request an outreach presentation.';
    return;
  }
  if (items.length && !afleveradres) {
    errEl.textContent = 'Enter a delivery address for your order.';
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
    taken.push(fetch('api_print.php?action=aanvraag_extern_create', {
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
    taken.push(fetch('api_outreach.php?action=aanvraag_extern_create', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    }));
  }

  const resultaten = await Promise.all(taken);
  const fouten = [];
  for (const res of resultaten) {
    if (!res.ok) { const d = await res.json(); fouten.push(d.error || 'Submission failed.'); }
  }
  if (fouten.length) { errEl.textContent = fouten.join(' '); return; }

  document.getElementById('formulier').style.display = 'none';
  document.getElementById('succesMelding').style.display = 'block';
}

laadLiteratuur();
</script>
</body>
</html>
