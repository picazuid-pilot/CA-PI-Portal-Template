<?php
// Verwacht dat config.php al geladen is (dus $_SESSION beschikbaar) door de pagina die dit include't.
$naam = $_SESSION['user_name'] ?? '';
?>
<div class="topbar">
  <div class="brand">
    📖 PI Werkportaal
    <span class="district">— <?= htmlspecialchars(DISTRICT_NAME) ?></span>
  </div>
  <nav>
    <a href="index.php">Dashboard</a>
    <a href="locaties.php">PI Locaties</a>
    <a href="profiel.php">Mijn profiel</a>
    <?php if (heeft_rol(['admin', 'voorzitter'])): ?><a href="ledenbeheer.php">Ledenbeheer</a><?php endif; ?>
    <?php $__itTot = (!heeft_vaste_it() && !empty($_SESSION['user_id'])) ? it_tijdelijk_actief_tot($_SESSION['user_id']) : null; if ($__itTot): ?><span style="margin-left:14px; background:#fff3; border-radius:10px; padding:2px 10px; font-size:12px;" title="Tijdelijke IT-rechten">⏱ IT tot <?= date('H:i', (int)($__itTot / 1000)) ?></span><?php endif; ?>
    <span style="margin-left:18px; opacity:.85; font-size:13px;"><?= htmlspecialchars($naam) ?></span>
    <a href="#" onclick="uitloggen(); return false;">Uitloggen</a>
  </nav>
</div>
<script>
async function uitloggen() {
  await fetch('auth.php?action=logout', { method: 'POST', credentials: 'same-origin' });
  location.href = 'login.php';
}
</script>
