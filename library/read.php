<?php
require_once __DIR__ . '/../includes/lib_core.php';

libRequirePage();
// Строгая политика: скрипты только наши. В EPUB бывает JavaScript — он не выполнится.
libCsp(true);
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#fbfbf9">
<title>Читалка — Ikhlas</title>
<link rel="manifest" href="/library/manifest.webmanifest">
<link rel="icon" href="/library/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/library/icon-192.png">
<link rel="stylesheet" href="/library/vendor/pdfjs/web/pdf_viewer.css">
<link rel="stylesheet" href="/library/css/reader.css">
</head>
<body>
<div class="rd" id="rd">
  <header class="rd-top" id="rd-top">
    <a class="rb" href="/library/#library" id="rd-back" title="В библиотеку" aria-label="В библиотеку">‹</a>
    <div class="rd-title"><b id="rd-title">Загрузка…</b><span id="rd-sub"></span></div>
    <span class="rd-sync" id="rd-sync" title="Синхронизация позиции"></span>
    <button class="rb" data-panel="toc" title="Оглавление" aria-label="Оглавление">☰</button>
    <button class="rb" data-panel="search" title="Поиск" aria-label="Поиск">⌕</button>
    <button class="rb" id="rd-bm" title="Закладка" aria-label="Закладка">🔖</button>
    <button class="rb" data-panel="notes" title="Закладки и заметки" aria-label="Закладки и заметки">✎</button>
    <button class="rb" id="rd-aa" title="Настройки чтения" aria-label="Настройки чтения">Aa</button>
    <button class="rb" id="rd-more" title="Ещё" aria-label="Ещё">⋯</button>
  </header>

  <div class="rd-banner" id="rd-banner" hidden></div>
  <main class="rd-main" id="rd-main">
    <div class="rd-load" id="rd-load"><div class="spin"></div><div id="rd-load-t">Открываем книгу…</div><div class="rd-load-bar"><i id="rd-load-bar"></i></div></div>
  </main>

  <footer class="rd-foot" id="rd-foot">
    <button class="rb" id="rd-prev" aria-label="Назад">‹</button>
    <input type="range" id="rd-slider" min="0" max="1" step="0.0001" value="0" aria-label="Прогресс">
    <span class="rd-loc" id="rd-loc"></span>
    <button class="rb" id="rd-next" aria-label="Вперёд">›</button>
  </footer>

  <aside class="rd-panel" id="rd-panel" hidden>
    <div class="rd-panel-h">
      <div class="rd-ptabs">
        <button data-ptab="toc">Оглавление</button>
        <button data-ptab="search">Поиск</button>
        <button data-ptab="notes">Заметки</button>
      </div>
      <button class="rb" id="rd-panel-x" aria-label="Закрыть">✕</button>
    </div>
    <div class="rd-panel-b" id="rd-panel-b"></div>
  </aside>

  <div class="rd-pop" id="rd-pop" hidden></div>
  <div class="rd-sel" id="rd-sel" hidden></div>
  <div class="rd-modal" id="rd-modal" hidden></div>
</div>
<script type="module" src="/library/js/reader/main.js"></script>
</body>
</html>
