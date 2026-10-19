<?php
/**
 * Раздел «Библиотека»: доступ, хранилище файлов, таблицы, реестр форматов,
 * определение формата по содержимому файла.
 *
 * Чтение, извлечение метаданных и конвертация TXT/DOCX/RTF идут в браузере
 * (library/js/process.js) — сервер хранит оригиналы, производные версии,
 * базу и синхронизацию.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib_config.php';
require_once __DIR__ . '/lib_zip.php';

const LIB_SCHEMA = 2;

// ── РЕЕСТР ФОРМАТОВ ───────────────────────────────────────────────────────────
// convert = true — для чтения нужна производная EPUB-версия (её делает браузер).
// Чем открывать каждый формат, решает клиентский реестр (library/js/formats.js).
const LIB_FORMATS = [
    'epub' => ['label' => 'EPUB',       'ext' => 'epub',    'convert' => false],
    'fb2'  => ['label' => 'FB2',        'ext' => 'fb2',     'convert' => false],
    'fbz'  => ['label' => 'FB2 в ZIP',  'ext' => 'fb2.zip', 'convert' => false],
    'mobi' => ['label' => 'MOBI',       'ext' => 'mobi',    'convert' => false],
    'azw3' => ['label' => 'KF8 / AZW3', 'ext' => 'azw3',    'convert' => false],
    'pdf'  => ['label' => 'PDF',        'ext' => 'pdf',     'convert' => false],
    'djvu' => ['label' => 'DjVu',       'ext' => 'djvu',    'convert' => false],
    'txt'  => ['label' => 'TXT',        'ext' => 'txt',     'convert' => true],
    'docx' => ['label' => 'DOCX',       'ext' => 'docx',    'convert' => true],
    'rtf'  => ['label' => 'RTF',        'ext' => 'rtf',     'convert' => true],
];

// Статусы обработки
const LIB_STATUSES = ['uploaded', 'checking', 'ready', 'needs_conversion', 'converting', 'converted', 'error', 'unsupported'];

// ── ДОСТУП ────────────────────────────────────────────────────────────────────
function libAllowed(): bool {
    if (empty($_SESSION['user_id'])) return false;
    $email = mb_strtolower((string)(currentUser()['username'] ?? ''));
    return $email !== '' && in_array($email, array_map('mb_strtolower', LIB_ALLOWED_EMAILS), true);
}

function libRequirePage(): void {
    requireAuth();
    if (libAllowed()) return;
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Библиотека</title><body style="font-family:system-ui,sans-serif;display:flex;min-height:100vh;'
       . 'align-items:center;justify-content:center;background:#f4f7f6;color:#17211e;margin:0;padding:24px;text-align:center">'
       . '<div><div style="font-size:44px">📚</div><h2 style="margin:12px 0 6px">Раздел закрыт</h2>'
       . '<p style="color:#6b7a76">Библиотека доступна только владельцу.</p><p><a href="/" style="color:#0f9d76">На главную</a></p></div>';
    exit;
}

// Для API: проверка + сразу отпускаем сессию, иначе загрузка большого файла
// блокировала бы все остальные запросы этого пользователя.
function libRequireApi(): int {
    if (empty($_SESSION['user_id'])) jsonErr('Нужно войти', 401);
    if (!libAllowed()) jsonErr('Раздел недоступен', 403);
    $uid = (int)$_SESSION['user_id'];
    session_write_close();
    return $uid;
}

// Одноразовый nonce для Content-Security-Policy: в книгах (EPUB) бывают
// скрипты, и выполняться им нельзя — разрешены только наши файлы.
function libCsp(bool $reader = false): string {
    $nonce = base64_encode(random_bytes(16));
    $img = "'self' blob: data:" . ($reader ? '' : ' https://*.googleusercontent.com');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-$nonce' 'wasm-unsafe-eval'; "
        . "style-src 'self' 'unsafe-inline' blob:; img-src $img; font-src 'self' blob: data:; "
        . "connect-src 'self' blob: data:; worker-src 'self' blob:; frame-src 'self' blob:; "
        . "media-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    return $nonce;
}

// ── ХРАНИЛИЩЕ ─────────────────────────────────────────────────────────────────
function libPath(string $rel = ''): string {
    $base = rtrim(LIB_STORAGE, '/\\');
    return $rel === '' ? $base : $base . '/' . $rel;
}

function libDir(string $rel = ''): string {
    $path = libPath($rel);
    if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('Не удаётся создать папку хранилища ' . $path . ' — проверьте LIB_STORAGE в includes/lib_config.php');
    }
    // если хранилище всё-таки оказалось внутри сайта — закрываем его
    $guard = libPath('.htaccess');
    if (!is_file($guard)) @file_put_contents($guard, "Require all denied\nDeny from all\n");
    return $path;
}

function libBookDir(int $uid, int $bookId): string {
    return "u$uid/b$bookId";
}

function libRemoveDir(string $abs): void {
    if (!is_dir($abs)) return;
    foreach (scandir($abs) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$abs/$f";
        is_dir($p) ? libRemoveDir($p) : @unlink($p);
    }
    @rmdir($abs);
}

// ── ТАБЛИЦЫ ───────────────────────────────────────────────────────────────────
function libEnsureTables(PDO $pdo): void {
    $marker = libDir() . '/.schema-' . LIB_SCHEMA;
    if (is_file($marker)) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_books (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        user_id           INT NOT NULL,
        title             VARCHAR(500) NOT NULL DEFAULT '',
        authors           VARCHAR(500) NOT NULL DEFAULT '',      -- через «; »
        language          VARCHAR(20)  NOT NULL DEFAULT '',
        publisher         VARCHAR(255) NOT NULL DEFAULT '',
        year              VARCHAR(20)  NOT NULL DEFAULT '',
        series            VARCHAR(255) NOT NULL DEFAULT '',
        identifier        VARCHAR(255) NOT NULL DEFAULT '',
        description       TEXT NULL,
        meta_edited       VARCHAR(255) NOT NULL DEFAULT '',      -- поля, исправленные вручную: обработка их не трогает
        orig_name         VARCHAR(255) NOT NULL,
        orig_format       VARCHAR(10)  NOT NULL,
        orig_size         BIGINT NOT NULL,
        orig_sha256       CHAR(64) NOT NULL,
        orig_path         VARCHAR(255) NOT NULL DEFAULT '',      -- относительно LIB_STORAGE
        derived_format    VARCHAR(10)  NOT NULL DEFAULT '',
        derived_path      VARCHAR(255) NOT NULL DEFAULT '',
        derived_size      BIGINT NOT NULL DEFAULT 0,
        converter         VARCHAR(40)  NOT NULL DEFAULT '',
        converter_version INT NOT NULL DEFAULT 0,
        structure_hash    VARCHAR(64)  NOT NULL DEFAULT '',
        structure_changed TINYINT(1) NOT NULL DEFAULT 0,
        status            VARCHAR(20)  NOT NULL DEFAULT 'uploaded',
        status_at         DATETIME NULL,
        error             TEXT NULL,
        warnings          TEXT NULL,
        cover_type        VARCHAR(5)   NOT NULL DEFAULT '',      -- jpg | png | webp, пусто — обложки нет
        toc               MEDIUMTEXT NULL,                       -- JSON
        pages             INT NULL,
        has_text          TINYINT(1) NULL,                       -- PDF/DjVu: есть ли текстовый слой
        drm               TINYINT(1) NOT NULL DEFAULT 0,
        read_status       VARCHAR(12)  NOT NULL DEFAULT 'new',   -- new|want|planned|reading|paused|finished
        favorite          TINYINT(1) NOT NULL DEFAULT 0,
        progress          DOUBLE NOT NULL DEFAULT 0,             -- 0..1, по самому свежему устройству
        position_label    VARCHAR(255) NOT NULL DEFAULT '',
        last_opened_at    DATETIME NULL,
        finished_at       DATETIME NULL,
        created_at        DATETIME NOT NULL,
        updated_at        DATETIME NOT NULL,
        INDEX idx_user (user_id),
        INDEX idx_sha (user_id, orig_sha256)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Позиция чтения — отдельно по каждому устройству: ничего не перезаписывается,
    // а «текущей» считается самая свежая.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_positions (
        book_id     INT NOT NULL,
        device_id   VARCHAR(40) NOT NULL,
        user_id     INT NOT NULL,
        device_name VARCHAR(80) NOT NULL DEFAULT '',
        locator     TEXT NOT NULL,                               -- JSON: cfi / страница / ...
        fraction    DOUBLE NOT NULL DEFAULT 0,
        label       VARCHAR(255) NOT NULL DEFAULT '',
        client_ts   BIGINT NOT NULL,                             -- мс, когда позиция сменилась на устройстве
        server_at   DATETIME NOT NULL,
        PRIMARY KEY (book_id, device_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Закладки, выделения, заметки, цитаты. id создаёт устройство (UUID),
    // удаление — пометкой, чтобы другие устройства узнали о нём.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_annotations (
        id          CHAR(36) NOT NULL PRIMARY KEY,
        user_id     INT NOT NULL,
        book_id     INT NOT NULL,
        type        VARCHAR(12) NOT NULL,                        -- bookmark | highlight | note | quote
        locator     TEXT NOT NULL,                               -- JSON
        text        TEXT NULL,                                   -- выделенный фрагмент
        note        TEXT NULL,                                   -- комментарий
        color       VARCHAR(16) NOT NULL DEFAULT '',
        chapter     VARCHAR(255) NOT NULL DEFAULT '',
        fraction    DOUBLE NOT NULL DEFAULT 0,
        created_ts  BIGINT NOT NULL,
        updated_ts  BIGINT NOT NULL,
        deleted     TINYINT(1) NOT NULL DEFAULT 0,
        server_at   DATETIME NOT NULL,
        INDEX idx_user_book (user_id, book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_collections (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        kind       VARCHAR(12) NOT NULL DEFAULT 'collection',    -- collection | category
        name       VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_book_collections (
        book_id       INT NOT NULL,
        collection_id INT NOT NULL,
        PRIMARY KEY (book_id, collection_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_settings (
        user_id    INT NOT NULL PRIMARY KEY,
        data       TEXT NOT NULL,                                -- JSON настроек чтения
        updated_ts BIGINT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_uploads (
        id            CHAR(32) NOT NULL PRIMARY KEY,
        user_id       INT NOT NULL,
        name          VARCHAR(255) NOT NULL,
        size          BIGINT NOT NULL,
        received      BIGINT NOT NULL DEFAULT 0,
        last_modified BIGINT NOT NULL DEFAULT 0,
        created_at    DATETIME NOT NULL,
        updated_at    DATETIME NOT NULL,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Сеансы чтения — для дневника и статистики
    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_sessions (
        id            CHAR(36) NOT NULL PRIMARY KEY,             -- создаёт устройство
        user_id       INT NOT NULL,
        book_id       INT NOT NULL,
        device_id     VARCHAR(40) NOT NULL DEFAULT '',
        device_name   VARCHAR(80) NOT NULL DEFAULT '',
        started_at    DATETIME NOT NULL,
        ended_at      DATETIME NOT NULL,
        seconds       INT NOT NULL DEFAULT 0,
        from_fraction DOUBLE NOT NULL DEFAULT 0,
        to_fraction   DOUBLE NOT NULL DEFAULT 0,
        INDEX idx_user_time (user_id, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Текст книг для поиска: извлечённый (source = text) и распознанный OCR (source = ocr).
    // Хранится отдельно от оригиналов; оригинальные файлы не меняются.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_text (
        id       BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id  INT NOT NULL,
        book_id  INT NOT NULL,
        source   VARCHAR(8) NOT NULL,                            -- text | ocr
        seq      INT NOT NULL,                                   -- номер куска / страницы
        locator  VARCHAR(500) NOT NULL,                          -- JSON: {type:'section', index} | {type:'page', page}
        body     MEDIUMTEXT NOT NULL,
        UNIQUE KEY uq_piece (book_id, source, seq),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ключи доступа для внешних ИИ-помощников (только чтение). Хранится только хэш.
    $pdo->exec("CREATE TABLE IF NOT EXISTS lib_tokens (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL,
        name         VARCHAR(80) NOT NULL DEFAULT '',
        token_hash   CHAR(64) NOT NULL,
        created_at   DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        UNIQUE KEY uq_hash (token_hash),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // схема 2: индекс текста и OCR
    libAddColumn($pdo, 'lib_books', 'indexed_at', 'DATETIME NULL');
    libAddColumn($pdo, 'lib_books', 'ocr_status', "VARCHAR(12) NOT NULL DEFAULT ''");   // '' | partial | done
    libAddColumn($pdo, 'lib_books', 'ocr_pages',  'INT NOT NULL DEFAULT 0');
    libAddColumn($pdo, 'lib_books', 'ocr_lang',   "VARCHAR(40) NOT NULL DEFAULT ''");

    @file_put_contents($marker, date('c'));
}

function libAddColumn(PDO $pdo, string $table, string $col, string $def): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $col]);
    if (!(int)$st->fetchColumn()) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
}

// ── ОПРЕДЕЛЕНИЕ ФОРМАТА ───────────────────────────────────────────────────────

/**
 * Формат по содержимому файла, а не по расширению.
 * Возвращает ['format' => 'epub'|…|null, 'drm' => bool, 'reason' => string, 'note' => string]
 */
function libDetectFormat(string $path, string $origName): array {
    $out = ['format' => null, 'drm' => false, 'reason' => '', 'note' => ''];
    $fh = fopen($path, 'rb');
    $head = (string)fread($fh, 4096);
    fclose($fh);
    if ($head === '') return ['reason' => 'Файл пустой'] + $out;

    // PDF (допускаем мусор перед заголовком, как и читалки)
    $pdfAt = strpos(substr($head, 0, 1024), '%PDF-');
    if ($pdfAt !== false) {
        $out['format'] = 'pdf';
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        fseek($fh, max(0, $size - 4096));
        $tail = fread($fh, 4096);
        fclose($fh);
        if (strpos($tail, '/Encrypt') !== false) $out['note'] = 'PDF зашифрован: может понадобиться пароль';
        return $out;
    }

    // DjVu: AT&TFORM....DJVU / DJVM
    if (substr($head, 0, 8) === 'AT&TFORM' && in_array(substr($head, 12, 4), ['DJVU', 'DJVM'], true)) {
        $out['format'] = 'djvu';
        return $out;
    }

    // ZIP: EPUB, DOCX или FB2 в архиве
    if (substr($head, 0, 4) === "PK\x03\x04") {
        $list = libZipList($path);
        if ($list === null) return ['reason' => 'Повреждённый ZIP-архив'] + $out;
        $total = array_sum(array_column($list, 'usize'));
        if ($total > 4 * LIB_MAX_FILE) return ['reason' => 'Архив распаковывается в слишком большой объём'] + $out;

        $mime = isset($list['mimetype']) ? trim((string)libZipRead($path, $list['mimetype'], 200)) : '';
        if ($mime === 'application/epub+zip' || isset($list['META-INF/container.xml'])) {
            $out['format'] = 'epub';
            // DRM: rights.xml или шифрование, отличное от «обфускации» шрифтов
            if (isset($list['META-INF/rights.xml'])) $out['drm'] = true;
            if (isset($list['META-INF/encryption.xml'])) {
                $xml = (string)libZipRead($path, $list['META-INF/encryption.xml'], 1000000);
                preg_match_all('/Algorithm\s*=\s*"([^"]+)"/', $xml, $m);
                $fontOnly = ['http://www.idpf.org/2008/embedding', 'http://ns.adobe.com/pdf/enc#RC'];
                foreach ($m[1] as $alg) {
                    if (!in_array($alg, $fontOnly, true)) { $out['drm'] = true; break; }
                }
            }
            return $out;
        }
        if (isset($list['word/document.xml'])) { $out['format'] = 'docx'; return $out; }
        foreach (array_keys($list) as $name) {
            if (preg_match('/\.fb2$/i', $name)) { $out['format'] = 'fbz'; return $out; }
        }
        return ['reason' => 'ZIP-архив без книги (нет EPUB, DOCX или FB2 внутри)'] + $out;
    }

    // MOBI / KF8 (AZW3): PalmDB с типом BOOKMOBI
    if (strlen($head) > 86 && substr($head, 60, 8) === 'BOOKMOBI') {
        return libDetectMobi($path) + $out;
    }
    if (strlen($head) > 68 && substr($head, 60, 8) === 'TEXtREAd') {
        return ['reason' => 'PalmDOC (.pdb) не поддерживается'] + $out;
    }
    if (strpos($head, 'DRMION') !== false || substr($head, 0, 4) === 'CONT') {
        return ['reason' => 'Формат Kindle KFX с защитой — откройте книгу в приложении Kindle', 'drm' => true] + $out;
    }

    // Старый Word (.doc) и зашифрованные документы Office хранятся в контейнере CFB
    if (substr($head, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return ['reason' => 'Старый формат Word (.doc) или защищённый паролем документ. Сохраните его как DOCX.'] + $out;
    }

    $text = preg_replace('/^\xEF\xBB\xBF/', '', $head);
    if (strncmp(ltrim($text), '{\rtf', 5) === 0) { $out['format'] = 'rtf'; return $out; }

    // UTF-16 с BOM — текст
    $bom16 = substr($head, 0, 2) === "\xFF\xFE" || substr($head, 0, 2) === "\xFE\xFF";
    if (!$bom16 && stripos($text, '<FictionBook') !== false) { $out['format'] = 'fb2'; return $out; }

    // Текст: в начале нет нулевых байтов и почти нет управляющих символов
    $fh = fopen($path, 'rb');
    $sample = (string)fread($fh, 65536);
    fclose($fh);
    if ($bom16) { $out['format'] = 'txt'; return $out; }
    if (strpos($sample, "\0") === false) {
        $ctrl = preg_match_all('/[\x01-\x08\x0E-\x1A\x1C-\x1F]/', $sample);
        if ($ctrl < strlen($sample) / 200) {
            if (preg_match('/^\s*<(!doctype html|html)/i', $text)) return ['reason' => 'HTML-страницы не входят в список форматов библиотеки'] + $out;
            $out['format'] = 'txt';
            return $out;
        }
    }
    return ['reason' => 'Формат не распознан'] + $out;
}

// MOBI: шифрование (DRM) и версия (8 = KF8 / AZW3)
function libDetectMobi(string $path): array {
    $fh = fopen($path, 'rb');
    $hdr = fread($fh, 86);
    $count = unpack('n', substr($hdr, 76, 2))[1];
    if ($count < 1) { fclose($fh); return ['reason' => 'Повреждённый MOBI: нет записей']; }
    $rec0 = unpack('N', substr($hdr, 78, 4))[1];
    fseek($fh, $rec0);
    $r = fread($fh, 1024);
    fclose($fh);
    if (strlen($r) < 132 || substr($r, 16, 4) !== 'MOBI') return ['reason' => 'Повреждённый MOBI: нет заголовка'];
    $encryption = unpack('n', substr($r, 12, 2))[1];
    $version = unpack('N', substr($r, 36, 4))[1];
    if ($encryption !== 0) {
        return ['format' => $version >= 8 ? 'azw3' : 'mobi', 'drm' => true];
    }
    return ['format' => $version >= 8 ? 'azw3' : 'mobi'];
}

// ── ВЫДАЧА КНИГИ В JSON ───────────────────────────────────────────────────────
function libBookOut(array $b, bool $full = false): array {
    $f = LIB_FORMATS[$b['orig_format']] ?? null;
    $out = [
        'id'            => (int)$b['id'],
        'title'         => $b['title'],
        'authors'       => $b['authors'],
        'language'      => $b['language'],
        'publisher'     => $b['publisher'],
        'year'          => $b['year'],
        'series'        => $b['series'],
        'format'        => $b['orig_format'],
        'format_label'  => $f['label'] ?? $b['orig_format'],
        'orig_name'     => $b['orig_name'],
        'orig_size'     => (int)$b['orig_size'],
        'sha256'        => $b['orig_sha256'],
        'derived'       => $b['derived_format'],
        'derived_size'  => (int)$b['derived_size'],
        'converter'     => $b['converter'],
        'converter_version' => (int)$b['converter_version'],
        'structure_changed' => (bool)$b['structure_changed'],
        'status'        => $b['status'],
        'status_at'     => $b['status_at'],
        'error'         => $b['error'],
        'cover'         => $b['cover_type'] !== '',
        'pages'         => $b['pages'] !== null ? (int)$b['pages'] : null,
        'has_text'      => $b['has_text'] !== null ? (bool)$b['has_text'] : null,
        'drm'           => (bool)$b['drm'],
        'read_status'   => $b['read_status'],
        'favorite'      => (bool)$b['favorite'],
        'progress'      => (float)$b['progress'],
        'position_label'=> $b['position_label'],
        'last_opened_at'=> $b['last_opened_at'],
        'finished_at'   => $b['finished_at'],
        'created_at'    => $b['created_at'],
        'updated_at'    => $b['updated_at'],
        'indexed'       => !empty($b['indexed_at']),
        'ocr_status'    => (string)($b['ocr_status'] ?? ''),
        'ocr_pages'     => (int)($b['ocr_pages'] ?? 0),
        'ocr_lang'      => (string)($b['ocr_lang'] ?? ''),
        'v'             => substr(md5($b['updated_at'] . $b['converter_version'] . $b['derived_size'] . $b['cover_type']), 0, 8),
    ];
    if ($full) {
        $out['identifier']  = $b['identifier'];
        $out['description'] = $b['description'];
        $out['meta_edited'] = $b['meta_edited'] === '' ? [] : explode(',', $b['meta_edited']);
        $out['warnings']    = $b['warnings'] ? (json_decode($b['warnings'], true) ?: []) : [];
        $out['toc']         = $b['toc'] ? (json_decode($b['toc'], true) ?: []) : [];
    }
    return $out;
}
