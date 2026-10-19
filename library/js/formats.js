// Реестр форматов: чем открывать, нужна ли конвертация, что умеет каждый способ.
// Новый формат = новая запись здесь (+ определение по содержимому в includes/lib_core.php).
//
// engine:  reflow — foliate-js (адаптивный текст), pdf — pdf.js, djvu — djvu.js
// convert: ключ в CONVERTERS — читается производная EPUB-версия, оригинал остаётся как есть
// file:    расширение, по которому foliate-js узнаёт формат при открытии

export const FORMATS = {
    epub: { label: 'EPUB',       engine: 'reflow', file: 'epub' },
    fb2:  { label: 'FB2',        engine: 'reflow', file: 'fb2' },
    fbz:  { label: 'FB2 в ZIP',  engine: 'reflow', file: 'fb2.zip' },
    mobi: { label: 'MOBI',       engine: 'reflow', file: 'mobi' },
    azw3: { label: 'KF8 / AZW3', engine: 'reflow', file: 'azw3' },
    pdf:  { label: 'PDF',        engine: 'pdf',    file: 'pdf' },
    djvu: { label: 'DjVu',       engine: 'djvu',   file: 'djvu' },
    txt:  { label: 'TXT',        engine: 'reflow', file: 'epub', convert: 'txt' },
    docx: { label: 'DOCX',       engine: 'reflow', file: 'epub', convert: 'docx', view: 'docx' },
    rtf:  { label: 'RTF',        engine: 'reflow', file: 'epub', convert: 'rtf' },
}

// Конвертеры в EPUB. Версия растёт при изменении результата — книги, сделанные
// старой версией, можно переобработать (закладки и заметки не удаляются).
export const CONVERTERS = {
    txt:  { name: 'txt2epub',  version: 1, load: () => import('./convert/txt.js') },
    docx: { name: 'docx2epub', version: 1, load: () => import('./convert/docx.js') },
    rtf:  { name: 'rtf2epub',  version: 1, load: () => import('./convert/rtf.js') },
}

export const STATUS = {
    uploaded:         'Загружена',
    checking:         'Проверяется',
    ready:            'Готова к чтению',
    needs_conversion: 'Требует конвертации',
    converting:       'Конвертируется',
    converted:        'Успешно обработана',
    error:            'Ошибка обработки',
    unsupported:      'Формат не поддерживается',
}

export const READ_STATUS = {
    new:      'Не начата',
    want:     'Хочу прочитать',
    planned:  'Запланирована',
    reading:  'Читаю сейчас',
    paused:   'Отложена',
    finished: 'Прочитана',
}

// Что открывать в читалке: оригинал или производную версию
export function readSource(book) {
    const f = FORMATS[book.format]
    if (!f) return null
    if (f.convert) {
        if (!book.derived) return null
        return { kind: 'derived', engine: 'reflow', name: 'book.epub', converted: true }
    }
    return { kind: 'original', engine: f.engine, name: 'book.' + f.file, converted: false }
}

// Можно ли открыть книгу прямо сейчас
export function canRead(book) {
    if (!readSource(book)) return false
    if (['ready', 'converted'].includes(book.status)) return true
    // родной формат с неудачным извлечением метаданных всё равно можно попробовать открыть
    return !FORMATS[book.format].convert && ['uploaded', 'checking', 'error'].includes(book.status)
}

// Требует ли книга обработки в браузере (метаданные / обложка / конвертация)
export function needsProcessing(book) {
    if (!FORMATS[book.format] || book.drm) return false
    if (['uploaded', 'needs_conversion'].includes(book.status)) return true
    const stale = !book.status_at || Date.now() - Date.parse(book.status_at.replace(' ', 'T')) > 15 * 60 * 1000
    return ['checking', 'converting'].includes(book.status) && stale
}

// Старая версия конвертера — предлагаем переобработать
export function outdatedConverter(book) {
    const key = FORMATS[book.format]?.convert
    return !!key && book.derived && book.converter_version < CONVERTERS[key].version
}
