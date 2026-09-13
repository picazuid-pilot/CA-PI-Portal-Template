<?php
require __DIR__ . '/config.php';
$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Set a new password — <?= htmlspecialchars(DISTRICT_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root { --primary: <?= COLOR_PRIMARY ?>; --primary-dark: color-mix(in srgb, var(--primary) 80%, black); }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--primary); font-family: 'Open Sans', sans-serif; }
  .card { width: 380px; background: #ffffff; border-radius: 14px; padding: 32px; box-shadow: 0 10px 40px rgba(0,0,0,.2); }
  h1 { font-size: 19px; margin: 0 0 16px; color: var(--primary-dark); text-align: center; }
  form { display: flex; flex-direction: column; gap: 11px; }
  input { background: #fafafa; border: 1px solid #e2e6e4; color: #1c2321; border-radius: 7px; padding: 11px 12px; font-size: 13.5px; font-family: inherit; outline: none; }
  input:focus { border-color: var(--primary); }
  button { background: var(--primary); color: #fff; border: none; border-radius: 7px; padding: 11px; font-size: 13.5px; font-weight: 700; cursor: pointer; font-family: inherit; margin-top: 4px; }
  button:hover { background: var(--primary-dark); }
  .error { color: #c9432f; font-size: 12px; min-height: 16px; }
  .succes { color: #2f8f4e; font-size: 13.5px; text-align: center; }
  a { color: var(--primary); font-size: 12.5px; text-align: center; display: block; margin-top: 12px; }
</style>
</head>
<body>
  <div class="card">
    <h1>Set a new password</h1>

    <?php if ($token === ''): ?>
      <p class="error" style="text-align:center;">This link isn't valid. Request a new one from the login page.</p>
      <a href="login.php">← Back to login</a>
    <?php else: ?>
      <form id="resetForm">
        <input type="password" id="password1" placeholder="New password (min. 8 characters)" required autocomplete="new-password" />
        <input type="password" id="password2" placeholder="Repeat new password" required autocomplete="new-password" />
        <div class="error" id="resetError"></div>
        <button type="submit">Set password</button>
      </form>
      <div id="resetSuccess" style="display:none;">
        <p class="succes">✓ Your password has been updated.</p>
        <a href="login.php">Go to login →</a>
      </div>
    <?php endif; ?>
  </div>

<script>
const form = document.getElementById('resetForm');
if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('resetError');
    errEl.textContent = '';
    const p1 = document.getElementById('password1').value;
    const p2 = document.getElementById('password2').value;
    if (p1 !== p2) { errEl.textContent = 'Passwords do not match.'; return; }

    const res = await fetch('auth.php?action=reset_password', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: <?= json_encode($token) ?>, wachtwoord: p1 }),
    });
    const data = await res.json();
    if (!res.ok) { errEl.textContent = data.error || 'Something went wrong.'; return; }
    form.style.display = 'none';
    document.getElementById('resetSuccess').style.display = 'block';
  });
}
</script>
</body>
</html>
