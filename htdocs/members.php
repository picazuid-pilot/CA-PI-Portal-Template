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
<title>Members — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <strong>Members</strong>
  </div>

  <div class="card">
    <div id="leden-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:14px;"></div>
  </div>

</div>

<script>
function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; }

async function laadProfielen() {
  const res = await fetch('api_members.php?action=profielen_overzicht', { cache: 'no-store' });
  const data = await res.json();
  document.getElementById('leden-grid').innerHTML = data.leden.map(l => `
    <a href="profile.php?id=${encodeURIComponent(l.id)}" style="text-decoration:none; color:inherit; border:1px solid var(--border); border-radius:10px; padding:14px; text-align:center; display:block;">
      <div style="width:64px; height:64px; border-radius:50%; margin:0 auto 8px; overflow:hidden; background:var(--primary-light-95); display:flex; align-items:center; justify-content:center; color:var(--grey); font-size:10px;">
        ${l.profielfoto ? `<img src="api_members.php?action=profielfoto_stream&bestand=${encodeURIComponent(l.profielfoto)}" style="width:100%; height:100%; object-fit:cover;" />` : '👤'}
      </div>
      <strong style="font-size:13.5px; display:block;">${escapeHtml(l.naam)}</strong>
      <div style="font-size:11px; color:var(--grey); margin-top:2px;">${l.roles.map(r => escapeHtml(data.rollen[r] || r)).join(', ')}</div>
      ${l.regio ? `<div style="font-size:10.5px; color:var(--primary); margin-top:2px;">📍 ${escapeHtml(l.regio)}</div>` : ''}
    </a>`).join('') || '<p style="color:var(--grey);">No members registered yet.</p>';
}

laadProfielen();
</script>
</body>
</html>
