<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';

requireAuth();

$me       = currentUser();
$myName   = $me['full_name'] ?: $me['username'];
$myAvatar = $me['picture'] ?? '';
$pageTitle = 'Ikhlas — финансы';
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
/* цвета графика «Расходы / Поступления» — подобраны под каждую тему,
   чтобы столбцы различались и при дальтонизме, и на тёмном фоне */
:root{--c-out:#7a5cc4;--c-in:#0f9d76}
[data-theme="sepia"]{--c-out:#7356b0;--c-in:#4f7a3a}
[data-theme="gray"],[data-theme="black"]{--c-out:#9a7ee6;--c-in:#22ad84}

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
.me-ava{width:30px;height:30px;border-radius:50%;vertical-align:middle;flex-shrink:0}

.ht-wrap{max-width:1180px;margin:0 auto;padding:22px 22px 56px;position:relative;z-index:1}
.num{font-variant-numeric:tabular-nums;white-space:nowrap}
.muted{color:var(--muted)}

/* ── МЕСЯЦ ── */
.datebar{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.nav-btn{background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:10px;width:38px;height:38px;font-size:17px;cursor:pointer;font-family:inherit;flex-shrink:0}
.nav-btn:hover{border-color:var(--accent)}
.date-main{flex:1;min-width:0}
.date-big{font-size:18px;font-weight:700}
.date-sub{font-size:12px;color:var(--muted);margin-top:2px}
.date-sub a{color:var(--accent);cursor:pointer;text-decoration:none;font-weight:600}
.inp{background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:9px 12px;color:var(--text);font-size:13px;font-family:inherit}
.inp:focus{outline:none;border-color:var(--accent)}

/* ── KPI ── */
.kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.kpi.kpi-auto{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.kpi-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px 18px;box-shadow:var(--shadow);min-width:0}
.kpi-lbl{font-size:12px;color:var(--muted);margin-bottom:8px;font-weight:500}
.kpi-val{font-size:26px;font-weight:800;line-height:1.05;letter-spacing:-.8px;font-variant-numeric:tabular-nums;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi-sub{font-size:12px;color:var(--muted);margin-top:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.c-green{color:var(--green)}.c-red{color:var(--red)}.c-purple{color:var(--purple)}

/* ── ВКЛАДКИ ── */
.tabs{position:relative;display:flex;gap:6px;margin-bottom:20px;overflow-x:auto;white-space:nowrap;
  background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:5px;box-shadow:var(--shadow)}
.tabs::-webkit-scrollbar{display:none}
.tab{padding:9px 16px;background:none;border:none;border-radius:10px;color:var(--muted);font-size:14px;
  font-weight:600;cursor:pointer;font-family:inherit;flex-shrink:0;transition:.15s}
.tab:hover{color:var(--text)}
.tab.active{color:#fff;background:var(--accent)}

/* ── КАРТОЧКИ-СПИСКИ ── */
.box{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);overflow:hidden;min-width:0}
.box-h{padding:13px 16px;border-bottom:1px solid var(--border);display:flex;align-items:baseline;gap:10px;flex-wrap:wrap}
.box-h b{font-size:14px}
.box-h span{font-size:12px;color:var(--muted)}
.stack{display:flex;flex-direction:column;gap:16px;min-width:0}
.cols{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:16px;align-items:start}
.cols.half{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
.sec-l{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:8px}

.it{padding:12px 16px;border-top:1px solid var(--border)}
.it:first-child{border-top:0}
.it-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:8px}
.it-n{font-weight:600;font-size:14px;overflow-wrap:anywhere}
.it-s{font-size:12px;color:var(--muted);margin-top:1px}
.it-r{text-align:right;font-weight:700;white-space:nowrap}
.track{height:7px;background:var(--surface2);border-radius:4px;overflow:hidden}
.track i{display:block;height:100%;background:var(--accent);border-radius:4px;max-width:100%;transition:width .3s}
.track.warn i{background:var(--yellow)}.track.bad i{background:var(--red)}.track.inc i{background:var(--green)}
.it-subs{margin:9px 0 0 2px;padding-left:12px;border-left:2px solid var(--border);display:flex;flex-direction:column;gap:4px}
.it-sub{display:flex;justify-content:space-between;gap:10px;font-size:13px;color:var(--muted)}
.it-sub .num{color:var(--text);font-weight:600}
.delta{font-size:11px;font-weight:700;margin-left:6px}
.delta.up{color:var(--red)}.delta.down{color:var(--green)}.delta.same{color:var(--muted);font-weight:500}

.alerts{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
.alert{border-radius:12px;padding:10px 14px;font-size:13px;display:flex;justify-content:space-between;gap:10px;
  border:1px solid;background:var(--surface)}
.alert.warn{border-color:var(--yellow);background:color-mix(in srgb,var(--yellow) 10%,var(--surface))}
.alert.bad{border-color:var(--red);background:color-mix(in srgb,var(--red) 10%,var(--surface))}
.alert.warn b{color:var(--yellow)}.alert.bad b{color:var(--red)}

.quick-wrap{margin-bottom:16px}
.quick{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;scrollbar-width:thin}
.qb{flex:none;display:flex;flex-direction:column;align-items:flex-start;gap:2px;background:var(--surface);border:1px solid var(--border);
  border-radius:12px;padding:9px 14px;min-width:100px;cursor:pointer;font-family:inherit;color:var(--text);box-shadow:var(--shadow);transition:.15s}
.qb:hover{border-color:var(--accent);transform:translateY(-1px)}
.qb-t{font-weight:600;font-size:13px;white-space:nowrap}
.qb-a{font-size:12px;color:var(--muted)}

/* ── ОПЕРАЦИИ ── */
.filters{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}
.filters .inp{flex:1 1 200px;min-width:0}
.ops-sum{font-size:13px;color:var(--muted);margin-bottom:4px}
.day-h{display:flex;justify-content:space-between;padding:14px 4px 6px;font-size:12px;color:var(--muted);font-weight:700}
.op{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:2px 12px;padding:11px 16px;border:0;border-top:1px solid var(--border);
  width:100%;text-align:left;background:none;color:var(--text);font-family:inherit;font-size:14px;cursor:pointer}
.op:first-child{border-top:0}
.op:hover{background:var(--surface2)}
.op-c{font-weight:600;min-width:0;overflow-wrap:anywhere}
.op-sub{color:var(--muted);font-weight:500}
.op-a{text-align:right;font-weight:700}
.op-a.in{color:var(--green)}
.op-n{grid-column:1/-1;font-size:12px;color:var(--muted);overflow-wrap:anywhere}
.op-n:empty{display:none}
.tag{display:inline-block;font-size:11px;padding:1px 8px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);
  color:var(--text);margin-left:6px;font-weight:600;vertical-align:1px}

/* ── СЕМЬЯ ── */
.fam-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.fam{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px;box-shadow:var(--shadow);
  display:flex;flex-direction:column;gap:8px;min-width:0}
.fam-h{display:flex;justify-content:space-between;align-items:baseline;gap:8px}
.fam-h b{font-size:15px}
.fam-s{font-size:22px;font-weight:800;letter-spacing:-.5px;font-variant-numeric:tabular-nums}
.fam-s small{font-size:12px;font-weight:500;color:var(--muted);letter-spacing:0}
.fam ul{list-style:none;display:flex;flex-direction:column;gap:3px;font-size:13px}
.fam li{display:flex;justify-content:space-between;gap:8px}
.fam-top{font-size:12px;color:var(--muted)}
.note{font-size:12px;color:var(--muted);margin-top:12px;line-height:1.5}

/* ── ОТЧЁТЫ ── */
.chart{position:relative;padding:14px 10px 0;overflow-x:auto}
.chart svg{display:block;width:100%;min-width:460px;height:auto}
.chart path,.chart text,.chart line{pointer-events:none}
.chart .hit{fill:var(--text);fill-opacity:0;cursor:pointer}
.chart .hit.on{fill-opacity:.05}
.legend{display:flex;gap:18px;font-size:12px;color:var(--text);padding:6px 16px 14px}
.legend i,.tip i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}
.tip{position:absolute;pointer-events:none;background:var(--surface);border:1px solid var(--border);border-radius:10px;
  padding:8px 11px;font-size:12px;box-shadow:0 8px 24px rgba(0,0,0,.18);white-space:nowrap;display:none;z-index:2}
.tip b{display:block;margin-bottom:4px}
.tip div{display:flex;align-items:center;gap:4px}
.tip .num{margin-left:auto;padding-left:12px;font-weight:700}
.rtbl{overflow-x:auto}
.rtbl table{border-collapse:collapse;width:100%;font-size:13px;min-width:600px}
.rtbl th,.rtbl td{padding:9px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap}
.rtbl th{background:var(--surface2);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)}
.rtbl th:first-child,.rtbl td:first-child{text-align:left;position:sticky;left:0;background:var(--surface);max-width:220px;overflow:hidden;text-overflow:ellipsis}
.rtbl th:first-child{background:var(--surface2)}
.rtbl td{font-variant-numeric:tabular-nums}
.rtbl td.cur,.rtbl th.cur{font-weight:700;color:var(--text)}
.rtbl tr.sub td{color:var(--muted);font-size:12px}
.rtbl tr.sub td:first-child{padding-left:28px}
.rtbl td.hi{color:var(--red)}
.rtbl tfoot td,.rtbl tfoot td:first-child{background:var(--surface2);font-weight:800}
.rtbl tbody tr:last-child td{border-bottom:2px solid var(--border)}

/* ── НАСТРОЙКИ ── */
.row{display:flex;gap:8px;align-items:center;padding:8px 14px;border-top:1px solid var(--border);flex-wrap:wrap}
.box-h + .row,.box-h + .cs{border-top:0}
.cs{border-top:1px solid var(--border);padding:4px 0}
.cs .row{border-top:0;padding-top:4px;padding-bottom:4px}
.row-sub{padding-left:24px!important}
.row-add{background:var(--surface2)}
.row-add .inp{background:var(--surface)}
.sub-mark{color:var(--muted);font-size:14px;width:14px;flex-shrink:0;text-align:center}
.grow{flex:1 1 140px;min-width:0}
.bud{width:110px;flex-shrink:0}
.btn{background:var(--accent);color:#fff;border:none;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
.btn:hover{opacity:.88}
.btn-sub{background:none;border:1px dashed var(--border);color:var(--muted);border-radius:8px;height:32px;padding:0 10px;
  font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0}
.btn-sub:hover,.btn-sub.on{border-color:var(--accent);color:var(--accent)}
.btn-x{background:none;border:1px solid rgba(255,107,107,.3);color:rgba(255,107,107,.75);border-radius:8px;width:32px;height:32px;cursor:pointer;flex-shrink:0;font-family:inherit}
.btn-x:hover{border-color:var(--red);color:var(--red)}
.hint{font-size:12px;color:var(--muted);padding:10px 16px 14px;line-height:1.5;border-top:1px solid var(--border)}
.q-info{font-size:12px;color:var(--muted)}

.empty{text-align:center;padding:44px 16px;color:var(--muted)}
.empty-ico{font-size:44px;margin-bottom:12px;opacity:.6}
.empty.sm{padding:22px 16px;font-size:14px}

/* ── ВВОД ОПЕРАЦИИ ── */
.btn-add{padding:10px 16px;flex-shrink:0}
.fab{display:none;position:fixed;right:16px;bottom:calc(16px + env(safe-area-inset-bottom,0px));z-index:60;width:58px;height:58px;
  border-radius:18px;border:0;background:var(--accent);color:#fff;font-size:30px;line-height:1;cursor:pointer;
  box-shadow:0 8px 22px rgba(0,0,0,.25);align-items:center;justify-content:center}
.sheet-ov{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:500;display:none;align-items:center;justify-content:center;padding:16px}
.sheet-ov.on{display:flex}
.sheet{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:20px;width:500px;max-width:100%;
  max-height:calc(100vh - 32px);overflow-y:auto;display:flex;flex-direction:column;gap:14px;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.sheet>*{flex-shrink:0}   /* иначе на низком экране ряд быстрых кнопок сплющивается */
body.no-fab .fab{display:none!important}
.sheet-hd{display:flex;justify-content:space-between;align-items:center;gap:10px}
.sheet-hd b{font-size:17px;font-weight:800}
.seg{display:grid;grid-template-columns:1fr 1fr;gap:4px;background:var(--surface2);border-radius:12px;padding:4px}
.seg button{border:0;background:none;border-radius:9px;padding:9px;font-weight:600;font-size:14px;color:var(--muted);cursor:pointer;font-family:inherit}
.seg button.on{background:var(--accent);color:#fff}
.amt{display:flex;align-items:baseline;gap:8px;border:2px solid var(--accent);border-radius:12px;padding:8px 14px;background:var(--surface2)}
.amt input{border:0;background:none;font-size:30px;font-weight:800;width:100%;min-width:0;outline:none;color:var(--text);
  font-family:inherit;font-variant-numeric:tabular-nums}
.amt span{color:var(--muted);font-weight:600}
.chips{display:flex;flex-wrap:wrap;gap:6px}
.chip{border:1.5px solid var(--border);background:var(--surface2);color:var(--text);border-radius:20px;padding:7px 13px;
  font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;transition:.12s}
.chip:hover{border-color:var(--accent)}
.chip.on{background:var(--accent);border-color:var(--accent);color:#fff}
.chip.add{background:none;border-style:dashed;color:var(--muted)}
.chip.add:hover{color:var(--accent)}
.f2{display:grid;grid-template-columns:minmax(0,1fr) 160px;gap:8px}
.f2 .inp{width:100%;min-width:0;padding:11px 12px;font-size:14px}
.f-err{color:var(--red);font-size:13px;display:none}
.f-err.on{display:block}
.sheet-btns{display:flex;gap:8px}
.sheet-btns button{flex:1;padding:13px;border-radius:12px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;border:1px solid var(--border)}
.b-save{background:var(--accent);border-color:var(--accent)!important;color:#fff}
.b-save:disabled{opacity:.6;cursor:default}
.b-del{background:none;color:var(--red);border-color:var(--red)!important}

#toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--surface2);border:1px solid var(--border);border-radius:11px;
  padding:11px 16px;font-size:13px;z-index:999;display:none;align-items:center;gap:14px;box-shadow:0 8px 30px rgba(0,0,0,.5);max-width:calc(100% - 32px)}
#toast.on{display:flex}
#toast.err{border-color:var(--red);color:var(--red)}
#toast button{background:none;border:0;color:var(--accent);font-weight:700;cursor:pointer;font-family:inherit;font-size:13px;text-decoration:underline;flex-shrink:0}

@media(max-width:900px){
  .cols,.cols.half{grid-template-columns:1fr}
}
@media(max-width:768px){
  body{overflow-x:hidden}
  .ht-wrap{width:100%;max-width:100%;padding:16px 14px 96px}
  .site-head-in{padding:10px 14px;gap:8px}
  .ht-who{display:none}
  .lnk{padding:8px 11px;font-size:13px}
  .btn-add{display:none}
  .fab{display:flex}
  #toast{bottom:calc(88px + env(safe-area-inset-bottom,0px))}
}
@media(max-width:640px){
  .ht-wrap{padding:14px 12px 96px}
  .brand-sub{display:none}
  .kpi{grid-template-columns:1fr 1fr;gap:10px}
  .kpi.kpi-auto{grid-template-columns:1fr 1fr}
  .kpi-card{padding:13px 14px}
  .kpi-val{font-size:20px;letter-spacing:-.4px}
  .kpi-lbl,.kpi-sub{font-size:11px}
  .tab{padding:9px 13px;font-size:13.5px}
  .tab-x{display:none}
  .row{padding:8px 10px}
  .row-sub{padding-left:16px!important}
  .bud{width:84px}
  .btn-sub span{display:none}
  .sheet-ov{align-items:flex-end;padding:0}
  .sheet{border-radius:18px 18px 0 0;width:100%;max-height:92vh;padding:16px 16px calc(18px + env(safe-area-inset-bottom,0px))}
  .f2{grid-template-columns:minmax(0,1fr) 140px}
}
</style>

<header class="site-head">
  <div class="site-head-in">
    <a class="brand" href="/">
      <span class="brand-mark">I</span>
      <span>
        <span class="brand-name">Ikhlas</span>
        <span class="brand-sub">финансы</span>
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

  <div class="datebar">
    <button class="nav-btn" onclick="shiftMonth(-1)" aria-label="Предыдущий месяц">‹</button>
    <div class="date-main">
      <div class="date-big" id="m-title">—</div>
      <div class="date-sub" id="m-sub"></div>
    </div>
    <button class="nav-btn" onclick="shiftMonth(1)" aria-label="Следующий месяц">›</button>
    <button class="btn btn-add" onclick="openSheet()">+ Операция</button>
  </div>

  <div class="kpi">
    <div class="kpi-card"><div class="kpi-lbl">Расходы</div><div class="kpi-val" id="k-out">—</div><div class="kpi-sub" id="k-out-s"></div></div>
    <div class="kpi-card"><div class="kpi-lbl">Поступления</div><div class="kpi-val c-green" id="k-in">—</div><div class="kpi-sub" id="k-in-s"></div></div>
    <div class="kpi-card"><div class="kpi-lbl">Остаток</div><div class="kpi-val" id="k-bal">—</div><div class="kpi-sub" id="k-bal-s"></div></div>
    <div class="kpi-card"><div class="kpi-lbl" id="k-4-l">Прогноз на месяц</div><div class="kpi-val c-purple" id="k-4">—</div><div class="kpi-sub" id="k-4-s"></div></div>
  </div>

  <div class="tabs">
    <button class="tab active" data-t="overview" onclick="tab('overview')">Обзор</button>
    <button class="tab"        data-t="ops"      onclick="tab('ops')">Операции</button>
    <button class="tab"        data-t="family"   onclick="tab('family')">👪 Семья</button>
    <button class="tab"        data-t="reports"  onclick="tab('reports')">Отчёты</button>
    <button class="tab"        data-t="settings" onclick="tab('settings')">Категории<span class="tab-x"> и настройки</span></button>
  </div>

  <div id="t-overview"></div>
  <div id="t-ops" style="display:none"></div>
  <div id="t-family" style="display:none"></div>
  <div id="t-reports" style="display:none"></div>
  <div id="t-settings" style="display:none"></div>

</div>

<button class="fab" onclick="openSheet()" aria-label="Добавить операцию">+</button>

<!-- Ввод / правка операции -->
<div class="sheet-ov" id="sheet-ov" onclick="if(event.target===this)closeSheet()">
  <form class="sheet" id="sheet" autocomplete="off" onsubmit="saveTx(event)">
    <div class="sheet-hd"><b id="sh-title">Новая операция</b><button type="button" class="btn-x" onclick="closeSheet()" aria-label="Закрыть">✕</button></div>
    <div class="quick" id="sh-quick"></div>
    <div class="seg" role="group" aria-label="Тип">
      <button type="button" id="seg-out" onclick="setType('out')">Расход</button>
      <button type="button" id="seg-in"  onclick="setType('in')">Поступление</button>
    </div>
    <label class="amt"><input id="f-amount" inputmode="decimal" placeholder="0" aria-label="Сумма"><span>смн</span></label>
    <div><div class="sec-l">Категория</div><div class="chips" id="f-cats"></div></div>
    <div id="f-subwrap"><div class="sec-l">Подкатегория</div><div class="chips" id="f-subs"></div></div>
    <div id="f-pwrap"><div class="sec-l">Для кого</div><div class="chips" id="f-people"></div></div>
    <div class="f2">
      <input class="inp" id="f-note" placeholder="Комментарий" aria-label="Комментарий" maxlength="255">
      <input class="inp" id="f-date" type="date" aria-label="Дата">
    </div>
    <div class="f-err" id="f-err"></div>
    <div class="sheet-btns">
      <button type="button" class="b-del" id="f-del" onclick="delTx()">Удалить</button>
      <button class="b-save" id="f-save">Сохранить</button>
    </div>
  </form>
</div>

<div id="toast"><span id="toast-t"></span><button id="toast-b" type="button">Отменить</button></div>
<script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
<script>
const MO   = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const MO_S = ['Янв','Фев','Мар','Апр','Май','Июн','Июл','Авг','Сен','Окт','Ноя','Дек'];
const MO_G = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
const WD_F = ['воскресенье','понедельник','вторник','среда','четверг','пятница','суббота'];
const TABS = ['overview','ops','family','reports','settings'];

const $   = id=>document.getElementById(id);
const pad = n=>String(n).padStart(2,'0');
const iso = d=>d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
const today = ()=>iso(new Date());
const ymOf  = s=>s.slice(0,7);
const mAdd  = (m,k)=>{let y=+m.slice(0,4),mo=+m.slice(5)-1+k;y+=Math.floor(mo/12);mo=((mo%12)+12)%12;return y+'-'+pad(mo+1);};
const mName = m=>MO[+m.slice(5)-1]+' '+m.slice(0,4);
const dim   = m=>new Date(+m.slice(0,4),+m.slice(5),0).getDate();
const fmt   = n=>(Math.round(n*100)/100).toLocaleString('ru-RU');
const sum   = a=>a.reduce((s,t)=>s+(+t.amount||0),0);
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function parseAmount(v){return parseFloat(String(v).replace(/\s/g,'').replace(',','.'));}

function toast(m,err,undo){
  const t=$('toast'), b=$('toast-b');
  $('toast-t').textContent=m;
  t.className='on'+(err?' err':'');
  b.style.display=undo?'':'none';
  b.onclick=()=>{t.className='';undo();};
  clearTimeout(t._t);
  t._t=setTimeout(()=>t.className='',undo?6000:2600);
}

async function api(action, opts){
  opts=opts||{};
  const url='/api/finance.php?action='+action+(opts.params?('&'+opts.params):'');
  let r;
  try{
    r=await fetch(url,{method:opts.method||'GET',headers:opts.body?{'Content-Type':'application/json'}:{},body:opts.body?JSON.stringify(opts.body):undefined});
  }catch(e){ throw new Error('Нет связи с сервером'); }
  if(r.status===401){ location.href='/login.php'; throw new Error('Нужно войти'); }
  const j=await r.json().catch(()=>({ok:false,error:'Ошибка ответа'}));
  if(!j.ok) throw new Error(j.error||'Ошибка');
  return j;
}

// ── ДАННЫЕ ─────────────────────────────────────────────────────────────────
let cfg={cats:[],people:[],quick:[]}, cfgReady=false;
let tx=[], txReady=false;
let month=ymOf(today());
let curTab='overview';
let opF={cat:'',person:'',q:''};
try{ const t=localStorage.getItem('ikhlas_fin_tab'); if(TABS.includes(t)) curTab=t; }catch(e){}

// категории: parent_id = null — категория, иначе подкатегория
const catBy    = id=>cfg.cats.find(c=>c.id===+id);
const topId    = id=>{const c=catBy(id); return c ? (c.parent_id||c.id) : 0;};
const catName  = id=>catBy(id)?.name||'Без категории';
const tops     = type=>cfg.cats.filter(c=>!c.parent_id&&c.type===type&&c.active);
const subsOf   = pid=>cfg.cats.filter(c=>c.parent_id===+pid&&c.active);
const isSub    = id=>!!catBy(id)?.parent_id;
const personName = id=>cfg.people.find(p=>p.id===+id)?.name||'';
const people   = ()=>cfg.people.filter(p=>p.active);
const inMonth  = m=>tx.filter(t=>ymOf(t.date)===m);

function catLabel(id){
  const c=catBy(id);
  return c&&c.parent_id ? catName(c.parent_id)+' · '+c.name : catName(id);
}
function catHtml(id){
  const c=catBy(id);
  return c&&c.parent_id ? esc(catName(c.parent_id))+'<span class="op-sub"> · '+esc(c.name)+'</span>' : esc(catName(id));
}
// <option> категорий с подкатегориями под ними
function catOptions(type, sel){
  return tops(type).map(c=>
    `<option value="${c.id}" ${+sel===c.id?'selected':''}>${esc(c.name)}</option>`+
    subsOf(c.id).map(s=>`<option value="${s.id}" ${+sel===s.id?'selected':''}>&nbsp;&nbsp;&nbsp;↳ ${esc(s.name)}</option>`).join('')
  ).join('');
}

let loadSeq=0;
async function loadTx(){
  const seq=++loadSeq;
  const from=mAdd(month,-5)+'-01', to=mAdd(month,1)+'-01';   // 6 месяцев — для отчётов
  try{
    const j=await api('tx',{params:'from='+from+'&to='+to});
    if(seq!==loadSeq) return;
    tx=j.tx; txReady=true; render();
  }catch(e){ toast(e.message,true); }
}
async function loadCfg(){
  try{
    const j=await api('cfg');
    cfg={cats:j.cats,people:j.people,quick:j.quick}; cfgReady=true; render();
  }catch(e){ toast(e.message,true); }
}

// ── ОТРИСОВКА ──────────────────────────────────────────────────────────────
function tab(t){
  curTab=t;
  try{ localStorage.setItem('ikhlas_fin_tab',t); }catch(e){}
  render();
}
function shiftMonth(n){ month=mAdd(month,n); txReady=false; render(); loadTx(); }
function toNow(){ month=ymOf(today()); txReady=false; render(); loadTx(); }

function render(){
  $('m-title').textContent=mName(month);
  $('m-sub').innerHTML = month===ymOf(today()) ? 'текущий месяц' : '<a onclick="toNow()">← к текущему месяцу</a>';
  document.querySelectorAll('.tabs .tab').forEach(b=>b.classList.toggle('active',b.dataset.t===curTab));
  // на телефоне вкладки прокручиваются — активную держим в поле зрения
  const bar=document.querySelector('.tabs'), act=bar.querySelector('.tab.active');
  if(act.offsetLeft<bar.scrollLeft||act.offsetLeft+act.offsetWidth>bar.scrollLeft+bar.clientWidth) bar.scrollLeft=act.offsetLeft-12;
  TABS.forEach(x=>$('t-'+x).style.display=(x===curTab)?'':'none');
  document.body.classList.toggle('no-fab',curTab==='settings');
  renderKpi();
  if(!txReady||!cfgReady){ $('t-'+curTab).innerHTML='<div class="empty sm">Загрузка…</div>'; return; }
  ({overview:rOverview,ops:rOps,family:rFamily,reports:rReports,settings:rSettings})[curTab]();
}

// inv — для поступлений рост это хорошо
function deltaHtml(cur,prev,inv){
  if(!prev&&!cur) return '';
  if(!prev) return '<span class="delta same">новое</span>';
  const p=Math.round((cur-prev)/prev*100);
  if(p===0) return '<span class="delta same">= прошлому</span>';
  return `<span class="delta ${(p>0)!==!!inv?'up':'down'}" title="к прошлому месяцу">${p>0?'▲':'▼'} ${Math.abs(p)}%</span>`;
}

function renderKpi(){
  const set=(id,html)=>{$(id).innerHTML=html;};
  if(!txReady||!cfgReady){
    ['k-out','k-in','k-bal','k-4'].forEach(id=>set(id,'—'));
    ['k-out-s','k-in-s','k-bal-s','k-4-s'].forEach(id=>set(id,''));
    return;
  }
  const cur=inMonth(month), outs=cur.filter(t=>t.type==='out'), ins=cur.filter(t=>t.type==='in');
  const out=sum(outs), inc=sum(ins), bal=inc-out;
  const prevOut=sum(inMonth(mAdd(month,-1)).filter(t=>t.type==='out'));

  set('k-out',fmt(out));
  set('k-out-s', outs.length ? outs.length+' опер.'+(prevOut?' ·'+deltaHtml(out,prevOut)+' к прошлому':'') : 'расходов нет');
  set('k-in',fmt(inc));
  set('k-in-s', ins.length ? ins.length+' опер.' : 'поступлений нет');
  const kb=$('k-bal');
  kb.textContent=(bal>0?'+':'')+fmt(bal);
  kb.className='kpi-val '+(bal<0?'c-red':bal>0?'c-green':'');
  set('k-bal-s', !inc ? '' : bal>=0 ? 'отложено '+Math.round(bal/inc*100)+'% поступлений' : 'расходы больше поступлений');

  const isNow=month===ymOf(today()), D=dim(month), day=new Date().getDate();
  const budget=tops('out').reduce((s,c)=>s+(c.budget||0),0);
  if(isNow&&out>0){
    set('k-4-l','Прогноз на месяц');
    set('k-4',fmt(Math.round(out/day*D)));
    set('k-4-s', budget ? 'бюджет '+fmt(budget)+' · потрачено '+Math.round(out/budget*100)+'%' : '≈ '+fmt(Math.round(out/day))+' в день');
  }else if(budget){
    set('k-4-l','Бюджет месяца');
    set('k-4',Math.round(out/budget*100)+'%');
    set('k-4-s',fmt(out)+' из '+fmt(budget));
  }else{
    set('k-4-l','В среднем в день');
    set('k-4',fmt(Math.round(out/(isNow?day:D))));
    set('k-4-s',mName(month));
  }
}

function quickHtml(){
  if(!cfg.quick.length) return '';
  return `<div class="quick-wrap"><div class="sec-l">Быстрый ввод · одно нажатие — расход сегодня</div><div class="quick">${
    cfg.quick.map(q=>`<button class="qb" data-q="${q.id}"><span class="qb-t">${esc(q.label)}</span><span class="qb-a">${fmt(q.amount)} смн</span></button>`).join('')
  }</div></div>`;
}

// Суммы по категориям за месяц + разбивка по подкатегориям
function breakdown(type){
  const list=inMonth(month).filter(t=>t.type===type), prev=inMonth(mAdd(month,-1)).filter(t=>t.type===type);
  const total=sum(list);
  const byTop={}, bySub={}, prevTop={};
  list.forEach(t=>{
    const tid=topId(t.cat), sid=isSub(t.cat)?t.cat:0;
    byTop[tid]=(byTop[tid]||0)+t.amount;
    const m=bySub[tid]||(bySub[tid]={});
    m[sid]=(m[sid]||0)+t.amount;
  });
  prev.forEach(t=>{const k=topId(t.cat); prevTop[k]=(prevTop[k]||0)+t.amount;});

  const ids=new Set(Object.keys(byTop).map(Number));
  if(type==='out') tops('out').filter(c=>c.budget>0).forEach(c=>ids.add(c.id));   // лимит без трат тоже показываем
  const rows=[...ids].map(id=>({id,s:byTop[id]||0,b:type==='out'?(catBy(id)?.budget||0):0})).sort((a,b)=>b.s-a.s||b.b-a.b);
  const max=Math.max(1,...rows.map(r=>r.s));

  const alerts=[];
  const html=rows.map(r=>{
    let cls=type==='in'?'inc':'', w=r.s/max*100, sub;
    if(r.b){
      const p=r.s/r.b;
      w=Math.min(100,p*100);
      cls=p>=1?'bad':p>=.8?'warn':'';
      sub=`из ${fmt(r.b)} · осталось ${fmt(Math.max(0,r.b-r.s))}`;
      if(p>=1) alerts.push(['bad',`${catName(r.id)}: лимит превышен`,`${fmt(r.s)} / ${fmt(r.b)}`]);
      else if(p>=.8) alerts.push(['warn',`${catName(r.id)}: использовано ${Math.round(p*100)}% лимита`,`осталось ${fmt(r.b-r.s)}`]);
    }else{
      sub = total ? Math.round(r.s/total*100)+'% '+(type==='out'?'расходов':'поступлений') : '';
    }
    const parts=Object.entries(bySub[r.id]||{});
    let subs='';
    if(parts.some(([k])=>k!=='0')){
      parts.sort((a,b)=>(a[0]==='0')-(b[0]==='0')||b[1]-a[1]);
      subs=`<div class="it-subs">${parts.map(([k,v])=>`<div class="it-sub"><span>${k==='0'?'без подкатегории':esc(catName(k))}</span><span class="num">${fmt(v)}</span></div>`).join('')}</div>`;
    }
    return `<div class="it">
      <div class="it-top">
        <div><div class="it-n">${esc(catName(r.id))}</div><div class="it-s">${sub}</div></div>
        <div class="it-r"><span class="num">${fmt(r.s)}</span>${deltaHtml(r.s,prevTop[r.id]||0,type==='in')}</div>
      </div>
      <div class="track ${cls}"><i style="width:${w.toFixed(1)}%"></i></div>
      ${subs}
    </div>`;
  }).join('');
  return {html,alerts,total};
}

function rOverview(){
  const out=breakdown('out'), inc=breakdown('in');
  $('t-overview').innerHTML=`
    ${out.alerts.length?`<div class="alerts">${out.alerts.map(a=>`<div class="alert ${a[0]}"><span><b>${a[0]==='bad'?'⛔':'⚠'}</b> ${esc(a[1])}</span><span class="num">${a[2]}</span></div>`).join('')}</div>`:''}
    ${quickHtml()}
    <div class="cols">
      <div class="box">
        <div class="box-h"><b>Расходы по категориям</b><span>${mName(month)}</span></div>
        ${out.html||'<div class="empty"><div class="empty-ico">🧾</div>Расходов за этот месяц пока нет.<br>Нажмите «+ Операция», чтобы добавить первую.</div>'}
      </div>
      <div class="box">
        <div class="box-h"><b>Поступления</b><span>${mName(month)}</span></div>
        ${inc.html||'<div class="empty sm">Поступлений в этом месяце нет</div>'}
      </div>
    </div>`;
}

function opRow(t, withDate){
  const d=new Date(t.date+'T00:00:00');
  const note=[t.note||'', withDate?d.getDate()+' '+MO_G[d.getMonth()]:''].filter(Boolean).join(' · ');
  return `<button class="op" data-edit="${t.id}">
    <span class="op-c">${catHtml(t.cat)}${t.person?`<span class="tag">${esc(personName(t.person))}</span>`:''}</span>
    <span class="op-a num ${t.type==='in'?'in':''}">${t.type==='in'?'+':'−'}${fmt(t.amount)}</span>
    <span class="op-n">${esc(note)}</span>
  </button>`;
}

function rOps(){
  const v=$('t-ops');
  const keep=document.activeElement&&document.activeElement.id==='of-q';
  let list=inMonth(month);
  if(opF.cat){
    const c=catBy(opF.cat);
    // категория — вместе со всеми её подкатегориями
    list=list.filter(t=> c&&!c.parent_id ? topId(t.cat)===c.id : t.cat===+opF.cat);
  }
  if(opF.person) list=list.filter(t=>opF.person==='-'?!t.person:t.person===+opF.person);
  if(opF.q){ const q=opF.q.toLowerCase(); list=list.filter(t=>(t.note||'').toLowerCase().includes(q)||catLabel(t.cat).toLowerCase().includes(q)); }
  list.sort((a,b)=>b.date.localeCompare(a.date)||b.id-a.id);
  const days={}; list.forEach(t=>(days[t.date]=days[t.date]||[]).push(t));
  const to=sum(list.filter(t=>t.type==='out')), ti=sum(list.filter(t=>t.type==='in'));
  const ppl=people();

  v.innerHTML=`
    <div class="filters">
      <select class="inp" id="of-cat" aria-label="Категория">
        <option value="">Все категории</option>
        <optgroup label="Расходы">${catOptions('out',opF.cat)}</optgroup>
        <optgroup label="Поступления">${catOptions('in',opF.cat)}</optgroup>
      </select>
      ${ppl.length?`<select class="inp" id="of-person" aria-label="Для кого">
        <option value="">Все члены семьи</option>
        ${ppl.map(p=>`<option value="${p.id}" ${+opF.person===p.id?'selected':''}>${esc(p.name)}</option>`).join('')}
        <option value="-" ${opF.person==='-'?'selected':''}>Без указания</option>
      </select>`:''}
      <input class="inp" id="of-q" type="search" placeholder="Поиск по комментарию и категории" value="${esc(opF.q)}">
    </div>
    <div class="ops-sum">${list.length} опер. · расходы <b class="num">${fmt(to)}</b>${ti?` · поступления <b class="num">${fmt(ti)}</b>`:''}</div>
    ${Object.keys(days).length ? Object.entries(days).map(([d,ops])=>{
      const dt=new Date(d+'T00:00:00'), ds=sum(ops.filter(t=>t.type==='out'));
      return `<div class="day-h"><span>${dt.getDate()} ${MO_G[dt.getMonth()]}, ${WD_F[dt.getDay()]}</span><span class="num">${ds?'−'+fmt(ds):''}</span></div>
        <div class="box">${ops.map(t=>opRow(t)).join('')}</div>`;
    }).join('') : '<div class="box"><div class="empty sm">Нет операций по этому фильтру</div></div>'}`;

  $('of-cat').onchange=e=>{opF.cat=e.target.value;rOps();};
  if($('of-person')) $('of-person').onchange=e=>{opF.person=e.target.value;rOps();};
  $('of-q').oninput=e=>{opF.q=e.target.value;rOps();};
  if(keep){ const q=$('of-q'); q.focus(); q.setSelectionRange(q.value.length,q.value.length); }
}

function rFamily(){
  const v=$('t-family');
  const cur=inMonth(month).filter(t=>t.type==='out'), prev=inMonth(mAdd(month,-1)).filter(t=>t.type==='out');
  const ppl=cfg.people.filter(p=>p.active||cur.some(t=>t.person===p.id));
  if(!ppl.length){
    v.innerHTML='<div class="box"><div class="empty"><div class="empty-ico">👪</div>Добавьте членов семьи во вкладке «Категории и настройки» —<br>и здесь появятся расходы на каждого.</div></div>';
    return;
  }
  const out=sum(cur);
  const cards=ppl.map(p=>{
    const m=cur.filter(t=>t.person===p.id), s=sum(m), ps=sum(prev.filter(t=>t.person===p.id));
    const bc={}; m.forEach(t=>{const k=topId(t.cat); bc[k]=(bc[k]||0)+t.amount;});
    const notes={}; m.forEach(t=>{const n=(t.note||catLabel(t.cat)).trim(); notes[n]=(notes[n]||0)+t.amount;});
    const top=Object.entries(notes).sort((a,b)=>b[1]-a[1]).slice(0,3);
    return {s, html:`<div class="fam">
      <div class="fam-h"><b>${esc(p.name)}</b>${deltaHtml(s,ps)}</div>
      <div class="fam-s">${fmt(s)} <small>смн · ${m.length} опер.</small></div>
      ${s?`<ul>${Object.entries(bc).sort((a,b)=>b[1]-a[1]).map(([c,x])=>`<li><span>${esc(catName(c))}</span><span class="num">${fmt(x)}</span></li>`).join('')}</ul>
        <div class="fam-top">Больше всего: ${top.map(([n,x])=>esc(n)+' — '+fmt(x)).join('; ')}</div>`
        :'<div class="fam-top">В этом месяце расходов нет</div>'}
    </div>`};
  });
  const total=cards.reduce((a,c)=>a+c.s,0);
  const kidsCats=cfg.cats.filter(c=>!c.parent_id&&c.type==='out'&&/дет/i.test(c.name)).map(c=>c.id);
  const noName=sum(cur.filter(t=>!t.person&&kidsCats.includes(topId(t.cat))));
  v.innerHTML=`
    <div class="kpi kpi-auto">
      <div class="kpi-card"><div class="kpi-lbl">На членов семьи за месяц</div><div class="kpi-val">${fmt(total)}</div></div>
      <div class="kpi-card"><div class="kpi-lbl">Доля от всех расходов</div><div class="kpi-val">${out?Math.round(total/out*100):0}%</div></div>
      ${kidsCats.length?`<div class="kpi-card"><div class="kpi-lbl">«Дети» без имени</div><div class="kpi-val">${fmt(noName)}</div></div>`:''}
    </div>
    <div class="fam-grid">${cards.sort((a,b)=>b.s-a.s).map(c=>c.html).join('')}</div>
    <div class="note">Чтобы расход попал к человеку, выберите его в поле «Для кого» при вводе операции. Работает с любой категорией: продукты, одежда, школа.</div>`;
}

// ── ОТЧЁТЫ ─────────────────────────────────────────────────────────────────
// столбец: скругление 4px сверху, у оси — прямой угол
function barPath(x,y,w,h){
  if(h<=0) return '';
  const r=Math.min(4,w/2,h);
  return `M${x},${y+h}V${y+r}Q${x},${y} ${x+r},${y}H${x+w-r}Q${x+w},${y} ${x+w},${y+r}V${y+h}Z`;
}
function fmtAxis(v){
  if(v>=1e6) return fmt(v/1e6)+' млн';
  if(v>=1e4) return fmt(v/1e3)+' тыс';
  return fmt(v);
}

function rReports(){
  const v=$('t-reports');
  const months=[...Array(6)].map((_,i)=>mAdd(month,i-5));
  const data=months.map(m=>{const t=inMonth(m);return{m,out:sum(t.filter(x=>x.type==='out')),inc:sum(t.filter(x=>x.type==='in'))};});

  // график: расходы и поступления за 6 месяцев
  const max=Math.max(1,...data.map(d=>Math.max(d.out,d.inc)));
  const p10=Math.pow(10,Math.floor(Math.log10(max))), nn=max/p10;
  const top=(nn<=1?1:nn<=2?2:nn<=5?5:10)*p10;
  const W=640,H=240,L=60,R=10,T=12,B=30,cw=(W-L-R)/data.length,ih=H-T-B;
  const y=val=>T+ih-(val/top)*ih;
  const bw=Math.min(24,cw*.3), gap=2;
  let svg=`<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Расходы и поступления за 6 месяцев">`;
  for(let i=0;i<=4;i++){
    const val=top/4*i;
    svg+=`<line x1="${L}" x2="${W-R}" y1="${y(val)}" y2="${y(val)}" stroke="var(--border)"/>`+
         `<text x="${L-8}" y="${y(val)+4}" text-anchor="end" font-size="11" fill="var(--muted)">${fmtAxis(val)}</text>`;
  }
  data.forEach((d,i)=>{
    const x0=L+i*cw, cx=x0+cw/2, cur=d.m===month;
    svg+=`<rect class="hit" data-i="${i}" x="${x0}" y="${T}" width="${cw}" height="${ih}"/>`;
    svg+=`<path d="${barPath(cx-gap/2-bw,y(d.out),bw,T+ih-y(d.out))}" fill="var(--c-out)"/>`;
    svg+=`<path d="${barPath(cx+gap/2,y(d.inc),bw,T+ih-y(d.inc))}" fill="var(--c-in)"/>`;
    svg+=`<text x="${cx}" y="${H-9}" text-anchor="middle" font-size="12" fill="${cur?'var(--text)':'var(--muted)'}" font-weight="${cur?700:400}">${MO_S[+d.m.slice(5)-1]}</text>`;
  });
  svg+='</svg>';

  // таблица: категории → подкатегории по месяцам
  const topM={}, subM={}, dirM={};
  const add=(o,k,m,val)=>{const r=o[k]||(o[k]={}); r[m]=(r[m]||0)+val;};
  tx.filter(t=>t.type==='out'&&months.includes(ymOf(t.date))).forEach(t=>{
    const m=ymOf(t.date), tid=topId(t.cat);
    add(topM,tid,m,t.amount);
    if(isSub(t.cat)) add(subM,t.cat,m,t.amount); else add(dirM,tid,m,t.amount);
  });
  const byCur=o=>(a,b)=>(o[b][month]||0)-(o[a][month]||0);
  const avg=r=>{const xs=months.slice(0,5).map(m=>r[m]||0).filter(x=>x);return xs.length?xs.reduce((a,b)=>a+b,0)/xs.length:0;};
  const tr=(name,r,cls,flag)=>{
    const a=avg(r), c=r[month]||0, hi=flag&&a&&c>a*1.2;
    return `<tr class="${cls}"><td>${name}</td>${months.map(m=>{
      const val=r[m]||0, h=m===month&&hi;
      return `<td class="${m===month?'cur':''}${h?' hi':''}">${val?(h?'▲ ':'')+fmt(val):'·'}</td>`;
    }).join('')}<td class="muted">${a?fmt(Math.round(a)):'·'}</td></tr>`;
  };
  let rows='';
  Object.keys(topM).map(Number).sort(byCur(topM)).forEach(tid=>{
    rows+=tr(esc(catName(tid)),topM[tid],'',true);
    const sids=Object.keys(subM).map(Number).filter(sid=>catBy(sid)?.parent_id===tid).sort(byCur(subM));
    if(sids.length){
      sids.forEach(sid=>rows+=tr('↳ '+esc(catName(sid)),subM[sid],'sub',false));
      if(dirM[tid]) rows+=tr('↳ без подкатегории',dirM[tid],'sub',false);
    }
  });
  const totRow=(name,key)=>`<tr><td>${name}</td>${data.map(d=>{const val=key==='bal'?d.inc-d.out:d[key];return `<td class="${d.m===month?'cur':''}">${val?(key==='bal'&&val>0?'+':'')+fmt(val):'·'}</td>`;}).join('')}<td></td></tr>`;

  const big=inMonth(month).filter(t=>t.type==='out').sort((a,b)=>b.amount-a.amount).slice(0,7);

  v.innerHTML=`
    <div class="cols">
      <div class="box">
        <div class="box-h"><b>Последние 6 месяцев</b><span>наведите на месяц, чтобы увидеть суммы</span></div>
        <div class="chart" id="chart">${svg}<div class="tip" id="chart-tip"></div></div>
        <div class="legend"><span><i style="background:var(--c-out)"></i>Расходы</span><span><i style="background:var(--c-in)"></i>Поступления</span></div>
      </div>
      <div class="box">
        <div class="box-h"><b>Крупные траты</b><span>${mName(month)}</span></div>
        ${big.length?big.map(t=>opRow(t,true)).join(''):'<div class="empty sm">Нет расходов</div>'}
      </div>
    </div>
    <div class="box" style="margin-top:16px">
      <div class="box-h"><b>Расходы по категориям и месяцам</b><span>▲ — расход месяца выше среднего за 5 прошлых больше чем на 20%</span></div>
      <div class="rtbl"><table>
        <thead><tr><th>Категория</th>${months.map(m=>`<th class="${m===month?'cur':''}">${MO_S[+m.slice(5)-1]} ${m.slice(2,4)}</th>`).join('')}<th>Среднее</th></tr></thead>
        <tbody>${rows||`<tr><td colspan="${months.length+2}" style="text-align:center;color:var(--muted);position:static">Пока нет расходов</td></tr>`}</tbody>
        <tfoot>${totRow('Расходы','out')}${totRow('Поступления','inc')}${totRow('Остаток','bal')}</tfoot>
      </table></div>
    </div>`;

  // подсказка при наведении / нажатии на месяц
  const box=$('chart'), tip=$('chart-tip');
  box.querySelectorAll('.hit').forEach(r=>{
    const show=e=>{
      const d=data[+r.dataset.i];
      box.querySelectorAll('.hit.on').forEach(x=>x.classList.remove('on'));
      r.classList.add('on');
      tip.innerHTML=`<b>${mName(d.m)}</b>
        <div><i style="background:var(--c-out)"></i>Расходы<span class="num">${fmt(d.out)}</span></div>
        <div><i style="background:var(--c-in)"></i>Поступления<span class="num">${fmt(d.inc)}</span></div>
        <div class="muted">Остаток<span class="num">${(d.inc-d.out>0?'+':'')+fmt(d.inc-d.out)}</span></div>`;
      tip.style.display='block';
      const br=box.getBoundingClientRect();
      let x=e.clientX-br.left+box.scrollLeft+14, yy=e.clientY-br.top+14;
      const maxX=box.scrollLeft+box.clientWidth-tip.offsetWidth-6;
      if(x>maxX) x=e.clientX-br.left+box.scrollLeft-tip.offsetWidth-14;
      tip.style.left=Math.max(6,x)+'px';
      tip.style.top=Math.min(yy,box.clientHeight-tip.offsetHeight-6)+'px';
    };
    r.addEventListener('mousemove',show);
    r.addEventListener('click',show);
    r.addEventListener('mouseleave',()=>{tip.style.display='none';r.classList.remove('on');});
  });
}

// ── НАСТРОЙКИ: категории, подкатегории, семья, быстрые кнопки ───────────────
let openSub=0, settingsDirty=false;

function rSettings(force){
  const v=$('t-settings');
  const ae=document.activeElement;
  // не перерисовываем, пока человек что-то печатает
  if(!force&&v.contains(ae)&&/INPUT|SELECT/.test(ae.tagName)){ settingsDirty=true; return; }
  settingsDirty=false;

  const catBlock=c=>`<div class="cs">
    <div class="row">
      <input class="inp grow" value="${esc(c.name)}" data-cn="${c.id}" maxlength="60" aria-label="Название категории">
      ${c.type==='out'?`<input class="inp bud num" inputmode="decimal" value="${c.budget||''}" placeholder="лимит" data-cb="${c.id}" aria-label="Лимит на месяц">`:''}
      <button class="btn-sub ${openSub===c.id?'on':''}" data-addsub="${c.id}" title="Добавить подкатегорию">+<span> подкатегория</span></button>
      <button class="btn-x" data-cdel="${c.id}" aria-label="Удалить категорию">✕</button>
    </div>
    ${subsOf(c.id).map(s=>`<div class="row row-sub">
      <span class="sub-mark">↳</span>
      <input class="inp grow" value="${esc(s.name)}" data-cn="${s.id}" maxlength="60" aria-label="Название подкатегории">
      <button class="btn-x" data-cdel="${s.id}" aria-label="Удалить подкатегорию">✕</button>
    </div>`).join('')}
    ${openSub===c.id?`<div class="row row-sub">
      <span class="sub-mark">↳</span>
      <input class="inp grow" id="ns-inp" placeholder="Новая подкатегория в «${esc(c.name)}»" maxlength="60" data-enter="ns-btn">
      <button class="btn" id="ns-btn" data-nsadd="${c.id}">Добавить</button>
    </div>`:''}
  </div>`;

  const catBox=(type,title,sub)=>`<div class="box">
    <div class="box-h"><b>${title}</b><span>${sub}</span></div>
    ${tops(type).map(catBlock).join('')||'<div class="empty sm">Категорий пока нет</div>'}
    <div class="row row-add">
      <input class="inp grow" id="nc-${type}" placeholder="Новая категория ${type==='out'?'расходов':'поступлений'}" maxlength="60" data-enter="nc-${type}-b">
      <button class="btn" id="nc-${type}-b" data-cadd="${type}">+ Категория</button>
    </div>
  </div>`;

  const ppl=people();
  v.innerHTML=`<div class="cols half">
    <div class="stack">
      ${catBox('out','Категории расходов','и лимиты на месяц')}
      <div class="note" style="margin-top:-6px">«+ подкатегория» — добавить подкатегорию внутрь категории, например <b>Продукты → Хлеб</b>.
        При вводе операции сначала выбирается категория, потом подкатегория.
        Лимит необязателен: когда расход по категории дойдёт до 80%, на «Обзоре» появится предупреждение.
        Удалённая категория пропадает из выбора, но старые операции и отчёты её сохраняют.</div>
    </div>
    <div class="stack">
      ${catBox('in','Категории поступлений','откуда приходят деньги')}
      <div class="box">
        <div class="box-h"><b>Члены семьи</b><span>поле «Для кого» при вводе расхода</span></div>
        ${ppl.map(p=>`<div class="row">
          <input class="inp grow" value="${esc(p.name)}" data-pn="${p.id}" maxlength="60" aria-label="Имя">
          <button class="btn-x" data-pdel="${p.id}" aria-label="Удалить">✕</button>
        </div>`).join('')}
        <div class="row row-add">
          <input class="inp grow" id="np-name" placeholder="Имя" maxlength="60" data-enter="np-btn">
          <button class="btn" id="np-btn" data-padd="1">+ Добавить</button>
        </div>
      </div>
      <div class="box">
        <div class="box-h"><b>Кнопки быстрого ввода</b><span>одно нажатие — расход сегодня</span></div>
        ${cfg.quick.map(q=>`<div class="row">
          <span class="grow"><b>${esc(q.label)}</b> <span class="q-info">· ${esc(catLabel(q.cat))}${q.person?' · '+esc(personName(q.person)):''} · <span class="num">${fmt(q.amount)}</span></span></span>
          <button class="btn-x" data-qdel="${q.id}" aria-label="Удалить">✕</button>
        </div>`).join('')}
        <div class="row row-add">
          <input class="inp grow" id="nq-label" placeholder="Название, напр. «Хлеб»" maxlength="60">
          <input class="inp bud num" id="nq-amount" inputmode="decimal" placeholder="сумма">
        </div>
        <div class="row row-add" style="border-top:0;padding-top:0">
          <select class="inp grow" id="nq-cat" aria-label="Категория">${catOptions('out')}</select>
          ${ppl.length?`<select class="inp grow" id="nq-person" aria-label="Для кого"><option value="">Вся семья</option>${ppl.map(p=>`<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select>`:''}
          <button class="btn" data-qadd="1">+ Кнопка</button>
        </div>
      </div>
    </div>
  </div>`;
}

async function addCat(name,type,parentId){
  const j=await api('cat_add',{method:'POST',body:{name,type,parent_id:parentId||0}});
  cfg.cats.push({id:j.id,parent_id:parentId||null,type:j.type,name,budget:0,active:true});
  return j.id;
}

(function bindSettings(){
  const v=$('t-settings');

  v.addEventListener('keydown',e=>{
    const t=e.target;
    if(e.key==='Enter'&&t.dataset&&t.dataset.enter){ e.preventDefault(); const b=$(t.dataset.enter); if(b) b.click(); }
  });

  // правки применяем сразу (список может перерисоваться раньше ответа), при ошибке — откат
  v.addEventListener('change',async e=>{
    const t=e.target;
    if(t.dataset.cn){                                       // переименовать категорию / подкатегорию
      const c=catBy(t.dataset.cn), name=t.value.trim();
      if(!c) return;
      if(!name||name===c.name){ t.value=c.name; return; }
      const old=c.name; c.name=name;
      try{ await api('cat_save',{method:'POST',body:{id:c.id,name}}); toast('Сохранено'); }
      catch(x){ c.name=old; t.value=old; toast(x.message,true); }
    }
    if(t.dataset.cb){                                       // лимит на месяц
      const c=catBy(t.dataset.cb), b=Math.max(0,parseAmount(t.value)||0);
      if(!c) return;
      const old=c.budget; c.budget=b; t.value=b||'';
      try{ await api('cat_save',{method:'POST',body:{id:c.id,budget:b}}); toast(b?'Лимит сохранён':'Лимит убран'); }
      catch(x){ c.budget=old; t.value=old||''; toast(x.message,true); }
      renderKpi();
    }
    if(t.dataset.pn){                                       // переименовать человека
      const p=cfg.people.find(x=>x.id===+t.dataset.pn), name=t.value.trim();
      if(!p) return;
      if(!name||name===p.name){ t.value=p.name; return; }
      const old=p.name; p.name=name;
      try{ await api('person_save',{method:'POST',body:{id:p.id,name}}); toast('Сохранено'); }
      catch(x){ p.name=old; t.value=old; toast(x.message,true); }
    }
  });

  v.addEventListener('click',async e=>{
    const b=e.target.closest('button');
    if(!b) return;
    const d=b.dataset;
    try{
      if(d.addsub){                                         // открыть/закрыть поле новой подкатегории
        openSub = openSub===+d.addsub ? 0 : +d.addsub;
        rSettings(true);
        if(openSub) $('ns-inp').focus();
      }
      else if(d.nsadd){                                     // добавить подкатегорию
        const inp=$('ns-inp'), name=inp.value.trim();
        if(!name){ inp.focus(); return; }
        await addCat(name,null,+d.nsadd);
        toast('Подкатегория добавлена');
        rSettings(true);
        $('ns-inp').focus();                                // можно сразу вписать следующую
      }
      else if(d.cadd){                                      // добавить категорию
        const inp=$('nc-'+d.cadd), name=inp.value.trim();
        if(!name){ inp.focus(); return; }
        await addCat(name,d.cadd,0);
        toast('Категория добавлена');
        rSettings(true);
        $('nc-'+d.cadd).focus();
      }
      else if(d.cdel){                                      // удалить категорию / подкатегорию
        const c=catBy(d.cdel);
        if(!c) return;
        const subs=c.parent_id?[]:subsOf(c.id);
        let msg=(c.parent_id?'Удалить подкатегорию «':'Удалить категорию «')+c.name+'»?';
        if(subs.length) msg+='\nВместе с ней уберутся подкатегории: '+subs.map(s=>s.name).join(', ')+'.';
        if(!confirm(msg+'\n\nСтарые операции и отчёты сохранятся.')) return;
        await api('cat_del',{method:'DELETE',params:'id='+c.id});
        cfg.cats.forEach(x=>{ if(x.id===c.id||x.parent_id===c.id) x.active=false; });
        const gone=new Set(cfg.cats.filter(x=>!x.active).map(x=>x.id));
        cfg.quick=cfg.quick.filter(q=>!gone.has(q.cat));
        if(openSub===c.id) openSub=0;
        toast('Удалено');
        rSettings(true); renderKpi();
      }
      else if(d.padd){                                      // добавить человека
        const inp=$('np-name'), name=inp.value.trim();
        if(!name){ inp.focus(); return; }
        const j=await api('person_add',{method:'POST',body:{name}});
        cfg.people.push({id:j.id,name,active:true});
        toast('Добавлено');
        rSettings(true);
        $('np-name').focus();
      }
      else if(d.pdel){                                      // удалить человека
        const p=cfg.people.find(x=>x.id===+d.pdel);
        if(!p||!confirm('Убрать «'+p.name+'» из списка?\n\nСтарые операции сохранятся.')) return;
        await api('person_del',{method:'DELETE',params:'id='+p.id});
        p.active=false;
        toast('Убрано');
        rSettings(true);
      }
      else if(d.qadd){                                      // добавить быструю кнопку
        const label=$('nq-label').value.trim(), amount=parseAmount($('nq-amount').value);
        const cat=+$('nq-cat').value, person=$('nq-person')?+$('nq-person').value:0;
        if(!label||!(amount>0)){ toast('Укажите название и сумму',true); return; }
        const j=await api('quick_add',{method:'POST',body:{label,amount,cat,person}});
        cfg.quick.push({id:j.id,label,amount:Math.round(amount*100)/100,cat,person:person||null,note:label});
        toast('Кнопка добавлена');
        rSettings(true);
      }
      else if(d.qdel){                                      // удалить быструю кнопку
        await api('quick_del',{method:'DELETE',params:'id='+d.qdel});
        cfg.quick=cfg.quick.filter(q=>q.id!==+d.qdel);
        rSettings(true);
      }
    }catch(x){ toast(x.message,true); }
  });

  // отложенная перерисовка после ввода
  document.addEventListener('focusout',()=>{
    if(settingsDirty) setTimeout(()=>{ if(settingsDirty&&!v.contains(document.activeElement)&&curTab==='settings') rSettings(); },0);
  });
})();

// ── ВВОД ОПЕРАЦИИ ──────────────────────────────────────────────────────────
// cat — категория верхнего уровня, sub — подкатегория (0 = без неё)
let F={id:null,type:'out',cat:0,sub:0,person:0,orig:null};

// часто используемые — первыми; удалённая категория правимой операции тоже в списке
function freqSort(list, key){
  const freq={}; tx.forEach(t=>{const k=key(t); if(k) freq[k]=(freq[k]||0)+1;});
  return list.sort((a,b)=>(freq[b.id]||0)-(freq[a.id]||0));
}
function catChoices(){
  const list=tops(F.type), c=catBy(F.cat);
  if(c&&!c.active&&!c.parent_id&&c.type===F.type) list.push(c);
  return freqSort(list, t=>t.type===F.type?topId(t.cat):0);
}
function subChoices(){
  if(!F.cat) return [];
  const list=subsOf(F.cat), s=catBy(F.sub);
  if(s&&!s.active&&s.parent_id===F.cat) list.push(s);
  return freqSort(list, t=>isSub(t.cat)?t.cat:0);
}

function paintForm(){
  $('seg-out').classList.toggle('on',F.type==='out');
  $('seg-in').classList.toggle('on',F.type==='in');

  const cats=catChoices();
  if(!cats.find(c=>c.id===F.cat)){ F.cat=cats[0]?.id||0; F.sub=0; }
  $('f-cats').innerHTML=cats.map(c=>`<button type="button" class="chip ${c.id===F.cat?'on':''}" data-fc="${c.id}">${esc(c.name)}</button>`).join('')+
    `<button type="button" class="chip add" data-newcat="1" title="Новая категория">+ категория</button>`;

  const subs=subChoices();
  if(F.sub&&!subs.find(s=>s.id===F.sub)) F.sub=0;
  $('f-subwrap').style.display=F.cat?'':'none';
  $('f-subs').innerHTML=(subs.length?`<button type="button" class="chip ${!F.sub?'on':''}" data-fs="0">Без подкатегории</button>`:'')+
    subs.map(s=>`<button type="button" class="chip ${s.id===F.sub?'on':''}" data-fs="${s.id}">${esc(s.name)}</button>`).join('')+
    `<button type="button" class="chip add" data-newsub="1" title="Новая подкатегория">+ подкатегория</button>`;

  const ppl=people(), cp=cfg.people.find(p=>p.id===F.person);
  if(cp&&!cp.active) ppl.push(cp);
  $('f-pwrap').style.display=F.type==='out'&&ppl.length?'':'none';
  $('f-people').innerHTML=`<button type="button" class="chip ${!F.person?'on':''}" data-fp="0">Вся семья</button>`+
    ppl.map(p=>`<button type="button" class="chip ${p.id===F.person?'on':''}" data-fp="${p.id}">${esc(p.name)}</button>`).join('');

  const q=$('sh-quick');
  q.style.display=!F.id&&cfg.quick.length?'':'none';
  q.innerHTML=!F.id?cfg.quick.map(x=>`<button type="button" class="qb" data-q="${x.id}"><span class="qb-t">${esc(x.label)}</span><span class="qb-a">${fmt(x.amount)} смн</span></button>`).join(''):'';
}

function openSheet(t){
  if(!cfgReady){ toast('Загрузка…'); return; }
  F = t ? {id:t.id,type:t.type,cat:topId(t.cat),sub:isSub(t.cat)?t.cat:0,person:t.person||0,orig:t}
        : {id:null,type:'out',cat:0,sub:0,person:0,orig:null};
  $('sh-title').textContent=t?'Изменить операцию':'Новая операция';
  $('f-amount').value=t?t.amount:'';
  $('f-note').value=t?.note||'';
  $('f-date').value=t?t.date:(month===ymOf(today())?today():month+'-01');
  const del=$('f-del');
  del.style.display=t?'':'none'; del.textContent='Удалить'; delete del.dataset.armed;
  $('f-err').className='f-err';
  $('f-save').disabled=false;
  paintForm();
  $('sheet-ov').classList.add('on');
  setTimeout(()=>$('f-amount').focus(),50);
}
function closeSheet(){ $('sheet-ov').classList.remove('on'); }
function setType(type){ if(F.type===type) return; F.type=type; F.cat=0; F.sub=0; if(type==='in') F.person=0; paintForm(); }
function fErr(m){ const e=$('f-err'); e.textContent=m; e.className='f-err on'; }

$('sheet').addEventListener('click',async e=>{
  const b=e.target.closest('button');
  if(!b) return;
  const d=b.dataset;
  if(d.fc){ F.cat=+d.fc; F.sub=0; paintForm(); }
  else if(d.fs!==undefined){ F.sub=+d.fs; paintForm(); }
  else if(d.fp!==undefined){ F.person=+d.fp; paintForm(); }
  else if(d.newcat){
    const name=(prompt('Новая категория '+(F.type==='out'?'расходов':'поступлений'))||'').trim();
    if(!name) return;
    try{ F.cat=await addCat(name,F.type,0); F.sub=0; paintForm(); }catch(x){ toast(x.message,true); }
  }
  else if(d.newsub){
    if(!F.cat) return;
    const name=(prompt('Новая подкатегория в «'+catName(F.cat)+'»')||'').trim();
    if(!name) return;
    try{ F.sub=await addCat(name,null,F.cat); paintForm(); }catch(x){ toast(x.message,true); }
  }
});

function goMonthOf(date){
  if(ymOf(date)!==month){ month=ymOf(date); txReady=false; render(); }
  loadTx();
}

async function saveTx(e){
  e.preventDefault();
  const a=parseAmount($('f-amount').value);
  if(!(a>0)) return fErr('Введите сумму больше нуля');
  if(!F.cat) return fErr('Выберите категорию');
  const date=$('f-date').value||today();
  const body={id:F.id||undefined,date,amount:Math.round(a*100)/100,cat:F.sub||F.cat,
              person:F.type==='out'&&F.person?F.person:null,note:$('f-note').value.trim()};
  $('f-save').disabled=true;
  try{
    const r=await api('tx_save',{method:'POST',body});
    closeSheet();
    if(F.id) toast('Изменения сохранены');
    else toast('Записано: '+catLabel(body.cat)+' '+fmt(body.amount),false,async()=>{
      try{ await api('tx_del',{method:'DELETE',params:'id='+r.id}); }catch(x){ toast(x.message,true); }
      loadTx();
    });
    goMonthOf(date);
  }catch(x){ $('f-save').disabled=false; fErr('Не сохранилось: '+x.message); }
}

async function delTx(){
  const b=$('f-del');
  if(!b.dataset.armed){ b.dataset.armed=1; b.textContent='Точно удалить?'; return; }
  const o=F.orig;
  try{
    await api('tx_del',{method:'DELETE',params:'id='+F.id});
    closeSheet(); loadTx();
    toast('Операция удалена',false,async()=>{
      try{ await api('tx_save',{method:'POST',body:{date:o.date,amount:o.amount,cat:o.cat,person:o.person,note:o.note}}); }catch(x){ toast(x.message,true); }
      loadTx();
    });
  }catch(x){ fErr('Не удалилось: '+x.message); }
}

async function quickAdd(qid){
  const q=cfg.quick.find(x=>x.id===qid);
  if(!q) return;
  const body={date:today(),amount:q.amount,cat:q.cat,person:q.person,note:q.note||q.label};
  try{
    const r=await api('tx_save',{method:'POST',body});
    closeSheet();
    toast('Записано: '+q.label+' '+fmt(q.amount),false,async()=>{
      try{ await api('tx_del',{method:'DELETE',params:'id='+r.id}); }catch(x){ toast(x.message,true); }
      loadTx();
    });
    goMonthOf(body.date);
  }catch(x){ toast('Не сохранилось: '+x.message,true); }
}

// ── EXCEL ──────────────────────────────────────────────────────────────────
async function exportXlsx(){
  try{
    const j=await api('export');
    if(!j.tx.length){ toast('Операций пока нет',true); return; }
    const rows=j.tx.map(t=>{
      const c=catBy(t.cat);
      return {
        'Дата':         t.date,
        'Тип':          t.type==='in'?'Поступление':'Расход',
        'Категория':    c&&c.parent_id ? catName(c.parent_id) : catName(t.cat),
        'Подкатегория': c&&c.parent_id ? c.name : '',
        'Для кого':     t.person ? personName(t.person) : '',
        'Сумма':        t.amount,
        'Комментарий':  t.note||'',
      };
    });
    const ws=XLSX.utils.json_to_sheet(rows);
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Операции');
    XLSX.writeFile(wb, `Finance_${today()}.xlsx`);
    toast('Экспортировано');
  }catch(e){ toast(e.message,true); }
}

// ── СОБЫТИЯ ────────────────────────────────────────────────────────────────
document.addEventListener('click',e=>{
  const q=e.target.closest('[data-q]');
  if(q){ quickAdd(+q.dataset.q); return; }
  const ed=e.target.closest('[data-edit]');
  if(ed){ const t=tx.find(x=>x.id===+ed.dataset.edit); if(t) openSheet(t); }
});
document.addEventListener('keydown',e=>{ if(e.key==='Escape'&&$('sheet-ov').classList.contains('on')) closeSheet(); });
// вернулся во вкладку (например, внёс расход с телефона) — подтягиваем свежие данные
document.addEventListener('visibilitychange',()=>{
  if(!document.hidden&&!$('sheet-ov').classList.contains('on')){ loadCfg(); loadTx(); }
});

render();
loadCfg();
loadTx();
</script>
</body>
</html>
