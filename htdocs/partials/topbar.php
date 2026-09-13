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
    <?php if (heeft_rol(['admin'])): ?><a href="member_management.php">Member Management</a><?php endif; ?>
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
