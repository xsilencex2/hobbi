<?php
require_once 'includes/auth.php';
require_once 'includes/google_config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#f4f7f6">
<title>Вход — Ikhlas</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#f4f7f6;--surface:#ffffff;--surface2:#eef2f1;--border:#e0e6e4;--accent:#0f9d76;
      --red:#e05252;--text:#17211e;--muted:#6b7a76}
body{background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;
  padding:24px;font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased}
.wrap{width:400px;max-width:100%}
.brand{display:flex;align-items:center;justify-content:center;gap:11px;margin-bottom:8px}
.brand-mark{width:46px;height:46px;border-radius:14px;background:var(--accent);color:#fff;
  display:flex;align-items:center;justify-content:center;font-size:23px;font-weight:800}
.brand-name{font-size:30px;font-weight:800;letter-spacing:-1px}
.logo-sub{text-align:center;color:var(--muted);font-size:15px;margin-bottom:30px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:34px 32px;text-align:center;
  box-shadow:0 1px 2px rgba(16,32,28,.05),0 10px 34px rgba(16,32,28,.07)}
.card-title{font-size:19px;font-weight:700;margin-bottom:6px;letter-spacing:-.3px}
.card-sub{font-size:14px;color:var(--muted);margin-bottom:22px;line-height:1.5}
.err{background:rgba(224,82,82,.08);border:1px solid rgba(224,82,82,.3);border-radius:10px;padding:11px 14px;
  color:var(--red);font-size:13px;margin-bottom:16px;display:none;text-align:left}
.err.on{display:block}
.gsi{display:flex;justify-content:center;min-height:44px}
.hint{font-size:13px;color:var(--muted);margin-top:20px;line-height:1.55}
.ver{text-align:center;color:var(--muted);font-size:12px;margin-top:24px}
.dbg{margin-top:18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;
  padding:14px 16px;font-size:13px;line-height:1.7;word-break:break-all}
.dbg b{display:block;margin-bottom:6px}
.dbg code{background:var(--surface2);border-radius:6px;padding:2px 6px;font-size:12.5px}
.dbg-note{color:var(--muted);font-size:12px;line-height:1.5;margin-top:8px}
.spin{font-size:13px;color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <span class="brand-mark">I</span>
    <span class="brand-name">Ikhlas</span>
  </div>
  <div class="logo-sub">Трекер привычек</div>
  <div class="card">
    <div class="card-title">Добро пожаловать</div>
    <div class="card-sub">Отмечайте привычки каждый день<br>и следите за своим прогрессом.</div>
    <div class="err" id="err"></div>
    <div class="gsi" id="g-btn"><span class="spin">Загрузка…</span></div>
    <div class="hint">Вход через Google — логин и пароль придумывать не нужно.</div>
  </div>
  <?php if (isset($_GET['debug'])): ?>
  <?php /* /login.php?debug=1 — показывает, что именно надо прописать в Google Console.
           Ошибка origin_mismatch = значение «Origin» ниже отсутствует в списке
           Authorized JavaScript origins у клиента с этим Client ID. */ ?>
  <div class="dbg">
    <b>Проверка настроек</b>
    <div>Origin: <code id="dbg-origin">—</code></div>
    <div>Client ID: <code><?= htmlspecialchars(GOOGLE_CLIENT_ID) ?></code></div>
    <div class="dbg-note">Оба значения должны совпадать с тем, что в Google Cloud Console:
      клиент с этим Client ID → Authorized JavaScript origins → должен содержать этот Origin.</div>
  </div>
  <?php endif; ?>
  <div class="ver">Ikhlas &copy; 2026</div>
</div>

<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
function handleCredentialResponse(resp){
  const btn = document.getElementById('g-btn');
  const err = document.getElementById('err');
  err.classList.remove('on');
  btn.innerHTML = '<span class="spin">Входим…</span>';
  fetch('/google_auth.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({credential: resp.credential})
  })
  .then(r => r.json())
  .then(j => {
    if (j.ok) { window.location.href = j.redirect || '/'; }
    else { err.textContent = '⚠ ' + (j.error || 'Ошибка входа'); err.classList.add('on'); renderButton(); }
  })
  .catch(() => { err.textContent = '⚠ Ошибка сети'; err.classList.add('on'); renderButton(); });
}

function renderButton(){
  const host = document.getElementById('g-btn');
  host.innerHTML = '';
  google.accounts.id.renderButton(host, {theme:'outline', size:'large', text:'continue_with', shape:'pill', width:300});
}

var dbgEl = document.getElementById('dbg-origin');
if (dbgEl) dbgEl.textContent = window.location.origin;

window.onload = function(){
  google.accounts.id.initialize({
    client_id: '<?= htmlspecialchars(GOOGLE_CLIENT_ID) ?>',
    callback: handleCredentialResponse
  });
  renderButton();
};
</script>
</body>
</html>
