<?php
/**
 * Оформление сайта: Белый · Сепия · Серый · Чёрный.
 * Темы меняют CSS-переменные на <html>, поэтому перекрашивается вся страница:
 * шапка, карточки, таблицы, кнопки.
 *
 * По умолчанию — светлая тема и авто-переключение по времени суток.
 * Настройка запоминается в localStorage (ikhlas_theme / ikhlas_auto).
 *
 * Страница со строгой Content-Security-Policy (библиотека) задаёт $cspNonce —
 * тогда встроенный скрипт получает этот nonce и не блокируется.
 */
?>
<style>
/* ── КНОПКА ТЕМЫ ── */
.theme-btn{background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:10px;
           padding:7px 12px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
.theme-btn:hover{border-color:var(--accent)}
/* если на странице нет шапки — кнопка плавающая */
.theme-btn.theme-fab{position:fixed;right:16px;bottom:calc(16px + env(safe-area-inset-bottom,0px));z-index:9500;
           box-shadow:0 6px 20px rgba(0,0,0,.28);padding:10px 14px;border-radius:22px}
@media (max-width:768px){ .theme-btn.theme-fab{bottom:calc(70px + env(safe-area-inset-bottom,0px))} }

.theme-wrap{position:relative;display:inline-block}
.theme-pop{position:absolute;right:0;top:calc(100% + 8px);background:var(--surface);border:1px solid var(--border);
           border-radius:14px;padding:12px;z-index:9600;width:280px;box-shadow:0 16px 44px rgba(0,0,0,.35);display:none}
.theme-pop.on{display:block}
.theme-btn.theme-fab + .theme-pop{position:fixed;right:16px;top:auto;bottom:calc(64px + env(safe-area-inset-bottom,0px))}
@media (max-width:768px){
  .theme-pop{position:fixed!important;left:10px;right:10px;top:auto!important;
             bottom:calc(70px + env(safe-area-inset-bottom,0px))!important;width:auto;max-height:70vh;overflow-y:auto}
  .theme-btn.theme-fab + .theme-pop{bottom:calc(124px + env(safe-area-inset-bottom,0px))!important}
}

/* ── ПИЛЮЛИ ТЕМ ── */
.theme-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.theme-chip{flex:1 1 auto;min-width:66px;border:2px solid var(--border);border-radius:20px;padding:9px 14px;
            font-size:13px;font-weight:700;cursor:pointer;text-align:center;transition:.15s;white-space:nowrap}
.theme-chip:hover{transform:translateY(-1px)}
.theme-chip.on{border-color:var(--accent);box-shadow:0 0 0 2px rgba(15,157,118,.25)}
.tc-white{background:#ffffff;color:#17211e;border-color:#dfe5e3}
.tc-sepia{background:#f4ecd8;color:#4a3b26;border-color:#d9c9a8}
.tc-gray {background:#3b4341;color:#e9efed;border-color:#525c59}
.tc-black{background:#0a0b0b;color:#f0f2f1;border-color:#2a302e}

.auto-row{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:12px;color:var(--muted);
          border-top:1px solid var(--border);padding-top:10px}
.sw{position:relative;width:40px;height:22px;background:var(--border);border-radius:20px;cursor:pointer;transition:.2s;flex-shrink:0}
.sw.on{background:var(--accent2,#38d9a9)}
.sw::after{content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s}
.sw.on::after{left:20px}

/* плавная перекраска всей страницы */
body{transition:background .35s ease,color .35s ease}
</style>
<script<?= !empty($cspNonce) ? ' nonce="' . htmlspecialchars($cspNonce) . '"' : '' ?>>
(function(){
// ═══════════ ТЕМЫ ВСЕЙ СИСТЕМЫ ═══════════
// Инлайн-стиль на <html> перебивает :root из head.php → перекрашивается ВСЁ.
var THEMES = {
  white: {name:'Белый', cls:'tc-white', meta:'#f4f7f6', v:{
    '--bg':'#f4f7f6','--surface':'#ffffff','--surface2':'#eef2f1','--border':'#e0e6e4',
    '--text':'#17211e','--muted':'#6b7a76','--accent':'#0f9d76','--accent2':'#0f9d76',
    '--green':'#0f9d76','--red':'#e05252','--yellow':'#d99a0b','--purple':'#7a5cc4'}},
  sepia: {name:'Сепия', cls:'tc-sepia', meta:'#f2e9d5', v:{
    '--bg':'#f2e9d5','--surface':'#faf3e3','--surface2':'#eadfc6','--border':'#d7c6a4',
    '--text':'#3b2f1e','--muted':'#7a6a52','--accent':'#9c6b2f','--accent2':'#4f7a3a',
    '--green':'#4f7a3a','--red':'#a8442e','--yellow':'#b58a10','--purple':'#6d5a8a'}},
  gray:  {name:'Серый', cls:'tc-gray', meta:'#2a2f2e', v:{
    '--bg':'#2a2f2e','--surface':'#333a38','--surface2':'#3d4644','--border':'#4b5553',
    '--text':'#e9efed','--muted':'#9aa8a4','--accent':'#3ecfa0','--accent2':'#3ecfa0',
    '--green':'#3ecfa0','--red':'#f07178','--yellow':'#e0af68','--purple':'#bb9af7'}},
  black: {name:'Черный', cls:'tc-black', meta:'#000000', v:{
    '--bg':'#000000','--surface':'#0e100f','--surface2':'#191d1c','--border':'#2a302e',
    '--text':'#f0f2f1','--muted':'#8a9591','--accent':'#2dd4a0','--accent2':'#2dd4a0',
    '--green':'#2dd4a0','--red':'#ff6b6b','--yellow':'#ffd43b','--purple':'#a78bfa'}}
};

// Авто по времени — как яркость на iPhone: днём светло, ночью темно
function timeSlot(h){
  if(h >= 7  && h < 16) return {t:'white', label:'День'};
  if(h >= 16 && h < 20) return {t:'sepia', label:'Вечер'};
  if(h >= 20 && h < 23) return {t:'gray',  label:'Поздний вечер'};
  return {t:'black', label:'Ночь'};                       // 23–7
}

var LS = window.localStorage;
// старые ключи читаем, чтобы у тех, кто уже пользовался, настройка не слетела
var theme    = LS.getItem('ikhlas_theme') || LS.getItem('ht_theme') || 'white';
var autoMode = (LS.getItem('ikhlas_auto') !== null ? LS.getItem('ikhlas_auto') : LS.getItem('ht_auto')) !== '0';
if(!THEMES[theme]) theme = 'white';

function applyTheme(){
  var t = theme, hint = 'Тема меняется в течение дня';
  if(autoMode){
    var s = timeSlot(new Date().getHours());
    t = s.t;
    hint = 'Сейчас: ' + s.label + ' · ' + THEMES[t].name;
  }
  var th = THEMES[t] || THEMES.white;
  var root = document.documentElement;
  for(var k in th.v) root.style.setProperty(k, th.v[k]);
  root.style.setProperty('color-scheme', (t==='white'||t==='sepia') ? 'light' : 'dark');
  root.setAttribute('data-theme', t);

  var m = document.querySelector('meta[name="theme-color"]');
  if(m) m.setAttribute('content', th.meta);

  var hEl = document.getElementById('crm-auto-hint'); if(hEl) hEl.textContent = hint;
  var sw  = document.getElementById('crm-auto-sw');   if(sw)  sw.classList.toggle('on', autoMode);
  renderChips();
}

function renderChips(){
  var g = document.getElementById('crm-theme-grid');
  if(!g) return;
  var html = '';
  for(var k in THEMES){
    var v = THEMES[k];
    var on = (!autoMode && theme === k) ? ' on' : '';
    html += '<div class="theme-chip ' + v.cls + on + '" data-theme-pick="' + k + '">' + v.name + '</div>';
  }
  g.innerHTML = html;
}

function pickTheme(k){
  theme = k; autoMode = false;                 // ручной выбор выключает авто
  LS.setItem('ikhlas_theme', k);
  LS.setItem('ikhlas_auto', '0');
  applyTheme();
}
function toggleAuto(){
  autoMode = !autoMode;
  LS.setItem('ikhlas_auto', autoMode ? '1' : '0');
  applyTheme();
}

// красим ДО отрисовки — иначе моргнёт тёмным
applyTheme();

// ── UI: кнопка + попап (вставляем сами, ничего не надо править на страницах) ──
function mount(){
  if(document.getElementById('crm-theme-pop')) return;   // уже есть

  var pop = document.createElement('div');
  pop.className = 'theme-pop';
  pop.id = 'crm-theme-pop';
  pop.innerHTML =
    '<div class="theme-grid" id="crm-theme-grid"></div>' +
    '<div class="auto-row">' +
      '<div><div style="color:var(--text);font-weight:600">Авто по времени</div>' +
      '<div style="font-size:11px" id="crm-auto-hint">Фон меняется в течение дня</div></div>' +
      '<div class="sw" id="crm-auto-sw"></div>' +
    '</div>';

  var btn = document.createElement('button');
  btn.className = 'theme-btn';
  btn.id = 'crm-theme-btn';
  btn.type = 'button';
  btn.textContent = '🎨 Фон';

  // в шапку страницы, если она есть; иначе — плавающая кнопка
  var host = document.querySelector('.header-right') || document.querySelector('.ht-right');
  if(host){
    var wrap = document.createElement('div');
    wrap.className = 'theme-wrap';
    wrap.appendChild(btn);
    wrap.appendChild(pop);
    host.insertBefore(wrap, host.firstChild);
  }else{
    btn.classList.add('theme-fab');
    document.body.appendChild(btn);
    document.body.appendChild(pop);
  }

  btn.addEventListener('click', function(e){
    e.stopPropagation();
    pop.classList.toggle('on');
  });
  pop.addEventListener('click', function(e){
    e.stopPropagation();
    var chip = e.target.closest('[data-theme-pick]');
    if(chip){ pickTheme(chip.getAttribute('data-theme-pick')); return; }
    if(e.target.closest('#crm-auto-sw')) toggleAuto();
  });
  document.addEventListener('click', function(){ pop.classList.remove('on'); });

  applyTheme();                                        // дорисовать пилюли
}

if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
else mount();

setInterval(function(){ if(autoMode) applyTheme(); }, 60000);   // авто-подстройка раз в минуту

window.crmTheme = {apply:applyTheme, pick:pickTheme, themes:THEMES};
})();
</script>
