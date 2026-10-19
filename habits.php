<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

requireAuth();

$me       = currentUser();
$myName   = $me['full_name'] ?: $me['username'];
$myAvatar = $me['picture'] ?? '';
$pageTitle = 'Ikhlas — трекер привычек';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#f4f7f6">
<title><?= htmlspecialchars($pageTitle) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
/* стартовые цвета; дальше их перекрывает выбранная тема (includes/theme.php) */
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
:root{--gold:#ffd700;--silver:#c0c8d4;--bronze:#cd7f32}

/* ── ШАПКА САЙТА ── */
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

.ht-wrap{max-width:1180px;margin:0 auto;padding:22px 22px 56px;position:relative;z-index:1}

.kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.kpi-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px 18px;box-shadow:var(--shadow)}
.kpi-lbl{font-size:12px;color:var(--muted);margin-bottom:8px;font-weight:500}
.kpi-val{font-size:30px;font-weight:800;line-height:1;letter-spacing:-1px}
.c-green{color:var(--green)}.c-blue{color:var(--accent)}.c-yellow{color:var(--yellow)}.c-purple{color:var(--purple)}

/* вкладки-пилюли, а не подчёркивания как в админке */
.tabs{display:flex;gap:6px;margin-bottom:20px;overflow-x:auto;white-space:nowrap;
  background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:5px;box-shadow:var(--shadow)}
.tabs::-webkit-scrollbar{display:none}
.tab{padding:9px 16px;background:none;border:none;border-radius:10px;color:var(--muted);font-size:14px;
  font-weight:600;cursor:pointer;font-family:inherit;flex-shrink:0;transition:.15s}
.tab:hover{color:var(--text)}
.tab.active{color:#fff;background:var(--accent)}

.datebar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:nowrap}
.datebar .inp{width:170px;flex-shrink:0}
.nav-btn{background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:10px;width:38px;height:38px;font-size:17px;cursor:pointer;font-family:inherit;flex-shrink:0}
.nav-btn:hover{border-color:var(--accent)}
.date-main{flex:1;min-width:150px}
.date-big{font-size:16px;font-weight:700}
.date-hijri{font-size:12px;color:var(--purple);margin-top:2px}
.inp{background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit}
.inp:focus{outline:none;border-color:var(--accent)}

.me-ava{width:30px;height:30px;border-radius:50%;vertical-align:middle;flex-shrink:0}

.prog{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px 18px;margin-bottom:18px;box-shadow:var(--shadow)}
.prog-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:9px}
.prog-pct{font-size:28px;font-weight:800}
.prog-bar{height:9px;background:var(--surface2);border-radius:5px;overflow:hidden}
.prog-fill{height:100%;border-radius:5px;transition:width .3s,background .3s}

.cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;align-items:start}
.cat{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px 16px 10px;box-shadow:var(--shadow)}
.cat-h{font-size:13px;font-weight:700;color:var(--text);margin-bottom:12px}
.hrow{display:flex;align-items:center;gap:10px;background:var(--surface2);border:1px solid transparent;border-radius:13px;padding:11px 13px;margin-bottom:8px;transition:.15s}
.hname{flex:1;font-size:15px;font-weight:500;min-width:0;overflow:hidden;text-overflow:ellipsis}
.hbtns{display:flex;gap:6px;flex-shrink:0}
.yn{padding:8px 16px;border-radius:10px;border:1.5px solid var(--border);background:var(--surface2);
    color:var(--muted);font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:.12s;flex-shrink:0}
.yn.yes.on{background:rgba(56,217,169,.22);border-color:var(--green);color:var(--green)}
.yn.no.on {background:rgba(255,107,107,.22);border-color:var(--red);color:var(--red)}
.yn:hover{color:var(--text)}
.hrow.nope{opacity:.62}
.qty-chip{display:inline-block;background:rgba(56,217,169,.2);color:var(--green);border-radius:20px;
          padding:1px 8px;font-size:11px;font-weight:800;margin-left:4px;vertical-align:middle}
.qm-ov{position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.qm{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:22px;width:320px;max-width:100%;text-align:center}
.qm-t{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px}
.qm-n{font-size:19px;font-weight:800;margin:6px 0 16px}
.qm-row{display:flex;align-items:center;gap:8px;justify-content:center;margin-bottom:16px}
.qm-inp{width:110px;padding:14px;font-size:30px;font-weight:800;text-align:center;border-radius:12px;
        border:2px solid var(--green);background:var(--surface2);color:var(--text);font-family:inherit}
.qm-step{width:46px;height:46px;border-radius:12px;border:1.5px solid var(--border);background:var(--surface2);
         color:var(--text);font-size:22px;font-weight:700;cursor:pointer;flex-shrink:0}
.qm-btns{display:flex;gap:8px}
.qm-btns button{flex:1;padding:13px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;border:1px solid var(--border);background:var(--surface2);color:var(--muted)}
.qm-ok{background:var(--green)!important;border-color:var(--green)!important;color:#0b2b20!important}

.sb{width:36px;height:36px;border-radius:10px;border:1.5px solid var(--border);background:var(--surface2);font-size:16px;cursor:pointer;transition:.12s;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sb:active{transform:scale(.92)}
.sb.on-done{background:rgba(56,217,169,.22);border-color:var(--green)}
.sb.on-partial{background:rgba(255,212,59,.22);border-color:var(--yellow)}
.sb.on-miss{background:rgba(255,107,107,.22);border-color:var(--red)}
.sb.on-off{background:rgba(123,131,149,.22);border-color:var(--muted)}
.hrow.done{border-color:rgba(56,217,169,.35)}

.mgrid{overflow-x:auto;background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow)}
.mgrid table{border-collapse:collapse;font-size:12px}
.mgrid th,.mgrid td{border:1px solid var(--border);padding:5px;text-align:center;white-space:nowrap}
.mgrid th{background:var(--surface2);font-size:10px;color:var(--muted);font-weight:600;position:sticky;top:0}
.mgrid th.hcol{writing-mode:vertical-rl;transform:rotate(180deg);height:96px;padding:6px 3px;font-size:11px}
.mgrid td.dcol,.mgrid th.dcol{position:sticky;left:0;background:var(--surface2);text-align:left;padding:5px 8px;font-weight:600;z-index:1}
.cell{width:26px;height:26px;cursor:pointer}
.cell.done{background:rgba(56,217,169,.3)}
.cell.partial{background:rgba(255,212,59,.3)}
.cell.miss{background:rgba(255,107,107,.25)}
.cell.off{background:rgba(123,131,149,.2)}
.mgrid td.pct{font-weight:700}
.mgrid tfoot td{background:var(--surface2);font-weight:800;border-top:2px solid var(--border);font-size:12px}
.mgrid tfoot td.dcol{z-index:2}
.mgrid tfoot .tf-sub{display:block;font-size:9px;font-weight:600;color:var(--muted);margin-top:1px}

.msum{background:var(--surface);border:1px solid var(--border);border-radius:16px;margin-top:18px;overflow:hidden;box-shadow:var(--shadow)}
.msum-h{padding:13px 16px;border-bottom:1px solid var(--border);display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.msum-h b{font-size:14px}
.msum-h span{font-size:12px;color:var(--muted)}
.msum-sc{overflow-x:auto}
.msum table{width:100%;border-collapse:collapse;font-size:13px;min-width:520px}
.msum th{background:var(--surface2);padding:9px 12px;text-align:left;font-size:10px;font-weight:600;
         text-transform:uppercase;letter-spacing:.5px;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap}
.msum th.num,.msum td.num{text-align:center;white-space:nowrap}
.msum td{padding:9px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
.msum tbody tr:last-child td{border-bottom:none}
.msum .s-name{font-weight:600}
.msum .s-cat{display:block;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-top:1px}
.msum .s-yes{color:var(--green);font-weight:700}
.msum .s-no{color:var(--red);font-weight:700}
.msum .s-qty{font-weight:800}
.msum .s-zero{color:var(--muted);font-weight:400}
.msum-bar{display:flex;align-items:center;gap:9px;min-width:140px}
.msum-track{flex:1;height:7px;background:var(--surface2);border-radius:4px;overflow:hidden;min-width:60px}
.msum-fill{height:100%;border-radius:4px;transition:width .3s}
.msum-pct{font-weight:800;font-size:13px;width:42px;text-align:right;flex-shrink:0}
.msum tfoot td{background:var(--surface2);font-weight:800;border-top:2px solid var(--border);padding:11px 12px}
@media (max-width:768px){
  .msum table{min-width:460px;font-size:12px}
  .msum td,.msum th{padding:8px 9px}
}

.year-wrap{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:18px;overflow-x:auto;box-shadow:var(--shadow)}
.ymonth{display:flex;gap:3px;align-items:center;margin-bottom:5px}
.ymname{width:34px;font-size:11px;color:var(--muted);flex-shrink:0}
.yday{width:13px;height:13px;border-radius:3px;background:var(--surface2);flex-shrink:0}
.yl0{background:var(--surface2)}
.yl1{background:rgba(56,217,169,.22)}
.yl2{background:rgba(56,217,169,.45)}
.yl3{background:rgba(56,217,169,.7)}
.yl4{background:var(--green)}
.ylegend{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--muted);margin-top:12px}

.lb-row{display:flex;align-items:center;gap:12px;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:14px 16px;margin-bottom:9px;box-shadow:var(--shadow)}
.lb-rank{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;flex-shrink:0;background:var(--surface2);color:var(--muted)}
.lb-row.r1{border-color:var(--gold);background:linear-gradient(90deg,rgba(255,215,0,.12),transparent)}
.lb-row.r1 .lb-rank{background:rgba(255,215,0,.2);color:var(--gold)}
.lb-row.r2{border-color:var(--silver);background:linear-gradient(90deg,rgba(192,200,212,.1),transparent)}
.lb-row.r2 .lb-rank{background:rgba(192,200,212,.18);color:var(--silver)}
.lb-row.r3{border-color:var(--bronze);background:linear-gradient(90deg,rgba(205,127,50,.12),transparent)}
.lb-row.r3 .lb-rank{background:rgba(205,127,50,.2);color:var(--bronze)}
.lb-row.me{box-shadow:0 0 0 2px rgba(91,124,250,.4)}
.lb-name{flex:1;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis}
.lb-sub{font-size:11px;color:var(--muted);margin-top:2px}
.lb-pct{font-size:20px;font-weight:800;flex-shrink:0}

.mh{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:12px 14px;margin-bottom:8px;box-shadow:var(--shadow)}
.btn{background:var(--accent);color:#fff;border:none;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.btn:hover{opacity:.88}
.btn-x{background:none;border:1px solid rgba(255,107,107,.3);color:rgba(255,107,107,.75);border-radius:8px;width:32px;height:32px;cursor:pointer;flex-shrink:0;font-family:inherit}
.btn-x:hover{border-color:var(--red);color:var(--red)}

.empty{text-align:center;padding:44px 16px;color:var(--muted)}
.empty-ico{font-size:44px;margin-bottom:12px;opacity:.6}
#toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--surface2);border:1px solid var(--border);border-radius:11px;padding:11px 18px;font-size:13px;z-index:999;display:none;box-shadow:0 8px 30px rgba(0,0,0,.5)}
#toast.err{border-color:var(--red);color:var(--red)}

@media(max-width:768px){
  body{overflow-x:hidden}
  .ht-wrap{width:100%;max-width:100%;padding:16px 14px 44px}
  .cats{grid-template-columns:1fr}
  /* шапка: логотип слева, кнопки справа — в одну строку, имя прячем */
  .site-head-in{padding:10px 14px;gap:8px}
  .ht-who{display:none}
  .lnk{padding:8px 11px;font-size:13px}
  .datebar{flex-wrap:wrap}
  .date-main{order:1;flex:1 1 100%;min-width:0}
  .nav-btn{order:2}
  .datebar .inp{order:3;flex:1 1 auto;width:auto;min-width:0}
}
@media(max-width:640px){
  .ht-wrap{padding:14px 12px 40px}
  .brand-sub{display:none}
  .kpi{grid-template-columns:1fr 1fr;gap:10px}
  .kpi-card{padding:13px 14px;min-width:0}
  .kpi-val{font-size:24px}
  .kpi-lbl{font-size:11px}
  .tab{padding:9px 13px;font-size:13.5px}
  .cat{padding:12px 12px 6px;min-width:0}
  .hrow{padding:10px;gap:8px;min-width:0}
  .hname{font-size:13px;min-width:0}
  .yn{padding:9px 14px;font-size:13px}
  .sb{width:44px;height:44px;font-size:18px}
  .prog-pct{font-size:24px}
  .mgrid{max-width:100%;-webkit-overflow-scrolling:touch}
  .mgrid th.hcol{height:80px;font-size:10px}
  .mgrid td.dcol,.mgrid th.dcol{font-size:11px;padding:4px 6px}
  .year-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
  .yday{width:11px;height:11px}
  .lb-row{padding:11px;gap:9px;min-width:0}
  .lb-rank{width:32px;height:32px;font-size:14px}
  .lb-pct{font-size:18px}
}
@media(max-width:380px){
  .yn{padding:8px 11px;font-size:12px}
  .sb{width:38px;height:38px;font-size:16px}
  .hbtns{gap:4px}
}
</style>

<header class="site-head">
  <div class="site-head-in">
    <a class="brand" href="/habits.php">
      <span class="brand-mark">I</span>
      <span>
        <span class="brand-name">Ikhlas</span>
        <span class="brand-sub">трекер привычек</span>
      </span>
    </a>
    <div class="ht-right" style="position:relative">
      <?php if ($myAvatar): ?><img src="<?= htmlspecialchars($myAvatar) ?>" alt="" class="me-ava"><?php endif; ?>
      <span class="ht-who"><b><?= htmlspecialchars($myName) ?></b></span>
      <button class="lnk" onclick="exportXlsx()">Excel</button>
      <a class="lnk" href="/logout.php">Выйти</a>
    </div>
  </div>
</header>

<div class="ht-wrap">

  <div class="kpi">
    <div class="kpi-card"><div class="kpi-lbl">Сегодня</div><div class="kpi-val c-green" id="k-today">—</div></div>
    <div class="kpi-card"><div class="kpi-lbl">Средний % месяца</div><div class="kpi-val c-blue" id="k-avg">—</div></div>
    <div class="kpi-card"><div class="kpi-lbl">Серия дней</div><div class="kpi-val c-yellow" id="k-streak">—</div></div>
    <div class="kpi-card"><div class="kpi-lbl">Идеальных дней</div><div class="kpi-val c-purple" id="k-perfect">—</div></div>
  </div>

  <div class="tabs">
    <button class="tab active" data-t="today"  onclick="tab('today')">Сегодня</button>
    <button class="tab"        data-t="month"  onclick="tab('month')">Месяц</button>
    <button class="tab"        data-t="year"   onclick="tab('year')">Год</button>
    <button class="tab"        data-t="board"  onclick="tab('board')">🏆 Лидеры</button>
    <button class="tab"        data-t="my"     onclick="tab('my')">Мои привычки</button>
  </div>

  <!-- СЕГОДНЯ -->
  <div id="t-today">
    <div class="datebar">
      <button class="nav-btn" onclick="shiftDay(-1)">‹</button>
      <div class="date-main">
        <div class="date-big" id="d-big">—</div>
        <div class="date-hijri" id="d-hijri">—</div>
      </div>
      <button class="nav-btn" onclick="shiftDay(1)">›</button>
      <input class="inp" type="date" id="d-pick" onchange="pickDate(this.value)">
    </div>

    <div class="prog">
      <div class="prog-top">
        <div class="prog-pct" id="p-pct">0%</div>
        <div style="font-size:12px;color:var(--muted)" id="p-sub">—</div>
      </div>
      <div class="prog-bar"><div class="prog-fill" id="p-fill" style="width:0%"></div></div>
    </div>

    <div id="habits-list"></div>

    <div class="cat" style="margin-top:16px">
      <div class="cat-h">Заметка дня</div>
      <div class="hrow" style="flex-wrap:wrap;gap:8px;margin-bottom:6px">
        <input class="inp" id="d-mood" placeholder="Настроение (напр. 😊 или «бодрый»)" style="flex:1;min-width:150px" onchange="saveDay()">
        <input class="inp" id="d-comment" placeholder="Комментарий к дню" style="flex:3;min-width:200px" onchange="saveDay()">
      </div>
    </div>
  </div>

  <!-- МЕСЯЦ -->
  <div id="t-month" style="display:none">
    <div class="datebar">
      <button class="nav-btn" onclick="shiftMonth(-1)">‹</button>
      <div class="date-main"><div class="date-big" id="m-title">—</div></div>
      <button class="nav-btn" onclick="shiftMonth(1)">›</button>
    </div>
    <div class="mgrid" id="m-grid"></div>
    <div style="font-size:11px;color:var(--muted);margin-top:8px">Нажми на клетку: пусто → <b>Да</b> → <b>Нет</b> → пусто. У Нафл / Коран / Чтение спросит количество.</div>
    <div id="m-sum"></div>
  </div>

  <!-- ГОД -->
  <div id="t-year" style="display:none">
    <div class="datebar">
      <button class="nav-btn" onclick="shiftYear(-1)">‹</button>
      <div class="date-main"><div class="date-big" id="y-title">—</div></div>
      <button class="nav-btn" onclick="shiftYear(1)">›</button>
    </div>
    <div class="year-wrap" id="y-map"></div>
  </div>

  <!-- ЛИДЕРЫ -->
  <div id="t-board" style="display:none">
    <div class="datebar">
      <button class="nav-btn" onclick="shiftBoard(-1)">‹</button>
      <div class="date-main"><div class="date-big" id="b-title">—</div></div>
      <button class="nav-btn" onclick="shiftBoard(1)">›</button>
    </div>
    <div id="b-list"></div>
  </div>

  <!-- МОИ ПРИВЫЧКИ -->
  <div id="t-my" style="display:none">
    <div class="hrow" style="flex-wrap:wrap;gap:8px;margin-bottom:14px">
      <input class="inp" id="nh-name" placeholder="Название привычки" style="flex:2;min-width:150px">
      <input class="inp" id="nh-cat" placeholder="Категория (напр. 💪 Здоровье)" style="flex:2;min-width:150px">
      <button class="btn" onclick="addHabit()">+ Добавить</button>
    </div>
    <div id="my-list"></div>
  </div>

</div>

<!-- Ввод количества после «Да» -->
<div class="qm-ov" id="qty-modal" onclick="if(event.target===this)closeQty()">
  <div class="qm">
    <div class="qm-t">Сколько раз?</div>
    <div class="qm-n" id="qty-name"></div>
    <div class="qm-row">
      <button class="qm-step" onclick="qtyPlus(-1)">−</button>
      <input class="qm-inp" id="qty-input" type="number" min="1" value="1"
             inputmode="numeric" onkeydown="if(event.key==='Enter')confirmQty()">
      <button class="qm-step" onclick="qtyPlus(1)">+</button>
    </div>
    <div class="qm-btns">
      <button onclick="closeQty()">Отмена</button>
      <button class="qm-ok" onclick="confirmQty()">Готово</button>
    </div>
  </div>
</div>

<div id="toast"></div>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
const ST = ['done','partial','miss','off'];
const ICON = {done:'✅', partial:'➖', miss:'❌', off:'⭕', '':'·'};
const PTS  = {done:1, partial:0.5, miss:0, off:0};

const MO = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const MO_S = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
const WD = ['ВС','ПН','ВТ','СР','ЧТ','ПТ','СБ'];
const HIJRI_M = ['Мухаррам','Сафар','Раби аль-Авваль','Раби ас-Сани','Джумада аль-Авваль','Джумада ас-Сани',
                 'Раджаб','Шаабан','Рамадан','Шавваль','Зуль-Каада','Зуль-Хиджа'];

const HIJRI_LEAP = [2,5,7,10,13,16,18,21,24,26,29];
const HIJRI_ANCHOR = Date.UTC(2026, 5, 16);
function hijriMonthLen(y, m){
  if (m === 12) return HIJRI_LEAP.includes(((y-1)%30)+1) ? 30 : 29;
  return (m % 2 === 1) ? 30 : 29;
}
function hijri(dt){
  let diff = Math.round((Date.UTC(dt.getFullYear(), dt.getMonth(), dt.getDate()) - HIJRI_ANCHOR) / 86400000);
  let y = 1448, m = 1, d = 1;
  while (diff > 0){
    const rem = hijriMonthLen(y, m) - d;
    if (diff <= rem) { d += diff; diff = 0; }
    else { diff -= rem + 1; d = 1; m++; if (m > 12){ m = 1; y++; } }
  }
  while (diff < 0){
    if (d > 1) { const step = Math.min(-diff, d - 1); d -= step; diff += step; }
    else { m--; if (m < 1){ m = 12; y--; } d = hijriMonthLen(y, m); diff++; }
  }
  return d + ' ' + HIJRI_M[m-1] + ' ' + y;
}

const pad=n=>String(n).padStart(2,'0');
const iso=d=>d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function toast(m,e){const t=document.getElementById('toast');t.textContent=m;t.className=e?'err':'';t.style.display='block';clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',2600);}
function pctColor(p){ return p>=80?'var(--green)' : p>=50?'var(--yellow)' : p>0?'var(--red)' : 'var(--muted)'; }

async function api(action, opts){
  opts=opts||{};
  const url='/api/habits.php?action='+action+(opts.params?('&'+opts.params):'');
  const r=await fetch(url,{method:opts.method||'GET',headers:opts.body?{'Content-Type':'application/json'}:{},body:opts.body?JSON.stringify(opts.body):undefined});
  const j=await r.json().catch(()=>({ok:false,error:'Ошибка ответа'}));
  if(!j.ok) throw new Error(j.error||'Ошибка');
  return j;
}

let curDate = new Date();
let mDate   = new Date();
let yYear   = new Date().getFullYear();
let bDate   = new Date();
let dayData = null;

function tab(t){
  document.querySelectorAll('.tab').forEach(b=>b.classList.toggle('active', b.dataset.t===t));
  ['today','month','year','board','my'].forEach(x=>{
    document.getElementById('t-'+x).style.display = (x===t)?'':'none';
  });
  if(t==='month') loadMonth();
  if(t==='year')  loadYear();
  if(t==='board') loadBoard();
  if(t==='my')    loadMy();
}

function shiftDay(n){ curDate.setDate(curDate.getDate()+n); loadDay(); }
function pickDate(v){ if(v){ curDate = new Date(v+'T00:00:00'); loadDay(); } }

async function loadDay(){
  const ds = iso(curDate);
  document.getElementById('d-pick').value = ds;
  document.getElementById('d-big').textContent   = WD[curDate.getDay()]+', '+curDate.getDate()+' '+MO[curDate.getMonth()]+' '+curDate.getFullYear();
  document.getElementById('d-hijri').textContent = '☾ '+hijri(curDate);
  try{
    const j = await api('day',{params:'date='+ds});
    dayData = j;
    document.getElementById('d-mood').value    = j.mood||'';
    document.getElementById('d-comment').value = j.comment||'';
    renderHabits(j);
    setProg(j.pct, j.points, j.habits.length);
    loadStats();
  }catch(e){ toast(e.message,true); }
}

function setProg(pct, points, total){
  document.getElementById('p-pct').textContent = pct+'%';
  document.getElementById('p-pct').style.color = pctColor(pct);
  document.getElementById('p-sub').textContent = points+' из '+total+' привычек';
  const f=document.getElementById('p-fill');
  f.style.width = Math.min(100,pct)+'%';
  f.style.background = pctColor(pct);
  document.getElementById('k-today').textContent = pct+'%';
}

function renderHabits(j){
  const byCat = {};
  j.habits.forEach(h=>{ (byCat[h.category] = byCat[h.category]||[]).push(h); });
  let html='<div class="cats">';
  for(const cat in byCat){
    html += `<div class="cat"><div class="cat-h">${esc(cat)}</div>`;
    byCat[cat].forEach(h=>{
      const yes = h.status==='done', no = h.status==='miss';
      html += `<div class="hrow ${yes?'done':''} ${no?'nope':''}" id="hr-${h.id}">
        <div class="hname">${esc(h.name)}${yes && h.qty>1 ? ` <span class="qty-chip">×${h.qty}</span>` : ''}</div>
        <div class="hbtns">
          <button class="yn yes ${yes?'on':''}" onclick="markYes(${h.id})">Да</button>
          <button class="yn no  ${no ?'on':''}" onclick="markNo(${h.id})">Нет</button>
        </div>
      </div>`;
    });
    html += '</div>';
  }
  html += '</div>';
  document.getElementById('habits-list').innerHTML = j.habits.length
    ? html
    : '<div class="empty"><div class="empty-ico">🎯</div>Нет привычек. Добавь во вкладке «Мои привычки».</div>';
}

let qtyHid = null;
function markYes(hid){
  const h = dayData.habits.find(x=>x.id==hid);
  if(!h) return;
  if(h.status==='done'){ saveMark(hid, '', 0); return; }

  if(!h.ask_qty){ saveMark(hid, 'done', 1); return; }

  qtyHid = hid;
  document.getElementById('qty-name').textContent = h.name;
  const inp = document.getElementById('qty-input');
  inp.value = h.qty>0 ? h.qty : 1;
  document.getElementById('qty-modal').style.display='flex';
  setTimeout(()=>{ inp.focus(); inp.select(); }, 50);
}
function markNo(hid){
  const h = dayData.habits.find(x=>x.id==hid);
  if(!h) return;
  saveMark(hid, h.status==='miss' ? '' : 'miss', 0);
}
function closeQty(){ document.getElementById('qty-modal').style.display='none'; qtyHid=null; }
function qtyPlus(n){
  const inp=document.getElementById('qty-input');
  inp.value = Math.max(1, (parseInt(inp.value)||0) + n);
}
function confirmQty(){
  const n = Math.max(1, parseInt(document.getElementById('qty-input').value)||1);
  const hid = qtyHid;
  closeQty();
  if(hid) saveMark(hid, 'done', n);
}

async function saveMark(hid, status, qty){
  const h = dayData.habits.find(x=>x.id==hid);
  if(!h) return;
  h.status = status; h.qty = (status==='done') ? qty : 0;
  renderHabits(dayData);
  try{
    const j = await api('set',{method:'POST',body:{habit_id:hid,date:iso(curDate),status,qty}});
    setProg(j.pct, j.points, dayData.habits.length);
    loadStats();
  }catch(e){ toast(e.message,true); loadDay(); }
}

async function saveDay(){
  try{
    await api('save_day',{method:'POST',body:{
      date: iso(curDate),
      mood: document.getElementById('d-mood').value,
      comment: document.getElementById('d-comment').value
    }});
    toast('Заметка сохранена');
  }catch(e){ toast(e.message,true); }
}

async function loadStats(){
  try{
    const ym = curDate.getFullYear()+'-'+pad(curDate.getMonth()+1);
    const j = await api('stats',{params:'ym='+ym});
    document.getElementById('k-avg').textContent     = j.avg+'%';
    document.getElementById('k-streak').textContent  = j.streak;
    document.getElementById('k-perfect').textContent = j.perfect;
  }catch(e){}
}

function shiftMonth(n){ mDate.setMonth(mDate.getMonth()+n); loadMonth(); }

async function loadMonth(){
  const ym = mDate.getFullYear()+'-'+pad(mDate.getMonth()+1);
  document.getElementById('m-title').textContent = MO[mDate.getMonth()]+' '+mDate.getFullYear();
  try{
    const j = await api('month',{params:'ym='+ym});
    const days = new Date(mDate.getFullYear(), mDate.getMonth()+1, 0).getDate();
    const H = j.habits;
    monthHabits = H;
    if(!H.length){
      document.getElementById('m-grid').innerHTML='<div class="empty"><div class="empty-ico">🎯</div>Нет привычек</div>';
      document.getElementById('m-sum').innerHTML='';
      return;
    }

    const tot = {};
    H.forEach(h=>{ tot[h.id] = {yes:0, no:0, qty:0, off:0}; });
    let sumPct = 0, filledDays = 0;

    let html = '<table><thead><tr><th class="dcol">День</th>';
    H.forEach(h=>{ html += `<th class="hcol">${esc(h.name)}</th>`; });
    html += '<th>%</th></tr></thead><tbody>';

    for(let d=1; d<=days; d++){
      const dt = new Date(mDate.getFullYear(), mDate.getMonth(), d);
      const ds = iso(dt);
      const row = j.grid[ds] || {};
      let p=0, off=0, any=false;
      H.forEach(h=>{
        const c = row[h.id] || {};
        const s = c.s || '';
        const q = +c.q || 0;
        if(s) any = true;
        if(s==='off'){ off++; tot[h.id].off++; }
        else p += (PTS[s]||0);
        if(s==='done'){ tot[h.id].yes++; tot[h.id].qty += (q>0 ? q : 1); }
        if(s==='miss') tot[h.id].no++;
      });
      const den = H.length - off;
      const pct = den>0 ? Math.round(p/den*100) : 0;
      if(any){ sumPct += pct; filledDays++; }

      html += `<tr><td class="dcol">${pad(d)} ${WD[dt.getDay()]}</td>`;
      H.forEach(h=>{
        const c = row[h.id] || {};
        const s = c.s || '';
        const q = c.q || 0;
        const txt = s==='done' ? (q>0 ? q : 1) : (ICON[s] || '');
        html += `<td class="cell ${s}" onclick="cycleCell('${ds}',${h.id},'${s}')">${txt}</td>`;
      });
      html += `<td class="pct" style="color:${pctColor(pct)}">${pct}%</td></tr>`;
    }

    const avgPct = filledDays>0 ? Math.round(sumPct/filledDays) : 0;
    html += '</tbody><tfoot><tr><td class="dcol">Итого</td>';
    H.forEach(h=>{
      const t = tot[h.id];
      const main = h.ask_qty ? t.qty : t.yes;
      const sub  = h.ask_qty ? (t.yes + ' дн.') : (days - t.off + ' дн.');
      html += `<td>${main || '<span style="color:var(--muted)">0</span>'}<span class="tf-sub">${sub}</span></td>`;
    });
    html += `<td class="pct" style="color:${pctColor(avgPct)}">${avgPct}%</td></tr></tfoot></table>`;
    document.getElementById('m-grid').innerHTML = html;

    renderMonthSummary(H, tot, days, avgPct, filledDays);
  }catch(e){ toast(e.message,true); }
}

function renderMonthSummary(H, tot, days, avgPct, filledDays){
  const box = document.getElementById('m-sum');
  if(!box) return;

  let totYes = 0, totNo = 0, totActive = 0;

  let rows = '';
  H.forEach(h=>{
    const t      = tot[h.id];
    const active = days - t.off;
    const pct    = active>0 ? Math.round(t.yes/active*100) : 0;
    totYes += t.yes; totNo += t.no; totActive += active;

    const qtyCell = h.ask_qty
      ? `<span class="s-qty" style="color:var(--green)">${t.qty}</span>`
      : '<span class="s-zero">—</span>';

    rows += `<tr>
      <td><span class="s-name">${esc(h.name)}</span>${h.category?`<span class="s-cat">${esc(h.category)}</span>`:''}</td>
      <td class="num"><span class="${t.yes?'s-yes':'s-zero'}">${t.yes}</span></td>
      <td class="num"><span class="${t.no?'s-no':'s-zero'}">${t.no}</span></td>
      <td class="num">${qtyCell}</td>
      <td>
        <div class="msum-bar">
          <div class="msum-track"><div class="msum-fill" style="width:${pct}%;background:${pctColor(pct)}"></div></div>
          <div class="msum-pct" style="color:${pctColor(pct)}">${pct}%</div>
        </div>
      </td>
    </tr>`;
  });

  const doneRate = totActive>0 ? Math.round(totYes/totActive*100) : 0;

  box.innerHTML = `
    <div class="msum">
      <div class="msum-h">
        <b>📊 Итоги месяца</b>
        <span>${MO[mDate.getMonth()]} ${mDate.getFullYear()} · дней в месяце: ${days} · отмечено: ${filledDays}</span>
      </div>
      <div class="msum-sc">
        <table>
          <thead><tr>
            <th>Привычка</th>
            <th class="num">✅ Да</th>
            <th class="num">❌ Нет</th>
            <th class="num">Кол-во</th>
            <th style="min-width:150px">Выполнение</th>
          </tr></thead>
          <tbody>${rows}</tbody>
          <tfoot><tr>
            <td>ВСЕГО</td>
            <td class="num" style="color:var(--green)">${totYes}</td>
            <td class="num" style="color:var(--red)">${totNo}</td>
            <td class="num">—</td>
            <td>
              <div class="msum-bar">
                <div class="msum-track"><div class="msum-fill" style="width:${doneRate}%;background:${pctColor(doneRate)}"></div></div>
                <div class="msum-pct" style="color:${pctColor(doneRate)}">${doneRate}%</div>
              </div>
            </td>
          </tr></tfoot>
        </table>
      </div>
    </div>`;
}

let monthHabits = [];
async function cycleCell(ds, hid, cur){
  if(cur === '' ){
    const h = monthHabits.find(x=>x.id==hid);
    let qty = 1;
    if(h && h.ask_qty){
      const n = prompt('Сколько раз? — '+h.name, '1');
      if(n === null) return;
      qty = Math.max(1, parseInt(n)||1);
    }
    await setCell(ds, hid, 'done', qty);
  } else if(cur === 'done'){
    await setCell(ds, hid, 'miss', 0);
  } else {
    await setCell(ds, hid, '', 0);
  }
}
async function setCell(ds, hid, status, qty){
  try{
    await api('set',{method:'POST',body:{habit_id:hid,date:ds,status,qty}});
    loadMonth();
    if(ds === iso(curDate)) loadDay();
  }catch(e){ toast(e.message,true); }
}

function shiftYear(n){ yYear += n; loadYear(); }

async function loadYear(){
  document.getElementById('y-title').textContent = yYear+' год';
  try{
    const j = await api('year',{params:'year='+yYear});
    let html='';
    for(let m=0; m<12; m++){
      const days = new Date(yYear, m+1, 0).getDate();
      html += `<div class="ymonth"><div class="ymname">${MO_S[m]}</div>`;
      for(let d=1; d<=days; d++){
        const ds = yYear+'-'+pad(m+1)+'-'+pad(d);
        const p = j.days[ds];
        let lvl = 'yl0';
        if(p !== undefined){
          lvl = p>=80?'yl4' : p>=50?'yl3' : p>=25?'yl2' : p>0?'yl1' : 'yl0';
        }
        html += `<div class="yday ${lvl}" title="${ds}${p!==undefined?' — '+p+'%':''}"></div>`;
      }
      html += '</div>';
    }
    html += `<div class="ylegend">Меньше
      <span class="yday yl0"></span><span class="yday yl1"></span><span class="yday yl2"></span>
      <span class="yday yl3"></span><span class="yday yl4"></span> Больше</div>`;
    document.getElementById('y-map').innerHTML = html;
  }catch(e){ toast(e.message,true); }
}

function shiftBoard(n){ bDate.setMonth(bDate.getMonth()+n); loadBoard(); }

async function loadBoard(){
  const ym = bDate.getFullYear()+'-'+pad(bDate.getMonth()+1);
  document.getElementById('b-title').textContent = MO[bDate.getMonth()]+' '+bDate.getFullYear();
  try{
    const j = await api('leaderboard',{params:'ym='+ym});
    if(!j.rows.length){
      document.getElementById('b-list').innerHTML='<div class="empty"><div class="empty-ico">🏆</div>За этот месяц отметок ещё нет</div>';
      return;
    }
    const medal = {1:'🥇',2:'🥈',3:'🥉'};
    document.getElementById('b-list').innerHTML = j.rows.map(r=>{
      const cls = (r.rank<=3?' r'+r.rank:'') + (r.user_id==j.me?' me':'');
      return `<div class="lb-row${cls}">
        <div class="lb-rank">${medal[r.rank]||r.rank}</div>
        <div class="lb-name">${esc(r.name)}${r.user_id==j.me?' <span style="color:var(--accent);font-size:11px">(вы)</span>':''}
          <div class="lb-sub">${r.days} дн. · ${r.perfect} идеальных</div>
        </div>
        <div class="lb-pct" style="color:${pctColor(r.avg)}">${r.avg}%</div>
      </div>`;
    }).join('');
  }catch(e){ toast(e.message,true); }
}

async function loadMy(){
  try{
    const j = await api('habits');
    document.getElementById('my-list').innerHTML = j.habits.length
      ? j.habits.map(h=>`<div class="mh">
          <div class="hname">${esc(h.name)}</div>
          <span style="font-size:11px;color:var(--muted)">${esc(h.category)}</span>
          <button class="btn-x" onclick="delHabit(${h.id},'${esc(h.name).replace(/'/g,'')}')">✕</button>
        </div>`).join('')
      : '<div class="empty"><div class="empty-ico">🎯</div>Нет привычек</div>';
  }catch(e){ toast(e.message,true); }
}

async function addHabit(){
  const name = document.getElementById('nh-name').value.trim();
  const cat  = document.getElementById('nh-cat').value.trim();
  if(!name){ toast('Введите название',true); return; }
  try{
    await api('add_habit',{method:'POST',body:{name, category:cat}});
    document.getElementById('nh-name').value='';
    document.getElementById('nh-cat').value='';
    toast('Привычка добавлена');
    loadMy(); loadDay();
  }catch(e){ toast(e.message,true); }
}

async function delHabit(id,name){
  if(!confirm('Убрать привычку «'+name+'»?\n\nИстория отметок сохранится.')) return;
  try{
    await api('del_habit',{method:'DELETE',params:'id='+id});
    toast('Убрано');
    loadMy(); loadDay();
  }catch(e){ toast(e.message,true); }
}

async function exportXlsx(){
  try{
    const year = curDate.getFullYear();
    const j = await api('export',{params:'year='+year});
    if(!j.habits.length){ toast('Нет привычек',true); return; }

    const rows=[];
    const start = new Date(year,0,1), end = new Date(year,11,31);
    for(let d=new Date(start); d<=end; d.setDate(d.getDate()+1)){
      const ds = iso(d);
      const g = j.grid[ds] || {};
      const dd = j.days[ds] || {};
      const rec = {
        'Дата': ds,
        'День': WD[d.getDay()],
        'Дата по Хиджре': hijri(d),
      };
      let p=0, off=0;
      j.habits.forEach(h=>{
        const c = g[h.id] || {};
        const s = c.s || '';
        rec[h.name] = s==='done' ? (c.q>0 ? c.q : 1) : (s ? (ICON[s]==='·' ? '' : ICON[s]) : '');
        if(s==='off') off++; else p += (PTS[s]||0);
      });
      const den = j.habits.length - off;
      rec['% Дня'] = den>0 ? Math.round(p/den*100)+'%' : '';
      rec['Балл'] = p;
      rec['Настроение'] = dd.mood||'';
      rec['Комментарий'] = dd.comment||'';
      rows.push(rec);
    }
    const ws = XLSX.utils.json_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Трекер '+year);
    XLSX.writeFile(wb, `HabitTracker_${year}.xlsx`);
    toast('Экспортировано');
  }catch(e){ toast(e.message,true); }
}

loadDay();
</script>
</body>
</html>
