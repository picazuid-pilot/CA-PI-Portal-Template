<?php
// Openbare pagina (geen login) die je bereikt via de geheime link in de e-mail van IT.
// Tonen gebeurt met GET (veilig voor mail-scanners die links openen); het besluit zelf
// is een POST met de geheime token. De link werkt één keer en is 24 uur geldig.
require __DIR__ . '/config.php';
$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="robots" content="noindex" />
<title>Tijdelijke IT-rechten — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root { --primary: <?= COLOR_PRIMARY ?>; --primary-dark: color-mix(in srgb, var(--primary) 80%, black); }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--primary); font-family: 'Open Sans', sans-serif; padding: 16px; }
  .card { width: 420px; max-width: 100%; background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 10px 40px rgba(0,0,0,.2); }
  h1 { font-size: 19px; margin: 0 0 14px; color: var(--primary-dark); text-align: center; }
  .wie { background: #f3f6f5; border-radius: 8px; padding: 12px 14px; font-size: 14px; margin-bottom: 14px; }
  .keuzes { display: grid; grid-template-columns: repeat(5, 1fr); gap: 6px; margin: 8px 0 14px; }
  .keuzes label { border: 1px solid #d5dcd9; border-radius: 7px; padding: 9px 0; text-align: center; font-size: 13px; cursor: pointer; }
  .keuzes input { display: none; }
  .keuzes input:checked + span { font-weight: 700; }
  .keuzes label:has(input:checked) { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 12%, white); }
  button { border: none; border-radius: 7px; padding: 11px; font-size: 13.5px; font-weight: 700; cursor: pointer; font-family: inherit; width: 100%; }
  .ja { background: var(--primary); color: #fff; }
  .nee { background: #fff; color: #c9432f; border: 1px solid #c9432f; margin-top: 8px; }
  .fout { color: #c9432f; text-align: center; }
  .ok { color: #2f8f4e; text-align: center; font-size: 14px; }
  .klein { font-size: 12px; color: #6b7672; text-align: center; margin-top: 12px; }
</style>
</head>
<body>
  <div class="card">
    <h1>Tijdelijke IT-rechten</h1>
    <div id="inhoud">Laden…</div>
  </div>
<script>
const token = <?= json_encode($token) ?>;
const inhoud = document.getElementById('inhoud');
function esc(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

async function laad() {
  if (!token) { inhoud.innerHTML = '<p class="fout">Geen geldige link.</p>'; return; }
  const res = await fetch('api_it_tijdelijk.php?action=token_info&token=' + encodeURIComponent(token), { cache: 'no-store' });
  const d = await res.json();
  if (!res.ok) { inhoud.innerHTML = '<p class="fout">' + esc(d.error || 'Ongeldige link.') + '</p>'; return; }
  inhoud.innerHTML = `
    <div class="wie"><strong>${esc(d.naam)}</strong> vraagt tijdelijke IT-rechten aan.${d.reden ? '<br>Reden: ' + esc(d.reden) : ''}</div>
    <div>Hoelang mag het gelden?</div>
    <div class="keuzes">${d.keuzes.map((m, i) => `<label><input type="radio" name="min" value="${m}" ${i === 1 ? 'checked' : ''}><span>${m >= 60 ? (m / 60) + ' uur' : m + ' min'}</span></label>`).join('')}</div>
    <button class="ja" onclick="besluit('goedkeuren')">✓ Goedkeuren</button>
    <button class="nee" onclick="besluit('afwijzen')">✕ Afwijzen</button>
    <p class="klein">Tijdelijke IT kan geen IT-rollen toekennen en geen IT-accounts of wachtwoorden wijzigen. De rechten stoppen vanzelf.</p>`;
}

async function besluit(b) {
  const minuten = Number((document.querySelector('input[name=min]:checked') || {}).value || 0);
  const res = await fetch('api_it_tijdelijk.php?action=token_besluit', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ token, besluit: b, minuten }) });
  const d = await res.json();
  if (!res.ok) { inhoud.innerHTML = '<p class="fout">' + esc(d.error || 'Mislukt.') + '</p>'; return; }
  inhoud.innerHTML = d.status === 'goedgekeurd'
    ? `<p class="ok">✓ Goedgekeurd voor ${d.minuten} minuten. De aanvrager heeft bericht gekregen.</p>`
    : '<p class="ok">Aanvraag afgewezen. De aanvrager heeft bericht gekregen.</p>';
}
laad();
</script>
</body>
</html>
