<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Раздел «Финансы»: у каждого пользователя свои категории, члены семьи и операции.
// Вход общий с трекером привычек (Google), база та же — sulmin_ikhlas.
if (empty($_SESSION['user_id'])) jsonErr('Нужно войти', 401);

set_exception_handler(function (Throwable $e) {
    error_log('finance api: ' . $e->getMessage());
    jsonErr('Ошибка сервера', 500);
});

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$UID    = (int)$_SESSION['user_id'];

// ── ENSURE TABLES ─────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS fin_cats (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    parent_id  INT NULL,                              -- NULL = категория, иначе подкатегория
    type       VARCHAR(3)  NOT NULL DEFAULT 'out',    -- out (расход) | in (поступление)
    name       VARCHAR(60) NOT NULL,
    budget     DECIMAL(12,2) NOT NULL DEFAULT 0,      -- лимит на месяц (только у категорий расходов)
    sort_order INT NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, is_active),
    INDEX idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS fin_people (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    name       VARCHAR(60) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS fin_quick (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    label      VARCHAR(60) NOT NULL,
    amount     DECIMAL(12,2) NOT NULL,
    cat_id     INT NOT NULL,
    person_id  INT NULL,
    note       VARCHAR(255) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS fin_tx (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    tx_date    DATE NOT NULL,
    type       VARCHAR(3) NOT NULL,                   -- out | in, всегда как у категории
    amount     DECIMAL(12,2) NOT NULL,
    cat_id     INT NOT NULL,                          -- категория или подкатегория
    person_id  INT NULL,                              -- для кого (только у расходов)
    note       VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_date (user_id, tx_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── БАЗОВЫЙ НАБОР КАТЕГОРИЙ ───────────────────────────────────────────────────
const BASE_CATS = [
    // [название, тип, подкатегории]
    ['🛒 Продукты',          'out', ['Хлеб', 'Мясо', 'Овощи и фрукты', 'Молочное']],
    ['🧒 Дети',              'out', []],
    ['💊 Здоровье',          'out', []],
    ['🚕 Транспорт',         'out', []],
    ['💡 Коммуналка',        'out', ['Свет', 'Газ', 'Вода', 'Интернет и связь']],
    ['🏠 Дом и уют',         'out', []],
    ['🛍 Покупки',           'out', []],
    ['🎉 Развлечения',       'out', []],
    ['🎁 Подарки',           'out', []],
    ['🤲 Садака',            'out', []],
    ['💼 Зарплата',          'in',  []],
    ['💰 Прочие поступления', 'in',  []],
];

// Первый заход — засеваем базовый набор
function seedIfEmpty(PDO $pdo, int $uid): void {
    $has = (int)$pdo->query("SELECT COUNT(*) FROM fin_cats WHERE user_id = $uid")->fetchColumn();
    if ($has > 0) return;
    $ins = $pdo->prepare("INSERT INTO fin_cats (user_id, parent_id, type, name, sort_order) VALUES (?,?,?,?,?)");
    $i = 0;
    foreach (BASE_CATS as [$name, $type, $subs]) {
        $ins->execute([$uid, null, $type, $name, $i++]);
        $pid = (int)$pdo->lastInsertId();
        foreach ($subs as $j => $sub) $ins->execute([$uid, $pid, $type, $sub, $j]);
    }
}

function input(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function validDate(string $d): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

// «1 234,50» → 1234.5
function money($v): float {
    return round((float)str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string)$v), 2);
}

function cleanName($v, int $max = 60): string {
    return mb_substr(trim((string)$v), 0, $max);
}

function ownCat(PDO $pdo, int $uid, int $id): ?array {
    $st = $pdo->prepare("SELECT id, parent_id, type, name, is_active FROM fin_cats WHERE id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    $c = $st->fetch();
    if (!$c) return null;
    $c['parent_id'] = $c['parent_id'] !== null ? (int)$c['parent_id'] : null;
    return $c;
}

function ownPerson(PDO $pdo, int $uid, int $id): bool {
    $st = $pdo->prepare("SELECT 1 FROM fin_people WHERE id = ? AND user_id = ?");
    $st->execute([$id, $uid]);
    return (bool)$st->fetchColumn();
}

function txRow(array $r): array {
    return [
        'id'     => (int)$r['id'],
        'date'   => $r['tx_date'],
        'type'   => $r['type'],
        'amount' => (float)$r['amount'],
        'cat'    => (int)$r['cat_id'],
        'person' => $r['person_id'] !== null ? (int)$r['person_id'] : null,
        'note'   => $r['note'],
    ];
}

// ══════════════════════════════════════════════════════════════════════════════

// ── CFG: категории (с подкатегориями), члены семьи, быстрые кнопки ────────────
// Удалённые категории и люди тоже отдаём — по ним показываются названия в истории.
if ($action === 'cfg' && $method === 'GET') {
    seedIfEmpty($pdo, $UID);

    $st = $pdo->prepare("SELECT id, parent_id, type, name, budget, is_active FROM fin_cats
                         WHERE user_id = ? ORDER BY sort_order, id");
    $st->execute([$UID]);
    $cats = array_map(fn($r) => [
        'id'        => (int)$r['id'],
        'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
        'type'      => $r['type'],
        'name'      => $r['name'],
        'budget'    => (float)$r['budget'],
        'active'    => (bool)$r['is_active'],
    ], $st->fetchAll());

    $st = $pdo->prepare("SELECT id, name, is_active FROM fin_people WHERE user_id = ? ORDER BY sort_order, id");
    $st->execute([$UID]);
    $people = array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'active' => (bool)$r['is_active'],
    ], $st->fetchAll());

    $st = $pdo->prepare("SELECT id, label, amount, cat_id, person_id, note FROM fin_quick
                         WHERE user_id = ? ORDER BY sort_order, id");
    $st->execute([$UID]);
    $quick = array_map(fn($r) => [
        'id'     => (int)$r['id'],
        'label'  => $r['label'],
        'amount' => (float)$r['amount'],
        'cat'    => (int)$r['cat_id'],
        'person' => $r['person_id'] !== null ? (int)$r['person_id'] : null,
        'note'   => $r['note'],
    ], $st->fetchAll());

    jsonOk(['cats' => $cats, 'people' => $people, 'quick' => $quick]);
}

// ── КАТЕГОРИИ И ПОДКАТЕГОРИИ ──────────────────────────────────────────────────
if ($action === 'cat_add' && $method === 'POST') {
    $d      = input();
    $name   = cleanName($d['name'] ?? '');
    $parent = (int)($d['parent_id'] ?? 0);
    if ($name === '') jsonErr('Введите название');

    if ($parent) {
        // подкатегория — только внутри категории верхнего уровня, тип как у неё
        $p = ownCat($pdo, $UID, $parent);
        if (!$p || !$p['is_active']) jsonErr('Категория не найдена', 404);
        if ($p['parent_id'] !== null) jsonErr('Подкатегорию можно создать только внутри категории');
        $type = $p['type'];
    } else {
        $type = ($d['type'] ?? '') === 'in' ? 'in' : 'out';
    }

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM fin_cats WHERE user_id = $UID AND is_active = 1")->fetchColumn();
    if ($cnt >= 300) jsonErr('Максимум 300 категорий и подкатегорий');

    $st = $pdo->prepare("SELECT COUNT(*) FROM fin_cats WHERE user_id = ? AND is_active = 1 AND type = ?
                         AND name = ? AND " . ($parent ? "parent_id = ?" : "parent_id IS NULL"));
    $st->execute($parent ? [$UID, $type, $name, $parent] : [$UID, $type, $name]);
    if ($st->fetchColumn()) jsonErr($parent ? 'Такая подкатегория уже есть' : 'Такая категория уже есть');

    $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM fin_cats WHERE user_id = $UID")->fetchColumn();
    $pdo->prepare("INSERT INTO fin_cats (user_id, parent_id, type, name, sort_order) VALUES (?,?,?,?,?)")
        ->execute([$UID, $parent ?: null, $type, $name, $max + 1]);
    jsonOk(['id' => (int)$pdo->lastInsertId(), 'type' => $type]);
}

if ($action === 'cat_save' && $method === 'POST') {
    $d = input();
    $c = ownCat($pdo, $UID, (int)($d['id'] ?? 0));
    if (!$c) jsonErr('Категория не найдена', 404);

    if (array_key_exists('name', $d)) {
        $name = cleanName($d['name']);
        if ($name === '') jsonErr('Введите название');
        $pdo->prepare("UPDATE fin_cats SET name = ? WHERE id = ? AND user_id = ?")->execute([$name, $c['id'], $UID]);
    }
    if (array_key_exists('budget', $d)) {
        if ($c['parent_id'] !== null || $c['type'] !== 'out') jsonErr('Лимит ставится только на категорию расходов');
        $pdo->prepare("UPDATE fin_cats SET budget = ? WHERE id = ? AND user_id = ?")
            ->execute([max(0, money($d['budget'])), $c['id'], $UID]);
    }
    jsonOk();
}

if ($action === 'cat_del' && $method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonErr('Не указан id');
    // мягкое удаление вместе с подкатегориями — операции и их названия сохраняются;
    // быстрые кнопки на эти категории убираем, иначе они писали бы в удалённую
    $pdo->prepare("DELETE FROM fin_quick WHERE user_id = ? AND cat_id IN
                   (SELECT id FROM fin_cats WHERE user_id = ? AND (id = ? OR parent_id = ?))")
        ->execute([$UID, $UID, $id, $id]);
    $pdo->prepare("UPDATE fin_cats SET is_active = 0 WHERE user_id = ? AND (id = ? OR parent_id = ?)")
        ->execute([$UID, $id, $id]);
    jsonOk();
}

// ── ЧЛЕНЫ СЕМЬИ («Для кого») ─────────────────────────────────────────────────
if ($action === 'person_add' && $method === 'POST') {
    $name = cleanName(input()['name'] ?? '');
    if ($name === '') jsonErr('Введите имя');
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM fin_people WHERE user_id = $UID AND is_active = 1")->fetchColumn();
    if ($cnt >= 30) jsonErr('Максимум 30 человек');
    $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM fin_people WHERE user_id = $UID")->fetchColumn();
    $pdo->prepare("INSERT INTO fin_people (user_id, name, sort_order) VALUES (?,?,?)")->execute([$UID, $name, $max + 1]);
    jsonOk(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'person_save' && $method === 'POST') {
    $d    = input();
    $name = cleanName($d['name'] ?? '');
    if ($name === '') jsonErr('Введите имя');
    $pdo->prepare("UPDATE fin_people SET name = ? WHERE id = ? AND user_id = ?")->execute([$name, (int)($d['id'] ?? 0), $UID]);
    jsonOk();
}

if ($action === 'person_del' && $method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonErr('Не указан id');
    $pdo->prepare("UPDATE fin_people SET is_active = 0 WHERE id = ? AND user_id = ?")->execute([$id, $UID]);
    jsonOk();
}

// ── БЫСТРЫЕ КНОПКИ (одно нажатие = расход сегодняшним днём) ───────────────────
if ($action === 'quick_add' && $method === 'POST') {
    $d      = input();
    $label  = cleanName($d['label'] ?? '');
    $amount = money($d['amount'] ?? 0);
    $person = (int)($d['person'] ?? 0);
    if ($label === '') jsonErr('Введите название кнопки');
    if ($amount <= 0 || $amount > 1e10) jsonErr('Сумма должна быть больше нуля');
    $c = ownCat($pdo, $UID, (int)($d['cat'] ?? 0));
    if (!$c || !$c['is_active'] || $c['type'] !== 'out') jsonErr('Выберите категорию расходов');
    if ($person && !ownPerson($pdo, $UID, $person)) jsonErr('Человек не найден', 404);

    $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM fin_quick WHERE user_id = $UID")->fetchColumn();
    $pdo->prepare("INSERT INTO fin_quick (user_id, label, amount, cat_id, person_id, note, sort_order) VALUES (?,?,?,?,?,?,?)")
        ->execute([$UID, $label, $amount, $c['id'], $person ?: null, $label, $max + 1]);
    jsonOk(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'quick_del' && $method === 'DELETE') {
    $pdo->prepare("DELETE FROM fin_quick WHERE id = ? AND user_id = ?")->execute([(int)($_GET['id'] ?? 0), $UID]);
    jsonOk();
}

// ── ОПЕРАЦИИ ──────────────────────────────────────────────────────────────────
if ($action === 'tx' && $method === 'GET') {
    $from = (string)($_GET['from'] ?? '');
    $to   = (string)($_GET['to'] ?? '');
    if (!validDate($from) || !validDate($to)) jsonErr('Неверный период');
    $st = $pdo->prepare("SELECT id, tx_date, type, amount, cat_id, person_id, note FROM fin_tx
                         WHERE user_id = ? AND tx_date >= ? AND tx_date < ? ORDER BY tx_date DESC, id DESC");
    $st->execute([$UID, $from, $to]);
    jsonOk(['tx' => array_map('txRow', $st->fetchAll())]);
}

if ($action === 'tx_save' && $method === 'POST') {
    $d      = input();
    $id     = (int)($d['id'] ?? 0);
    $date   = (string)($d['date'] ?? '');
    $amount = money($d['amount'] ?? 0);
    $person = (int)($d['person'] ?? 0);
    $note   = cleanName($d['note'] ?? '', 255);

    if (!validDate($date)) jsonErr('Неверная дата');
    if ($amount <= 0 || $amount > 1e10) jsonErr('Сумма должна быть больше нуля');
    // удалённую категорию не запрещаем: старую операцию можно поправить, не меняя категорию
    $c = ownCat($pdo, $UID, (int)($d['cat'] ?? 0));
    if (!$c) jsonErr('Выберите категорию');
    $type = $c['type'];
    if ($type === 'in') $person = 0;          // «для кого» — только у расходов
    if ($person && !ownPerson($pdo, $UID, $person)) jsonErr('Человек не найден', 404);

    if ($id) {
        $own = $pdo->prepare("SELECT 1 FROM fin_tx WHERE id = ? AND user_id = ?");
        $own->execute([$id, $UID]);
        if (!$own->fetchColumn()) jsonErr('Операция не найдена', 404);
        $pdo->prepare("UPDATE fin_tx SET tx_date = ?, type = ?, amount = ?, cat_id = ?, person_id = ?, note = ?
                       WHERE id = ? AND user_id = ?")
            ->execute([$date, $type, $amount, $c['id'], $person ?: null, $note, $id, $UID]);
        jsonOk(['id' => $id]);
    }

    $pdo->prepare("INSERT INTO fin_tx (user_id, tx_date, type, amount, cat_id, person_id, note) VALUES (?,?,?,?,?,?,?)")
        ->execute([$UID, $date, $type, $amount, $c['id'], $person ?: null, $note]);
    jsonOk(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'tx_del' && $method === 'DELETE') {
    $pdo->prepare("DELETE FROM fin_tx WHERE id = ? AND user_id = ?")->execute([(int)($_GET['id'] ?? 0), $UID]);
    jsonOk();
}

// ── EXPORT: все операции (для Excel на клиенте) ───────────────────────────────
if ($action === 'export' && $method === 'GET') {
    $st = $pdo->prepare("SELECT id, tx_date, type, amount, cat_id, person_id, note FROM fin_tx
                         WHERE user_id = ? ORDER BY tx_date DESC, id DESC");
    $st->execute([$UID]);
    jsonOk(['tx' => array_map('txRow', $st->fetchAll())]);
}

jsonErr('Неизвестный запрос', 400);
