<?php
require_once 'includes/auth.php';
require_once 'includes/lib_core.php';
requireAuth();

$me       = currentUser();
$myName   = $me['full_name'] ?: $me['username'];
$myAvatar = $me['picture'] ?? '';

$items = [
    ['icon' => '✅', 'title' => 'Трекер привычек',   'sub' => 'Отмечай дела каждый день',      'href' => '/habits.php', 'ready' => true],
    ['icon' => '💰', 'title' => 'Финансы',            'sub' => 'Доходы и расходы',               'href' => '/finance.php', 'ready' => true],
    ['icon' => '📿', 'title' => 'Азкары',             'sub' => 'Утренние и вечерние поминания',  'href' => '#',           'ready' => false],
    ['icon' => '📖', 'title' => 'Куръан',             'sub' => 'Чтение и прогресс',               'href' => '#',           'ready' => false],
    ['icon' => '🧮', 'title' => 'Закят, калькулятор', 'sub' => 'Расчёт закята',                  'href' => '#',           'ready' => false],
];
// Библиотека видна только тем, кто есть в LIB_ALLOWED_EMAILS (includes/lib_config.php)
if (libAllowed()) {
    array_splice($items, 2, 0, [['icon' => '📚', 'title' => 'Библиотека', 'sub' => 'Книги, чтение, заметки', 'href' => '/library/', 'ready' => true]]);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#f4f7f6">
<title>Ikhlas</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
:root{--bg:#f4f7f6;--surface:#ffffff;--surface2:#eef2f1;--border:#e0e6e4;--accent:#0f9d76;--green:#0f9d76;
--red:#e05252;--yellow:#d99a0b;--purple:#7a5cc4;--text:#17211e;--muted:#6b7a76;
--shadow:0 1px 2px rgba(16,32,28,.05),0 6px 18px rgba(16,32,28,.05)}
body{background:var(--bg);color:var(--text);font-size:15px;min-height:100vh;
  font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased}
</style>
<?php require_once __DIR__ . '/includes/theme.php'; ?>
</head>
<body>

<style>
.site-head{position:sticky;top:0;z-index:50;background:var(--surface);border-bottom:1px solid var(--border)}
.site-head-in{max-width:1180px;margin:0 auto;padding:12px 22px;display:flex;align-items:center;gap:12px}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;flex-shrink:0}
.brand-mark{width:34px;height:34px;border-radius:10px;background:var(--accent);color:#fff;display:flex;
  align-items:center;justify-content:center;font-size:17px;font-weight:800;flex-shrink:0}
.brand-name{font-size:17px;font-weight:700;letter-spacing:-.3px;line-height:1.1}
.brand-sub{font-size:11px;color:var(--muted);margin-top:1px}
.ht-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.ht-who{font-size:13px;color:var(--muted)}
.ht-who b{color:var(--text);font-weight:600}
.lnk{background:none;border:1px solid var(--border);color:var(--muted);border-radius:10px;padding:8px 13px;
  font-size:13px;cursor:pointer;text-decoration:none;font-family:inherit;transition:.15s;white-space:nowrap}
.lnk:hover{color:var(--text);border-color:var(--muted);background:var(--surface2)}
.me-ava{width:30px;height:30px;border-radius:50%;vertical-align:middle;flex-shrink:0}

.ht-wrap{max-width:1180px;margin:0 auto;padding:28px 22px 56px}
.ht-title{font-size:22px;font-weight:800;letter-spacing:-.3px;margin-bottom:4px}
.ht-lead{font-size:14px;color:var(--muted);margin-bottom:22px}

.menu-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px}
.menu-card{position:relative;display:flex;align-items:center;gap:14px;background:var(--surface);
  border:1px solid var(--border);border-radius:16px;padding:18px;box-shadow:var(--shadow);
  text-decoration:none;color:inherit;transition:.15s}
a.menu-card:hover{border-color:var(--accent);transform:translateY(-2px)}
.menu-card.soon{opacity:.6;cursor:default}
.menu-ico{width:46px;height:46px;border-radius:13px;background:var(--surface2);display:flex;
  align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.menu-t{font-size:15px;font-weight:700;margin-bottom:2px}
.menu-s{font-size:12px;color:var(--muted)}
.menu-badge{position:absolute;top:12px;right:12px;font-size:10px;font-weight:700;color:var(--muted);
  background:var(--surface2);border:1px solid var(--border);border-radius:20px;padding:3px 9px}
</style>

<header class="site-head">
  <div class="site-head-in">
    <a class="brand" href="/">
      <span class="brand-mark">I</span>
      <span>
        <span class="brand-name">Ikhlas</span>
        <span class="brand-sub">главная страница</span>
      </span>
    </a>
    <div class="ht-right">
      <?php if ($myAvatar): ?><img src="<?= htmlspecialchars($myAvatar) ?>" alt="" class="me-ava"><?php endif; ?>
      <span class="ht-who"><b><?= htmlspecialchars($myName) ?></b></span>
      <a class="lnk" href="/logout.php">Выйти</a>
    </div>
  </div>
</header>

<div class="ht-wrap">
  <div class="ht-title">Добро пожаловать, <?= htmlspecialchars($myName) ?></div>
  <div class="ht-lead">Выберите раздел</div>

  <div class="menu-grid">
    <?php foreach ($items as $it): ?>
      <?php if ($it['ready']): ?>
        <a class="menu-card" href="<?= htmlspecialchars($it['href']) ?>">
      <?php else: ?>
        <div class="menu-card soon">
          <span class="menu-badge">Скоро</span>
      <?php endif; ?>
          <div class="menu-ico"><?= $it['icon'] ?></div>
          <div>
            <div class="menu-t"><?= htmlspecialchars($it['title']) ?></div>
            <div class="menu-s"><?= htmlspecialchars($it['sub']) ?></div>
          </div>
      <?php if ($it['ready']): ?>
        </a>
      <?php else: ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</div>

</body>
</html>
