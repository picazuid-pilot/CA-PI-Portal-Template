<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require __DIR__ . '/committee_roles_defaults.php';

$isAdmin = heeft_rol(['admin']);
$aangepast = read_json(DATA_DIR . '/committee_roles_custom.json', null);
$rollen = $aangepast ?: standard_committee_roles();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Committee Service Roles — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link rel="stylesheet" href="style.css.php" />
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="container">

  <div class="toolbar">
    <strong>Committee Service Roles</strong>
    <?php if ($isAdmin): ?>
    <button class="secondary" style="color:#c9432f; border-color:#c9432f;" onclick="resetToDefaults()">↺ Reset to defaults</button>
    <?php endif; ?>
  </div>

  <div class="card">
    <p style="color:var(--grey); font-size:13px; margin:0;">
      Based on the official <em>Public Information WSCC Handbook</em> from Cocaine Anonymous World Services
      (<a href="https://pi.ca.org/wp-content/uploads/2025/12/2025-Revised-PI-Handbook.pdf" target="_blank" rel="noopener">2025-Revised-PI-Handbook.pdf</a>),
      with the sobriety/experience guidelines as suggested there. This whole portal is built to support the committee
      as effectively as possible in exactly these roles and duties.
    </p>
  </div>

  <?php foreach ($rollen as $r): ?>
  <div class="card" style="margin-top:14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div style="display:flex; align-items:center; gap:10px;">
        <span style="width:12px; height:12px; border-radius:3px; background:<?= htmlspecialchars(haal_kleuren_op()[$r['sleutel']] ?? '#9aa3a0') ?>; display:inline-block;"></span>
        <strong style="font-size:16px;"><?= htmlspecialchars($r['naam']) ?></strong>
      </div>
      <?php if ($isAdmin): ?><button class="secondary" onclick='openEditForm(<?= json_encode($r) ?>)'>✎ Edit</button><?php endif; ?>
    </div>
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:10px 0; font-size:12.5px; color:var(--grey);">
      <div><strong style="color:var(--ink);">Sobriety:</strong> <?= htmlspecialchars($r['sobriety']) ?></div>
      <div><strong style="color:var(--ink);">Prior experience:</strong> <?= htmlspecialchars($r['ervaring']) ?></div>
      <div><strong style="color:var(--ink);">Term:</strong> <?= htmlspecialchars($r['termijn']) ?></div>
    </div>
    <ul style="margin:8px 0 0; padding-left:20px; font-size:13.5px;">
      <?php foreach ($r['taken'] as $taak): ?>
        <li style="margin-bottom:4px;"><?= $taak /* deliberately contains safe, fixed HTML already (no user input from regular members) */ ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endforeach; ?>

</div>

<?php if ($isAdmin): ?>
<!-- ===== Modal: edit role (IT only) ===== -->
<div id="modal-role-edit" class="modal-backdrop" style="display:none;" onclick="if(event.target===this) document.getElementById('modal-role-edit').style.display='none'">
  <div class="modal" style="max-width:640px;">
    <button class="modal-close" onclick="document.getElementById('modal-role-edit').style.display='none'">✕</button>
    <h2 id="re-titel">Edit role</h2>
    <label>Name (as shown to members)</label><input id="re-naam" />
    <div class="form-grid">
      <div><label>Sobriety</label><input id="re-sobriety" /></div>
      <div><label>Prior experience</label><input id="re-ervaring" /></div>
      <div><label>Term</label><input id="re-termijn" /></div>
    </div>
    <label>Duties (one per line)</label>
    <textarea id="re-taken" rows="10"></textarea>
    <p style="font-size:11px; color:var(--grey); margin-top:4px;">Simple HTML markup (like &amp;amp; or &lt;em&gt;) is allowed, but be careful — this is shown unfiltered.</p>
    <div style="margin-top:14px; text-align:right;">
      <button class="primary" onclick="roleSaveEdit()">Save</button>
    </div>
  </div>
</div>

<script>
let reCurrentKey = null;

function openEditForm(role) {
  reCurrentKey = role.sleutel;
  document.getElementById('re-titel').textContent = 'Edit — ' + role.naam;
  document.getElementById('re-naam').value = role.naam;
  document.getElementById('re-sobriety').value = role.sobriety;
  document.getElementById('re-ervaring').value = role.ervaring;
  document.getElementById('re-termijn').value = role.termijn;
  document.getElementById('re-taken').value = role.taken.join('\n');
  document.getElementById('modal-role-edit').style.display = 'flex';
}

async function roleSaveEdit() {
  const body = {
    sleutel: reCurrentKey,
    naam: document.getElementById('re-naam').value,
    sobriety: document.getElementById('re-sobriety').value,
    ervaring: document.getElementById('re-ervaring').value,
    termijn: document.getElementById('re-termijn').value,
    taken: document.getElementById('re-taken').value.split('\n').map(r => r.trim()).filter(Boolean),
  };
  if (!body.naam.trim() || !body.taken.length) { alert('Name and at least 1 duty are required.'); return; }
  const res = await fetch('api_committee_roles.php?action=role_update', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  if (!res.ok) { const d = await res.json(); alert(d.error || 'Save failed.'); return; }
  location.reload();
}

async function resetToDefaults() {
  if (!confirm('Reset all Committee Service Roles back to the default (Handbook) content? Any custom edits will be lost.')) return;
  await fetch('api_committee_roles.php?action=reset', { method: 'POST', credentials: 'same-origin' });
  location.reload();
}
</script>
<?php endif; ?>

</body>
</html>

