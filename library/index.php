<?php
require_once __DIR__ . '/../includes/lib_core.php';

libRequirePage();

$me       = currentUser();
$myName   = $me['full_name'] ?: $me['username'];
$myAvatar = $me['picture'] ?? '';
$cspNonce = libCsp();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#f4f7f6">
<title>Ikhlas — библиотека</title>
<link rel="manifest" href="/library/manifest.webmanifest">
<link rel="icon" href="/library/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/library/icon-192.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
/* стартовые цвета; дальше их перекрывает выбранная тема (includes/theme.php) */
:root{--bg:#f4f7f6;--surface:#ffffff;--surface2:#eef2f1;--border:#e0e6e4;--accent:#0f9d76;--green:#0f9d76;
--red:#e05252;--yellow:#d99a0b;--purple:#7a5cc4;--text:#17211e;--muted:#6b7a76;
--shadow:0 1px 2px rgba(16,32,28,.05),0 6px 18px rgba(16,32,28,.05)}
body{background:var(--bg);color:var(--text);font-size:15px;min-height:100vh;
  font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased}
</style>
<?php require_once __DIR__ . '/../includes/theme.php'; ?>
<link rel="stylesheet" href="/library/css/library.css">
</head>
<body>

<header class="site-head">
  <div class="site-head-in">
    <a class="brand" href="/">
      <span class="brand-mark">I</span>
      <span>
        <span class="brand-name">Ikhlas</span>
        <span class="brand-sub">библиотека</span>
      </span>
    </a>
    <div class="ht-right" style="position:relative">
      <span class="sync-pill" id="sync-pill" title="Синхронизация"></span>
      <?php if ($myAvatar): ?><img src="<?= htmlspecialchars($myAvatar) ?>" alt="" class="me-ava" referrerpolicy="no-referrer"><?php endif; ?>
      <span class="ht-who"><b><?= htmlspecialchars($myName) ?></b></span>
      <a class="lnk" href="/logout.php">Выйти</a>
    </div>
  </div>
</header>

<div class="ht-wrap">
  <nav class="tabs" id="tabs" aria-label="Разделы библиотеки">
    <a class="tab" href="#home"        data-t="home">Главная</a>
    <a class="tab" href="#library"     data-t="library">Моя библиотека</a>
    <a class="tab" href="#collections" data-t="collections">Коллекции</a>
    <a class="tab" href="#notes"       data-t="notes">Заметки и цитаты</a>
    <a class="tab" href="#diary"       data-t="diary">Дневник</a>
    <a class="tab" href="#stats"       data-t="stats">Статистика</a>
    <a class="tab" href="#settings"    data-t="settings">Настройки</a>
  </nav>

  <main id="view"><div class="empty sm">Загрузка…</div></main>
</div>

<input type="file" id="file-input" multiple hidden
       accept=".epub,.fb2,.fbz,.zip,.mobi,.azw,.azw3,.prc,.pdf,.djvu,.djv,.txt,.docx,.rtf">
<div class="drop-ov" id="drop-ov"><div>📚 Отпустите, чтобы добавить книги</div></div>
<div class="uq" id="uq" hidden></div>
<div class="drawer-ov" id="drawer-ov"><aside class="drawer" id="drawer" role="dialog" aria-modal="true"></aside></div>

<script type="module" src="/library/js/catalog.js"></script>
</body>
</html>
