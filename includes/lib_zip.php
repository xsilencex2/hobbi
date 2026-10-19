<?php
/**
 * ZIP без расширения zip: чтение каталога (включая ZIP64), извлечение с проверкой
 * контрольной суммы и потоковая запись архива прямо в ответ (без временных файлов —
 * библиотека может весить десятки гигабайт).
 */

// Список файлов архива: имя → [method, csize, usize, offset, encrypted]
function libZipList(string $path): ?array {
    $size = filesize($path);
    if ($size < 22) return null;
    $fh = fopen($path, 'rb');
    $tail = min($size, 65557);
    fseek($fh, $size - $tail);
    $buf = fread($fh, $tail);
    $eocd = strrpos($buf, "PK\x05\x06");
    if ($eocd === false) { fclose($fh); return null; }
    $e = unpack('vdisk/vcdDisk/vcountDisk/vcount/Vsize/Voffset', substr($buf, $eocd + 4, 16));
    $count = $e['count']; $cdSize = $e['size']; $cdOff = $e['offset'];

    // ZIP64: настоящие значения — в отдельной записи, на которую указывает локатор
    if ($count === 0xFFFF || $cdSize === 0xFFFFFFFF || $cdOff === 0xFFFFFFFF) {
        $locAt = $eocd - 20;
        if ($locAt >= 0 && substr($buf, $locAt, 4) === "PK\x06\x07") {
            $z64off = unpack('P', substr($buf, $locAt + 8, 8))[1];
            fseek($fh, $z64off);
            $rec = fread($fh, 56);
            if (substr($rec, 0, 4) === "PK\x06\x06") {
                $count  = unpack('P', substr($rec, 32, 8))[1];
                $cdSize = unpack('P', substr($rec, 40, 8))[1];
                $cdOff  = unpack('P', substr($rec, 48, 8))[1];
            }
        }
    }
    if ($count > 200000 || $cdOff + $cdSize > $size || $cdSize > 200 * 1048576) { fclose($fh); return null; }
    fseek($fh, $cdOff);
    $cd = fread($fh, $cdSize);
    fclose($fh);

    $list = [];
    $p = 0;
    for ($i = 0; $i < $count; $i++) {
        if (substr($cd, $p, 4) !== "PK\x01\x02") break;
        $h = unpack('vver/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Vxattr/Voffset', substr($cd, $p + 4, 42));
        $name  = substr($cd, $p + 46, $h['nlen']);
        $extra = substr($cd, $p + 46 + $h['nlen'], $h['xlen']);
        $usize = $h['usize']; $csize = $h['csize']; $off = $h['offset'];
        // расширение ZIP64 (id 0x0001): поля идут по порядку, только для тех, что равны 0xFFFFFFFF
        for ($x = 0; $x + 4 <= strlen($extra);) {
            $hd = unpack('vid/vlen', substr($extra, $x, 4));
            if ($hd['id'] === 1) {
                $d = substr($extra, $x + 4, $hd['len']); $k = 0;
                if ($usize === 0xFFFFFFFF) { $usize = unpack('P', substr($d, $k, 8))[1]; $k += 8; }
                if ($csize === 0xFFFFFFFF) { $csize = unpack('P', substr($d, $k, 8))[1]; $k += 8; }
                if ($off === 0xFFFFFFFF)   { $off   = unpack('P', substr($d, $k, 8))[1]; }
            }
            $x += 4 + $hd['len'];
        }
        $list[$name] = ['method' => $h['method'], 'csize' => $csize, 'usize' => $usize, 'offset' => $off,
                        'crc' => $h['crc'], 'encrypted' => (bool)($h['flags'] & 1)];
        $p += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
    }
    return $list;
}

function libZipDataOffset($fh, array $entry): ?int {
    fseek($fh, $entry['offset']);
    $h = fread($fh, 30);
    if (substr($h, 0, 4) !== "PK\x03\x04") return null;
    $l = unpack('vnlen/vxlen', substr($h, 26, 4));
    return $entry['offset'] + 30 + $l['nlen'] + $l['xlen'];
}

// Прочитать небольшой файл из ZIP (не больше $max байт после распаковки)
function libZipRead(string $path, array $entry, int $max = 2000000): ?string {
    if ($entry['usize'] > $max || $entry['csize'] > $max || $entry['encrypted']) return null;
    $fh = fopen($path, 'rb');
    $at = libZipDataOffset($fh, $entry);
    if ($at === null) { fclose($fh); return null; }
    fseek($fh, $at);
    $data = $entry['csize'] > 0 ? fread($fh, $entry['csize']) : '';
    fclose($fh);
    if ($entry['method'] === 0) return $data;
    if ($entry['method'] === 8) { $out = @gzinflate($data, $max); return $out === false ? null : $out; }
    return null;
}

// Извлечь файл потоком (можно в никуда — только посчитать контрольную сумму).
// Возвращает sha256 распакованного содержимого или null при ошибке.
function libZipExtract(string $zip, array $entry, ?string $dest): ?string {
    if ($entry['encrypted'] || !in_array($entry['method'], [0, 8], true)) return null;
    $fh = fopen($zip, 'rb');
    $at = libZipDataOffset($fh, $entry);
    if ($at === null) { fclose($fh); return null; }
    fseek($fh, $at);
    $out = $dest ? fopen($dest, 'wb') : null;
    $hash = hash_init('sha256');
    $inflate = $entry['method'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
    $left = $entry['csize'];
    $written = 0;
    while ($left > 0) {
        $chunk = fread($fh, (int)min(1048576, $left));
        if ($chunk === false || $chunk === '') break;
        $left -= strlen($chunk);
        $data = $inflate ? inflate_add($inflate, $chunk, $left > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH) : $chunk;
        if ($data === false) { fclose($fh); if ($out) fclose($out); return null; }
        hash_update($hash, $data);
        $written += strlen($data);
        if ($out) fwrite($out, $data);
    }
    fclose($fh);
    if ($out) fclose($out);
    if ($left > 0 || $written !== $entry['usize']) return null;
    return hash_final($hash);
}

/**
 * Потоковая запись ZIP прямо в ответ. Файлы кладутся без сжатия (книги и так сжаты),
 * CRC считается заранее — заголовки с точными размерами, совместимо с любым распаковщиком.
 * ZIP64 включается сам, когда нужно (файлы и архивы больше 4 ГБ).
 */
class LibZipStream {
    private int $offset = 0;
    private array $entries = [];

    private function w(string $s): void {
        echo $s;
        $this->offset += strlen($s);
    }

    private static function dos(int $ts): array {
        $d = getdate($ts);
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
                (max(0, $d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }

    public function addFile(string $name, string $path): void {
        $crc  = hexdec(hash_file('crc32b', $path));
        $size = filesize($path);
        $this->header($name, $crc, $size, filemtime($path) ?: time());
        $fh = fopen($path, 'rb');
        while (!feof($fh)) {
            $buf = fread($fh, 1048576);
            if ($buf === false || $buf === '') break;
            $this->w($buf);
            flush();
            if (connection_aborted()) { fclose($fh); exit; }
        }
        fclose($fh);
    }

    public function addString(string $name, string $data): void {
        $this->header($name, hexdec(hash('crc32b', $data)), strlen($data), time());
        $this->w($data);
    }

    private function header(string $name, int $crc, int $size, int $mtime): void {
        [$time, $date] = self::dos($mtime);
        $z64 = $size >= 0xFFFFFFFF;
        $extra = $z64 ? pack('vvPP', 1, 16, $size, $size) : '';
        $this->entries[] = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $this->offset, 'time' => $time, 'date' => $date];
        $this->w(pack('VvvvvvVVVvv', 0x04034b50, $z64 ? 45 : 20, 0x0800, 0, $time, $date, $crc,
                      $z64 ? 0xFFFFFFFF : $size, $z64 ? 0xFFFFFFFF : $size, strlen($name), strlen($extra)) . $name . $extra);
    }

    public function finish(): void {
        $cdStart = $this->offset;
        foreach ($this->entries as $e) {
            $bigSize = $e['size'] >= 0xFFFFFFFF;
            $bigOff  = $e['offset'] >= 0xFFFFFFFF;
            $x = ($bigSize ? pack('PP', $e['size'], $e['size']) : '') . ($bigOff ? pack('P', $e['offset']) : '');
            $extra = $x !== '' ? pack('vv', 1, strlen($x)) . $x : '';
            $this->w(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 45, ($bigSize || $bigOff) ? 45 : 20, 0x0800, 0, $e['time'], $e['date'], $e['crc'],
                          $bigSize ? 0xFFFFFFFF : $e['size'], $bigSize ? 0xFFFFFFFF : $e['size'],
                          strlen($e['name']), strlen($extra), 0, 0, 0, 0, $bigOff ? 0xFFFFFFFF : $e['offset']) . $e['name'] . $extra);
        }
        $cdSize = $this->offset - $cdStart;
        $count = count($this->entries);
        $z64 = $count >= 0xFFFF || $cdStart >= 0xFFFFFFFF || $cdSize >= 0xFFFFFFFF;
        if ($z64) {
            $recAt = $this->offset;
            $this->w(pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $count, $count, $cdSize, $cdStart));
            $this->w(pack('VVPV', 0x07064b50, 0, $recAt, 1));
        }
        $this->w(pack('VvvvvVVv', 0x06054b50, 0, 0, $z64 ? 0xFFFF : $count, $z64 ? 0xFFFF : $count,
                      $z64 ? 0xFFFFFFFF : $cdSize, $z64 ? 0xFFFFFFFF : $cdStart, 0));
        flush();
    }
}
