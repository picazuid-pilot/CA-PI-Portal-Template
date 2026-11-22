<?php
// Expects config.php to already be loaded (so $_SESSION is available) by the page that includes this.
$naam = $_SESSION['user_name'] ?? '';
?>
<div class="topbar">
  <div class="brand">
    📖 PI Work Portal
    <span class="district">— <?= htmlspecialchars(DISTRICT_NAME) ?></span>
  </div>
  <nav>
    <a href="index.php">Dashboard</a>
    <a href="locations.php">PI Locations</a>
    <a href="profile.php">My Profile</a>
    <?php if (heeft_rol(['admin', 'chair'])): ?><a href="member_management.php">Member Management</a><?php endif; ?>
    <?php $__itTot = (!heeft_vaste_it() && !empty($_SESSION['user_id'])) ? it_tijdelijk_actief_tot($_SESSION['user_id']) : null; if ($__itTot): ?><span style="margin-left:14px; background:#fff3; border-radius:10px; padding:2px 10px; font-size:12px;" title="Temporary IT rights">⏱ IT until <?= date('H:i', (int)($__itTot / 1000)) ?></span><?php endif; ?>
    <span style="margin-left:18px; opacity:.85; font-size:13px;"><?= htmlspecialchars($naam) ?></span>
    <a href="#" onclick="logOut(); return false;">Log out</a>
  </nav>
</div>
<script>
async function logOut() {
  await fetch('auth.php?action=logout', { method: 'POST', credentials: 'same-origin' });
  location.href = 'login.php';
}
</script>
