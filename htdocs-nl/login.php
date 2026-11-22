<?php
require __DIR__ . '/config.php';
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Inloggen — <?= htmlspecialchars(DISTRICT_NAME) ?> PI Werkportaal</title>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --primary: <?= COLOR_PRIMARY ?>;
    --primary-dark: color-mix(in srgb, var(--primary) 80%, black);
    --primary-light: color-mix(in srgb, var(--primary) 12%, white);
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    background: var(--primary); font-family: 'Open Sans', sans-serif;
  }
  .card { width: 380px; background: #ffffff; border-radius: 14px; padding: 32px; box-shadow: 0 10px 40px rgba(0,0,0,.2); }
  .icon { text-align: center; font-size: 30px; margin-bottom: 6px; }
  h1 { font-size: 21px; margin: 0 0 4px; color: var(--primary-dark); text-align: center; font-weight: 700; }
  p.sub { text-align: center; color: #6b7573; font-size: 12.5px; margin: 0 0 22px; }
  .tabs { display: flex; background: var(--primary-light); border-radius: 8px; padding: 3px; margin-bottom: 20px; }
  .tab { flex: 1; text-align: center; padding: 9px; border-radius: 6px; cursor: pointer; font-size: 13px; color: var(--primary-dark); }
  .tab.active { background: var(--primary); color: #ffffff; font-weight: 600; }
  form { display: none; flex-direction: column; gap: 11px; }
  form.active { display: flex; }
  input {
    background: #fafafa; border: 1px solid #e2e6e4; color: #1c2321;
    border-radius: 7px; padding: 11px 12px; font-size: 13.5px; font-family: inherit; outline: none;
  }
  input:focus { border-color: var(--primary); }
  button {
    background: var(--primary); color: #ffffff; border: none; border-radius: 7px;
    padding: 11px; font-size: 13.5px; font-weight: 700; cursor: pointer; font-family: inherit; margin-top: 4px;
  }
  button:hover { background: var(--primary-dark); }
  button:disabled { opacity: .5; cursor: default; }
  .error { color: #c9432f; font-size: 12px; min-height: 16px; }
  .footer-note { text-align: center; color: #9aa3a0; font-size: 11px; margin-top: 18px; }
</style>
</head>
<body>
  <div class="card">
    <div class="icon">📋</div>
    <h1>PI Werkportaal</h1>
    <p class="sub"><?= htmlspecialchars(DISTRICT_NAME) ?> — alleen voor PI-teamleden</p>

    <div class="tabs">
      <div class="tab active" data-tab="login">Inloggen</div>
      <div class="tab" data-tab="register">Account aanmaken</div>
    </div>

    <form id="loginForm" class="active">
      <input type="text" id="loginEmail" placeholder="E-mailadres<?= (defined('DEMO_ACCOUNT_ENABLED') && DEMO_ACCOUNT_ENABLED) ? " (of 'dummy' voor het demo-account)" : '' ?>" required autocomplete="username" />
      <input type="password" id="loginPassword" placeholder="Wachtwoord" required autocomplete="current-password" />
      <div class="error" id="loginError"></div>
      <button type="submit">Inloggen</button>
      <a href="#" onclick="toonVergeten(); return false;" style="text-align:center; font-size:12px; color:var(--primary-dark); margin-top:2px;">Wachtwoord vergeten?</a>
    </form>

    <form id="vergetenForm">
      <p style="font-size:12.5px; color:#6b7573; margin:0 0 4px;">Vul je e-mailadres in — als dat bekend is, sturen we een link om een nieuw wachtwoord in te stellen.</p>
      <input type="email" id="vergetenEmail" placeholder="E-mailadres" required autocomplete="username" />
      <div class="error" id="vergetenError"></div>
      <div id="vergetenSucces" style="font-size:12.5px; color:#2f8f4e; display:none;"></div>
      <button type="submit">Verstuur reset-link</button>
      <a href="#" onclick="toonLogin(); return false;" style="text-align:center; font-size:12px; color:var(--primary-dark); margin-top:2px;">Terug naar inloggen</a>
    </form>

    <form id="registerForm">
      <input type="text" id="regName" placeholder="Jouw naam" required />
      <input type="email" id="regEmail" placeholder="E-mailadres" required autocomplete="username" />
      <input type="password" id="regPassword" placeholder="Wachtwoord (min. 8 tekens)" required autocomplete="new-password" />
      <input type="text" id="regInvite" placeholder="Uitnodigingscode" required />
      <div class="error" id="registerError"></div>
      <button type="submit">Account aanmaken</button>
    </form>

    <p class="footer-note">Public Information — Cocaine Anonymous</p>
  </div>

<script>
  const tabs = document.querySelectorAll('.tab');
  const forms = { login: document.getElementById('loginForm'), register: document.getElementById('registerForm') };
  const alleFormulieren = [...Object.values(forms), document.getElementById('vergetenForm')];
  tabs.forEach(tab => tab.addEventListener('click', () => {
    tabs.forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    alleFormulieren.forEach(f => f.classList.remove('active'));
    forms[tab.dataset.tab].classList.add('active');
  }));

  function toonVergeten() {
    alleFormulieren.forEach(f => f.classList.remove('active'));
    document.getElementById('vergetenForm').classList.add('active');
  }
  function toonLogin() {
    alleFormulieren.forEach(f => f.classList.remove('active'));
    tabs.forEach(t => t.classList.remove('active'));
    document.querySelector('.tab[data-tab="login"]').classList.add('active');
    forms.login.classList.add('active');
  }

  document.getElementById('vergetenForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('vergetenError');
    const succesEl = document.getElementById('vergetenSucces');
    errEl.textContent = '';
    succesEl.style.display = 'none';
    const res = await fetch('auth.php?action=wachtwoord_vergeten', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: document.getElementById('vergetenEmail').value }),
    });
    const data = await res.json();
    succesEl.textContent = data.melding || 'Als dit e-mailadres bekend is, ontvang je een link.';
    succesEl.style.display = 'block';
  });

  function redirectAfterLogin() {
    const params = new URLSearchParams(location.search);
    location.href = params.get('next') || 'index.php';
  }

  document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('loginError');
    errEl.textContent = '';
    const res = await fetch('auth.php?action=login', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email: document.getElementById('loginEmail').value,
        password: document.getElementById('loginPassword').value,
      }),
    });
    const data = await res.json();
    if (!res.ok) { errEl.textContent = data.error || 'Inloggen mislukt.'; return; }
    redirectAfterLogin();
  });

  document.getElementById('registerForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('registerError');
    errEl.textContent = '';
    const res = await fetch('auth.php?action=register', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: document.getElementById('regName').value,
        email: document.getElementById('regEmail').value,
        password: document.getElementById('regPassword').value,
        invite: document.getElementById('regInvite').value,
      }),
    });
    const data = await res.json();
    if (!res.ok) { errEl.textContent = data.error || 'Registreren mislukt.'; return; }
    redirectAfterLogin();
  });
</script>
</body>
</html>
