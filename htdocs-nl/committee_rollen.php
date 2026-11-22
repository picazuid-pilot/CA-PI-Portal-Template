<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$isAdmin = heeft_rol(['admin', 'voorzitter']); // IT en voorzitter mogen de comité-rollen aanpassen

function commissie_rollen_custom_path() {
    return DATA_DIR . '/commissie_rollen_aangepast.json';
}

require __DIR__ . '/commissie_rollen_standaard.php';

$aangepast = read_json(commissie_rollen_custom_path(), null);
$rollen = $aangepast ?: standaard_commissie_rollen();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Committee Service Rollen — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <strong>Committee Service Rollen</strong>
    <?php if ($isAdmin): ?>
    <button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="resetNaarStandaard()">↺ Reset naar standaardwaarden</button>
    <?php endif; ?>
  </div>

  <div class="card">
    <p style="color:var(--grey); font-size:13px; margin:0;">
      Gebaseerd op het officiële <em>Public Information WSCC Handbook</em> van Cocaine Anonymous World Services
      (<a href="https://pi.ca.org/wp-content/uploads/2025/12/2025-Revised-PI-Handbook.pdf" target="_blank" rel="noopener">2025-Revised-PI-Handbook.pdf</a>),
      met de sobriety-/ervaringsrichtlijnen zoals daar gesuggereerd. Dit hele portaal is erop gericht om de commissie zo goed
      mogelijk te ondersteunen bij precies deze rollen en taken.
    </p>
  </div>

  <?php foreach ($rollen as $r): ?>
  <div class="card" style="margin-top:14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div style="display:flex; align-items:center; gap:10px;">
        <span style="width:12px; height:12px; border-radius:3px; background:<?= htmlspecialchars(haal_kleuren_op()[$r['sleutel']] ?? '#9aa3a0') ?>; display:inline-block;"></span>
        <strong style="font-size:16px;"><?= htmlspecialchars($r['naam']) ?></strong>
      </div>
      <?php if ($isAdmin): ?><button class="secondary" onclick="openBewerkForm(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">✎ Bewerken</button><?php endif; ?>
    </div>
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:10px 0; font-size:12.5px; color:var(--grey);">
      <div><strong style="color:var(--ink);">Sobriety:</strong> <?= htmlspecialchars($r['sobriety']) ?></div>
      <div><strong style="color:var(--ink);">Ervaring vooraf:</strong> <?= htmlspecialchars($r['ervaring']) ?></div>
      <div><strong style="color:var(--ink);">Termijn:</strong> <?= htmlspecialchars($r['termijn']) ?></div>
    </div>
    <ul style="margin:8px 0 0; padding-left:20px; font-size:13.5px;">
      <?php foreach ($r['taken'] as $taak): ?>
        <li style="margin-bottom:4px;"><?= $taak /* bevat bewust al veilige, vaste HTML (geen user input van gewone leden) */ ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endforeach; ?>

</div>

<?php if ($isAdmin): ?>
<!-- ===== Modal: rol bewerken (alleen IT) ===== -->
<div id="modal-rol-bewerken" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) document.getElementById('modal-rol-bewerken').style.display='none'">
  <div class="modal" style="max-width:640px;">
    <button class="modal-close" onclick="document.getElementById('modal-rol-bewerken').style.display='none'">✕</button>
    <h2 id="rb-titel">Rol bewerken</h2>
    <label>Naam (zoals getoond aan leden)</label><input id="rb-naam" />
    <div class="form-grid">
      <div><label>Sobriety</label><input id="rb-sobriety" /></div>
      <div><label>Ervaring vooraf</label><input id="rb-ervaring" /></div>
      <div><label>Termijn</label><input id="rb-termijn" /></div>
    </div>
    <label>Taken (één per regel)</label>
    <textarea id="rb-taken" rows="10"></textarea>
    <p style="font-size:11px; color:var(--grey); margin-top:4px;">Eenvoudige HTML-opmaak (zoals &amp;amp; of &lt;em&gt;) mag, maar wees voorzichtig — dit wordt ongefilterd getoond.</p>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="rolOpslaan()">Opslaan</button>
    </div>
  </div>
</div>

<script>
let rbHuidigeSleutel = null;

function openBewerkForm(rol) {
  rbHuidigeSleutel = rol.sleutel;
  document.getElementById('rb-titel').textContent = 'Bewerken — ' + rol.naam;
  document.getElementById('rb-naam').value = rol.naam;
  document.getElementById('rb-sobriety').value = rol.sobriety;
  document.getElementById('rb-ervaring').value = rol.ervaring;
  document.getElementById('rb-termijn').value = rol.termijn;
  document.getElementById('rb-taken').value = rol.taken.join('\n');
  document.getElementById('modal-rol-bewerken').style.display = 'flex';
}

async function rolOpslaan() {
  const body = {
    sleutel: rbHuidigeSleutel,
    naam: document.getElementById('rb-naam').value,
    sobriety: document.getElementById('rb-sobriety').value,
    ervaring: document.getElementById('rb-ervaring').value,
    termijn: document.getElementById('rb-termijn').value,
    taken: document.getElementById('rb-taken').value.split('\n').map(r => r.trim()).filter(Boolean),
  };
  if (!body.naam.trim() || !body.taken.length) { alert('Naam en minstens 1 taak zijn verplicht.'); return; }
  const res = await fetch('api_commissie_rollen.php?action=rol_bijwerken', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Opslaan mislukt.'); return; }
  location.reload();
}

async function resetNaarStandaard() {
  if (!confirm('Alle Committee Service Rollen terugzetten naar de standaard (handboek-)inhoud? Eigen aanpassingen gaan dan verloren.')) return;
  await fetch('api_commissie_rollen.php?action=reset', { method: 'POST', credentials: 'same-origin' });
  location.reload();
}
</script>
<?php endif; ?>

</body>
</html>
