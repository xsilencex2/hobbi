// Индекс текста для поиска по содержимому книг. Текст извлекается в браузере
// теми же движками, что и для чтения, и хранится на сервере отдельно от
// оригиналов (таблица lib_text). Сканы без текстового слоя сюда не попадают —
// для них есть распознавание (OCR) в читалке.
import { api, fileUrl } from './common.js'
import { readSource } from './formats.js'
import { fetchBook } from './process.js'
import { getOfflineFile } from './offline.js'
import { pdfjs, PDF_OPTS, djvuLib, foliate } from './engines.js'

const CHUNK = 4000          // символов в одном куске индекса
const BATCH = 900000        // символов в одном запросе — с запасом под post_max_size

export async function indexBook(book, onProgress = () => {}) {
    const src = readSource(book)
    if (!src) throw new Error('Книга ещё не готова к чтению')
    let seq = 0, first = true, buf = [], size = 0
    const send = async done => {
        await api('index_put', { body: { book_id: book.id, replace: first, done, chunks: buf } })
        first = false; buf = []; size = 0
    }
    // делим по пробелам, без перекрытия: одно совпадение — один результат
    const add = async (locator, text) => {
        text = String(text || '').replace(/\s+/g, ' ').trim()
        for (let i = 0; i < text.length;) {
            let end = Math.min(text.length, i + CHUNK)
            if (end < text.length) { const sp = text.lastIndexOf(' ', end); if (sp > i + CHUNK / 2) end = sp }
            buf.push({ seq: seq++, locator, text: text.slice(i, end) })
            size += end - i
            i = end + 1
            if (size >= BATCH) await send(false)
        }
    }

    if (src.engine === 'pdf') {
        if (book.has_text === false) return send(true)          // скан: ищем по OCR
        const lib = await pdfjs()
        const pdf = await lib.getDocument({ url: fileUrl(book, 'original'), ...PDF_OPTS, disableAutoFetch: true, disableStream: true }).promise
        try {
            for (let p = 1; p <= pdf.numPages; p++) {
                const tc = await (await pdf.getPage(p)).getTextContent().catch(() => null)
                await add({ type: 'page', page: p }, (tc?.items || []).map(i => i.str + (i.hasEOL ? ' ' : '')).join(''))
                onProgress(p / pdf.numPages)
            }
        } finally { pdf.destroy() }
    } else if (src.engine === 'djvu') {
        if (book.has_text === false) return send(true)
        const file = await getOfflineFile(book).catch(() => null) || await fetchBook(book, 'original')
        const DjVu = await djvuLib()
        const w = new DjVu.Worker()
        try {
            await w.createDocument(await file.arrayBuffer())
            const n = await w.doc.getPagesQuantity().run()
            for (let p = 1; p <= n; p++) {
                await add({ type: 'page', page: p }, await w.doc.getPage(p).getText().run().catch(() => ''))
                onProgress(p / n)
            }
        } finally { w.terminate() }
    } else {
        const file = await getOfflineFile(book).catch(() => null) || await fetchBook(book, src.kind)
        const { makeBook } = await foliate()
        const b = await makeBook(new File([file], src.name))
        for (const [index, section] of b.sections.entries()) {
            if (!section.createDocument) continue
            const doc = await section.createDocument().catch(() => null)
            if (doc) await add({ type: 'section', index }, (doc.body || doc.documentElement)?.textContent)
            onProgress((index + 1) / b.sections.length)
        }
        b.destroy?.()
    }
    await send(true)
}

export async function clearIndex(bookId) {
    await api('index_clear', { body: bookId ? { book_id: bookId } : {} })
}
