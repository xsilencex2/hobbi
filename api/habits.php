<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Отдельный сайт: логин на этом сайте уже не пускает роль client (см. login.php)
requireAuth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$UID    = (int)($_SESSION['user_id'] ?? 0);

// ── ENSURE TABLES ─────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS habit_defs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    name       VARCHAR(80)  NOT NULL,
    category   VARCHAR(60)  NOT NULL DEFAULT 'Другое',
    sort_order INT NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    ask_qty    TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = после «Да» спросить «сколько?»
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Привычки, у которых важно КОЛИЧЕСТВО (остальные — просто Да/Нет)
const HABITS_WITH_QTY = ['Нафл', 'Коран', 'Чтение'];

// колонка добавлена позже — дозаливаем и проставляем этим трём
$c = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_defs' AND COLUMN_NAME = 'ask_qty'");
$c->execute();
if (!$c->fetchColumn()) {
    $pdo->exec("ALTER TABLE habit_defs ADD COLUMN ask_qty TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    $in = implode(',', array_fill(0, count(HABITS_WITH_QTY), '?'));
    $pdo->prepare("UPDATE habit_defs SET ask_qty = 1 WHERE name IN ($in)")->execute(HABITS_WITH_QTY);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS habit_logs (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id  INT NOT NULL,
    habit_id INT NOT NULL,
    log_date DATE NOT NULL,
    status   VARCHAR(10) NOT NULL DEFAULT 'miss',   -- done (Да) | miss (Нет)
    qty      INT NOT NULL DEFAULT 0,                -- сколько раз/единиц (при «Да»)
    UNIQUE KEY uq_log (user_id, habit_id, log_date),
    INDEX idx_user_date (user_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// колонка qty добавлена позже — дозаливаем
$c = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'habit_logs' AND COLUMN_NAME = 'qty'");
$c->execute();
if (!$c->fetchColumn()) $pdo->exec("ALTER TABLE habit_logs ADD COLUMN qty INT NOT NULL DEFAULT 0 AFTER status");

$pdo->exec("CREATE TABLE IF NOT EXISTS habit_days (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    user_id  INT NOT NULL,
    log_date DATE NOT NULL,
    mood     VARCHAR(20)  NOT NULL DEFAULT '',
    comment  VARCHAR(500) NOT NULL DEFAULT '',
    UNIQUE KEY uq_day (user_id, log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── БАЗОВЫЙ НАБОР ПРИВЫЧЕК (как в HabitTracker_2026) ──────────────────────────
const BASE_HABITS = [
    ['Нафл',       '🕌 Ислам'],
    ['Фаджр',      '🕌 Ислам'],
    ['Зухр',       '🕌 Ислам'],
    ['Аср',        '🕌 Ислам'],
    ['Магриб',     '🕌 Ислам'],
    ['Иша',        '🕌 Ислам'],
    ['Коран',      '🕌 Ислам'],
    ['Азкары',     '🕌 Ислам'],
    ['Упражнение', '💪 Здоровье'],
    ['Прогулка',   '💪 Здоровье'],
    ['Сон 8ч',     '💪 Здоровье'],
    ['Чтение',     '📚 Обучение'],
    ['Английский', '📚 Обучение'],
    ['Работа',     '💼 Бизнес'],
    ['Маркетинг',  '💼 Бизнес'],
    ['Продажи',    '💼 Бизнес'],
    ['Финансы',    '💰 Финансы'],
    ['Семья',      '👪 Семья'],
];

// Первый заход — засеваем базовый набор
function seedIfEmpty(PDO $pdo, int $uid): void {
    $has = (int)$pdo->query("SELECT COUNT(*) FROM habit_defs WHERE user_id = $uid")->fetchColumn();
    if ($has > 0) return;
    $ins = $pdo->prepare("INSERT INTO habit_defs (user_id, name, category, sort_order, ask_qty) VALUES (?,?,?,?,?)");
    $i = 0;
    foreach (BASE_HABITS as [$name, $cat]) {
        // Нафл / Коран / Чтение — спрашиваем «сколько?», остальные просто Да/Нет
        $ask = in_array($name, HABITS_WITH_QTY, true) ? 1 : 0;
        $ins->execute([$uid, $name, $cat, $i++, $ask]);
    }
}

function activeHabits(PDO $pdo, int $uid): array {
    $st = $pdo->prepare("SELECT id, name, category, sort_order, ask_qty FROM habit_defs
                         WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['ask_qty'] = (int)$r['ask_qty'];
    unset($r);
    return $rows;
}

// Балл статуса: done=1, partial=0.5, miss=0, off — не считается
function pts(string $s): float { return $s === 'done' ? 1.0 : ($s === 'partial' ? 0.5 : 0.0); }

/**
 * % дня = набранные баллы / (активных привычек − выходных).
 * Незаполненная привычка = 0 баллов, но В знаменателе (иначе можно накрутить,
 * отметив одну привычку и получив 100%).
 */
function dayPct(float $p, int $offCnt, int $habitCnt): float {
    $den = $habitCnt - $offCnt;
    return $den > 0 ? round($p / $den * 100, 1) : 0.0;
}

function validStatus(string $s): bool { return in_array($s, ['done','partial','miss','off'], true); }
function validDate(string $d): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); }

// ══════════════════════════════════════════════════════════════════════════════

// ── DAY: привычки + статусы + настроение/комментарий ──────────────────────────
if ($action === 'day' && $method === 'GET') {
    seedIfEmpty($pdo, $UID);
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!validDate($date)) jsonErr('Неверная дата');

    $habits = activeHabits($pdo, $UID);

    $st = $pdo->prepare("SELECT habit_id, status, qty FROM habit_logs WHERE user_id = ? AND log_date = ?");
    $st->execute([$UID, $date]);
    $logs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $logs[$r['habit_id']] = ['status' => $r['status'], 'qty' => (int)$r['qty']];
    }

    $st = $pdo->prepare("SELECT mood, comment FROM habit_days WHERE user_id = ? AND log_date = ?");
    $st->execute([$UID, $date]);
    $day = $st->fetch(PDO::FETCH_ASSOC) ?: ['mood' => '', 'comment' => ''];

    $p = 0.0; $off = 0;
    foreach ($habits as &$h) {
        $l = $logs[$h['id']] ?? null;
        $s = $l['status'] ?? '';
        $h['status'] = $s;
        $h['qty']    = $l['qty'] ?? 0;
        if ($s === 'off') $off++; else $p += pts($s);
    }
    unset($h);

    jsonOk([
        'date'    => $date,
        'habits'  => $habits,
        'mood'    => $day['mood'],
        'comment' => $day['comment'],
        'points'  => $p,
        'pct'     => dayPct($p, $off, count($habits)),
    ]);
}

// ── SET: отметить привычку ────────────────────────────────────────────────────
if ($action === 'set' && $method === 'POST') {
    $d      = json_decode(file_get_contents('php://input'), true) ?? [];
    $hid    = (int)($d['habit_id'] ?? 0);
    $date   = (string)($d['date'] ?? '');
    $status = (string)($d['status'] ?? '');
    $qty    = max(0, (int)($d['qty'] ?? 0));      // сколько раз (при «Да»)

    if (!$hid || !validDate($date)) jsonErr('Неверные параметры');
    if (!validStatus($status) && $status !== '') jsonErr('Неверный статус');

    // привычка должна принадлежать этому пользователю
    $own = $pdo->prepare("SELECT 1 FROM habit_defs WHERE id = ? AND user_id = ?");
    $own->execute([$hid, $UID]);
    if (!$own->fetchColumn()) jsonErr('Привычка не найдена', 404);

    if ($status === '') {
        $pdo->prepare("DELETE FROM habit_logs WHERE user_id=? AND habit_id=? AND log_date=?")
            ->execute([$UID, $hid, $date]);
    } else {
        if ($status !== 'done') $qty = 0;         // «Нет» — количества нет
        elseif ($qty < 1)       $qty = 1;         // «Да» без числа = 1 раз
        $pdo->prepare("INSERT INTO habit_logs (user_id, habit_id, log_date, status, qty) VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE status = VALUES(status), qty = VALUES(qty)")
            ->execute([$UID, $hid, $date, $status, $qty]);
    }

    // пересчёт дня
    $habits = activeHabits($pdo, $UID);
    $st = $pdo->prepare("SELECT habit_id, status FROM habit_logs WHERE user_id = ? AND log_date = ?");
    $st->execute([$UID, $date]);
    $logs = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $p = 0.0; $off = 0;
    foreach ($habits as $h) {
        $s = $logs[$h['id']] ?? '';
        if ($s === 'off') $off++; else $p += pts($s);
    }
    jsonOk(['points' => $p, 'pct' => dayPct($p, $off, count($habits))]);
}

// ── SAVE DAY: настроение + комментарий ────────────────────────────────────────
if ($action === 'save_day' && $method === 'POST') {
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $date = (string)($d['date'] ?? '');
    if (!validDate($date)) jsonErr('Неверная дата');
    $pdo->prepare("INSERT INTO habit_days (user_id, log_date, mood, comment) VALUES (?,?,?,?)
                   ON DUPLICATE KEY UPDATE mood = VALUES(mood), comment = VALUES(comment)")
        ->execute([
            $UID, $date,
            mb_substr(trim((string)($d['mood'] ?? '')), 0, 20),
            mb_substr(trim((string)($d['comment'] ?? '')), 0, 500),
        ]);
    jsonOk();
}

// ── HABITS: список / добавить / удалить ───────────────────────────────────────
if ($action === 'habits' && $method === 'GET') {
    seedIfEmpty($pdo, $UID);
    jsonOk(['habits' => activeHabits($pdo, $UID)]);
}

if ($action === 'add_habit' && $method === 'POST') {
    $d    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim((string)($d['name'] ?? ''));
    $cat  = trim((string)($d['category'] ?? '')) ?: 'Другое';
    if ($name === '') jsonErr('Введите название привычки');

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM habit_defs WHERE user_id = $UID AND is_active = 1")->fetchColumn();
    if ($cnt >= 40) jsonErr('Максимум 40 привычек');

    $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM habit_defs WHERE user_id = $UID")->fetchColumn();
    $askQty = !empty($d['ask_qty']) ? 1 : 0;
    $pdo->prepare("INSERT INTO habit_defs (user_id, name, category, sort_order, ask_qty) VALUES (?,?,?,?,?)")
        ->execute([$UID, mb_substr($name, 0, 80), mb_substr($cat, 0, 60), $max + 1, $askQty]);
    jsonOk(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'del_habit' && $method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonErr('Не указан id');
    // мягкое удаление — история отметок сохраняется
    $pdo->prepare("UPDATE habit_defs SET is_active = 0 WHERE id = ? AND user_id = ?")->execute([$id, $UID]);
    jsonOk();
}

// ── MONTH: сетка «дни × привычки» ─────────────────────────────────────────────
if ($action === 'month' && $method === 'GET') {
    seedIfEmpty($pdo, $UID);
    $ym = $_GET['ym'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) jsonErr('Неверный месяц');
    $from = $ym . '-01';
    $to   = date('Y-m-t', strtotime($from));

    $habits = activeHabits($pdo, $UID);

    $st = $pdo->prepare("SELECT log_date, habit_id, status, qty FROM habit_logs
                         WHERE user_id = ? AND log_date BETWEEN ? AND ?");
    $st->execute([$UID, $from, $to]);
    $grid = [];   // date => {habit_id: {status, qty}}
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $grid[$r['log_date']][$r['habit_id']] = ['s' => $r['status'], 'q' => (int)$r['qty']];
    }

    $st = $pdo->prepare("SELECT log_date, mood, comment FROM habit_days
                         WHERE user_id = ? AND log_date BETWEEN ? AND ?");
    $st->execute([$UID, $from, $to]);
    $days = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $days[$r['log_date']] = ['mood' => $r['mood'], 'comment' => $r['comment']];
    }

    jsonOk(['ym' => $ym, 'from' => $from, 'to' => $to,
            'habits' => $habits, 'grid' => $grid, 'days' => $days]);
}

// ── YEAR: % по каждому дню (тепловая карта) ───────────────────────────────────
if ($action === 'year' && $method === 'GET') {
    seedIfEmpty($pdo, $UID);
    $year = (int)($_GET['year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) jsonErr('Неверный год');
    $hCnt = count(activeHabits($pdo, $UID));

    $st = $pdo->prepare("SELECT log_date,
            SUM(CASE status WHEN 'done' THEN 1 WHEN 'partial' THEN 0.5 ELSE 0 END) AS p,
            SUM(CASE WHEN status = 'off' THEN 1 ELSE 0 END) AS off_cnt
        FROM habit_logs
        WHERE user_id = ? AND log_date BETWEEN ? AND ?
        GROUP BY log_date");
    $st->execute([$UID, "$year-01-01", "$year-12-31"]);

    $days = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $days[$r['log_date']] = dayPct((float)$r['p'], (int)$r['off_cnt'], $hCnt);
    }
    jsonOk(['year' => $year, 'days' => $days, 'habit_count' => $hCnt]);
}

// ── STATS: средний %, серия, идеальные дни ────────────────────────────────────
if ($action === 'stats' && $method === 'GET') {
    seedIfEmpty($pdo, $UID);
    $ym   = $_GET['ym'] ?? date('Y-m');
    $from = $ym . '-01';
    $to   = date('Y-m-t', strtotime($from));
    $hCnt = count(activeHabits($pdo, $UID));

    $st = $pdo->prepare("SELECT log_date,
            SUM(CASE status WHEN 'done' THEN 1 WHEN 'partial' THEN 0.5 ELSE 0 END) AS p,
            SUM(CASE WHEN status = 'off' THEN 1 ELSE 0 END) AS off_cnt
        FROM habit_logs WHERE user_id = ? AND log_date BETWEEN ? AND ?
        GROUP BY log_date ORDER BY log_date");
    $st->execute([$UID, $from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $sum = 0.0; $n = 0; $perfect = 0;
    $pctByDate = [];
    foreach ($rows as $r) {
        $pct = dayPct((float)$r['p'], (int)$r['off_cnt'], $hCnt);
        $pctByDate[$r['log_date']] = $pct;
        $sum += $pct; $n++;
        if ($pct >= 100) $perfect++;
    }

    // серия: сколько дней подряд ≥50%, считая назад от сегодня
    $streak = 0;
    $cur = new DateTime(date('Y-m-d'));
    for ($i = 0; $i < 400; $i++) {
        $key = $cur->format('Y-m-d');
        $stq = $pdo->prepare("SELECT
                SUM(CASE status WHEN 'done' THEN 1 WHEN 'partial' THEN 0.5 ELSE 0 END) AS p,
                SUM(CASE WHEN status = 'off' THEN 1 ELSE 0 END) AS off_cnt,
                COUNT(*) AS c
            FROM habit_logs WHERE user_id = ? AND log_date = ?");
        $stq->execute([$UID, $key]);
        $r = $stq->fetch(PDO::FETCH_ASSOC);
        if (!$r || (int)$r['c'] === 0) break;
        if (dayPct((float)$r['p'], (int)$r['off_cnt'], $hCnt) < 50) break;
        $streak++;
        $cur->modify('-1 day');
    }

    jsonOk([
        'ym' => $ym,
        'avg' => $n ? round($sum / $n, 1) : 0,
        'days_tracked' => $n,
        'perfect' => $perfect,
        'streak' => $streak,
        'habit_count' => $hCnt,
    ]);
}

// ── LEADERBOARD: рейтинг за месяц (геймификация) ──────────────────────────────
if ($action === 'leaderboard' && $method === 'GET') {
    $ym   = $_GET['ym'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) jsonErr('Неверный месяц');
    $from = $ym . '-01';
    $to   = date('Y-m-t', strtotime($from));

    // активных привычек у каждого
    $hCnt = $pdo->query("SELECT user_id, COUNT(*) c FROM habit_defs
                         WHERE is_active = 1 GROUP BY user_id")->fetchAll(PDO::FETCH_KEY_PAIR);

    // баллы по дням у каждого
    $st = $pdo->prepare("SELECT user_id, log_date,
            SUM(CASE status WHEN 'done' THEN 1 WHEN 'partial' THEN 0.5 ELSE 0 END) AS p,
            SUM(CASE WHEN status = 'off' THEN 1 ELSE 0 END) AS off_cnt
        FROM habit_logs WHERE log_date BETWEEN ? AND ?
        GROUP BY user_id, log_date");
    $st->execute([$from, $to]);

    $agg = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['user_id'];
        $cnt = (int)($hCnt[$uid] ?? 0);
        if ($cnt <= 0) continue;
        $pct = dayPct((float)$r['p'], (int)$r['off_cnt'], $cnt);
        if (!isset($agg[$uid])) $agg[$uid] = ['sum' => 0.0, 'days' => 0, 'perfect' => 0];
        $agg[$uid]['sum'] += $pct;
        $agg[$uid]['days']++;
        if ($pct >= 100) $agg[$uid]['perfect']++;
    }
    if (!$agg) jsonOk(['ym' => $ym, 'rows' => [], 'me' => $UID]);

    $ids  = implode(',', array_map('intval', array_keys($agg)));
    $users = $pdo->query("SELECT id, email, name FROM google_users WHERE id IN ($ids)")
                 ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($agg as $uid => $a) {
        $rows[] = [
            'user_id' => $uid,
            'name'    => $users[$uid]['name'] ?? ($users[$uid]['email'] ?? 'Пользователь'),
            'avg'     => round($a['sum'] / $a['days'], 1),
            'days'    => $a['days'],
            'perfect' => $a['perfect'],
        ];
    }
    usort($rows, fn($x, $y) => $y['avg'] <=> $x['avg'] ?: $y['days'] <=> $x['days']);
    foreach ($rows as $i => &$r) $r['rank'] = $i + 1;
    unset($r);

    jsonOk(['ym' => $ym, 'rows' => $rows, 'me' => $UID]);
}

// ── EXPORT: все отметки за год (для Excel на клиенте) ─────────────────────────
if ($action === 'export' && $method === 'GET') {
    seedIfEmpty($pdo, $UID);
    $year = (int)($_GET['year'] ?? date('Y'));
    $habits = activeHabits($pdo, $UID);

    $st = $pdo->prepare("SELECT log_date, habit_id, status FROM habit_logs
                         WHERE user_id = ? AND log_date BETWEEN ? AND ? ORDER BY log_date");
    $st->execute([$UID, "$year-01-01", "$year-12-31"]);
    $grid = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $grid[$r['log_date']][$r['habit_id']] = $r['status'];

    $st = $pdo->prepare("SELECT log_date, mood, comment FROM habit_days
                         WHERE user_id = ? AND log_date BETWEEN ? AND ?");
    $st->execute([$UID, "$year-01-01", "$year-12-31"]);
    $days = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $days[$r['log_date']] = $r;

    jsonOk(['year' => $year, 'habits' => $habits, 'grid' => $grid, 'days' => $days]);
}

jsonErr('Неизвестный запрос', 400);
