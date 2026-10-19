// Обработка книги в браузере: проверка, метаданные, оглавление, обложка,
// конвертация TXT/DOCX/RTF в EPUB. Результат уходит на сервер; оригинал не меняется.
import { api, fileUrl, langMap, humanError } from './common.js'
import { FORMATS, CONVERTERS } from './formats.js'
import { uploadFile } from './upload.js'
import { pdfjs, PDF_OPTS, djvuLib, foliate } from './engines.js'

// Скачать файл книги с прогрессом
export async function fetchBook(book, kind = 'original', onProgress) {
    const r = await fetch(fileUrl(book, kind), { credentials: 'same-origin' })
    if (!r.ok) throw new Error(`Не удалось скачать файл (${r.status})`)
    const total = +r.headers.get('Content-Length') || 0
    if (!onProgress || !r.body || !total) return r.blob()
    const reader = r.body.getReader()
    const chunks = []
    let got = 0
    for (;;) {
        const { done, value } = await reader.read()
        if (done) break
        chunks.push(value)
        got += value.length
        onProgress(got / total)
    }
    return new Blob(chunks)
}

const stripHtml = s => s ? new DOMParser().parseFromString(String(s), 'text/html').body.textContent.trim() : ''
const yearOf = s => (/\b(1[5-9]\d\d|20\d\d)\b/.exec(String(s || '')) || [])[1] || ''

function simplifyToc(items, depth = 0) {
    if (!Array.isArray(items) || depth > 4) return []
    let n = 0
    return items.slice(0, 1500).map(i => ({
        label: stripHtml(langMap(i.label)).slice(0, 200),
        ...(i.href ? { href: String(i.href).slice(0, 500) } : {}),
        ...(i.page ? { page: i.page } : {}),
        ...(i.subitems?.length ? { subitems: simplifyToc(i.subitems, depth + 1) } : {}),
    })).filter(i => i.label && n++ < 1500)
}

// Обложка: уменьшить до 600 px по ширине, JPEG
export async function shrinkImage(blob, maxW = 600) {
    if (!blob || !blob.size) return null
    let img
    try { img = await createImageBitmap(blob) } catch (e) {
        // SVG и редкие форматы — через <img>
        img = await new Promise(res => {
            const el = new Image()
            const url = URL.createObjectURL(blob)
            el.onload = () => { res(el); setTimeout(() => URL.revokeObjectURL(url), 1000) }
            el.onerror = () => { res(null); URL.revokeObjectURL(url) }
            el.src = url
        })
    }
    if (!img || !img.width) return null
    const k = Math.min(1, maxW / img.width)
    const c = document.createElement('canvas')
    c.width = Math.round(img.width * k); c.height = Math.round(img.height * k)
    const ctx = c.getContext('2d')
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height)
    ctx.drawImage(img, 0, 0, c.width, c.height)
    return new Promise(res => c.toBlob(res, 'image/jpeg', 0.85))
}

// ── EPUB · FB2 · MOBI · AZW3 (foliate-js) ────────────────────────────────────
async function extractReflow(file, book) {
    const { makeBook } = await foliate()
    const b = await makeBook(new File([file], 'book.' + FORMATS[book.format].file))
    // целостность: должна открыться первая глава
    const first = b.sections.find(s => s.linear !== 'no') || b.sections[0]
    if (!first) throw new Error('В книге нет ни одной главы')
    await first.createDocument?.()
    const md = b.metadata || {}
    const series = md.belongsTo?.series
    const meta = {
        title: langMap(md.title),
        authors: [].concat(md.author || []).map(langMap).filter(Boolean).join('; '),
        language: String([].concat(md.language || [])[0] || ''),
        publisher: langMap(md.publisher),
        year: yearOf(md.published),
        identifier: langMap([].concat(md.identifier || [])[0]),
        description: stripHtml(md.description).slice(0, 20000),
        series: langMap(Array.isArray(series) ? series[0] : series),
    }
    let cover = null
    try { cover = await b.getCover?.() } catch (e) {}
    const toc = simplifyToc(b.toc)
    b.destroy?.()
    return { meta, toc, cover, warnings: [] }
}

// ── PDF (pdf.js; файл читается с сервера частями, не целиком) ─────────────────
const JUNK_TITLE = /^(microsoft word - |untitled$|документ\d*$|без названия$)|\.(docx?|pdf|indd|tex|odt)$/i

async function extractPdf(book) {
    const lib = await pdfjs()
    const task = lib.getDocument({ url: fileUrl(book), ...PDF_OPTS, disableAutoFetch: true, disableStream: true })
    let pdf
    try { pdf = await task.promise } catch (e) {
        if (e?.name === 'PasswordException') {
            return { meta: {}, toc: [], pages: null, hasText: null,
                     warnings: ['PDF защищён паролем: обложка и оглавление появятся после ввода пароля в читалке'] }
        }
        throw new Error('PDF повреждён или не читается: ' + (e?.message || e))
    }
    try {
        const pages = pdf.numPages
        const md = await pdf.getMetadata().catch(() => null)
        const dcTitle = md?.metadata?.get?.('dc:title')
        const dcCreator = md?.metadata?.get?.('dc:creator')
        let title = String((Array.isArray(dcTitle) ? dcTitle[0] : dcTitle) || md?.info?.Title || '').trim()
        if (JUNK_TITLE.test(title)) title = ''
        const authors = String((Array.isArray(dcCreator) ? dcCreator.join('; ') : dcCreator) || md?.info?.Author || '').trim()

        // оглавление PDF с номерами страниц
        const toc = []
        const outline = await pdf.getOutline().catch(() => null)
        let resolved = 0
        const walk = async (items, out, depth) => {
            for (const it of items || []) {
                const node = { label: String(it.title || '').trim().slice(0, 200) }
                if (resolved < 600 && it.dest) {
                    try {
                        const dest = typeof it.dest === 'string' ? await pdf.getDestination(it.dest) : it.dest
                        if (dest?.[0]) { node.page = (await pdf.getPageIndex(dest[0])) + 1; resolved++ }
                    } catch (e) {}
                }
                if (depth < 4 && it.items?.length) node.subitems = await walk(it.items, [], depth + 1)
                if (node.label) out.push(node)
            }
            return out
        }
        if (outline) await walk(outline, toc, 0)

        // есть ли текстовый слой (иначе это скан — понадобится распознавание)
        const probe = [...new Set([1, 2, 3, Math.ceil(pages / 2), pages].filter(p => p >= 1 && p <= pages))]
        let chars = 0
        for (const p of probe) {
            const tc = await (await pdf.getPage(p)).getTextContent().catch(() => null)
            chars += (tc?.items || []).reduce((s, i) => s + (i.str || '').replace(/\s/g, '').length, 0)
        }
        const hasText = chars >= 40

        // обложка — первая страница
        let cover = null
        try {
            const page = await pdf.getPage(1)
            const vp1 = page.getViewport({ scale: 1 })
            const vp = page.getViewport({ scale: Math.min(2, 500 / vp1.width) })
            const c = document.createElement('canvas')
            c.width = Math.ceil(vp.width); c.height = Math.ceil(vp.height)
            await page.render({ canvasContext: c.getContext('2d'), viewport: vp, background: '#ffffff' }).promise
            cover = await new Promise(res => c.toBlob(res, 'image/jpeg', 0.85))
        } catch (e) {}

        const warnings = hasText ? [] : ['В PDF нет текстового слоя (похоже на скан): поиск и выделение текста недоступны, пока не выполнено распознавание (OCR)']
        return { meta: { title, authors }, toc, pages, hasText, cover, warnings }
    } finally {
        pdf.destroy()
    }
}

// ── DjVu (djvu.js в отдельном потоке) ─────────────────────────────────────────
async function extractDjvu(file) {
    const DjVu = await djvuLib()
    const w = new DjVu.Worker()
    try {
        try { await w.createDocument(await file.arrayBuffer()) }
        catch (e) { throw new Error('DjVu повреждён или не читается: ' + (e?.message || e)) }
        const pages = await w.doc.getPagesQuantity().run()

        const toc = []
        try {
            const contents = await w.doc.getContents().run()
            const conv = async (items, depth) => {
                const out = []
                for (const it of items || []) {
                    const node = { label: String(it.description || '').trim().slice(0, 200) }
                    if (it.url) { try { const n = await w.doc.getPageNumberByUrl(it.url).run(); if (n) node.page = n } catch (e) {} }
                    if (depth < 4 && it.children?.length) node.subitems = await conv(it.children, depth + 1)
                    if (node.label) out.push(node)
                }
                return out
            }
            toc.push(...await conv(contents, 0))
        } catch (e) {}

        let chars = 0
        for (const p of [...new Set([1, 2, Math.ceil(pages / 2)])].filter(p => p >= 1 && p <= pages)) {
            try { chars += String(await w.doc.getPage(p).getText().run() || '').replace(/\s/g, '').length } catch (e) {}
        }
        const hasText = chars >= 40

        // обложка: первая страница, если она не гигантская (скан 600 dpi — сотни МБ в памяти)
        let cover = null
        try {
            const img = await w.doc.getPage(1).getImageData().run()
            if (img?.width && img.width * img.height <= 40_000_000) {
                const c = document.createElement('canvas')
                c.width = img.width; c.height = img.height
                c.getContext('2d').putImageData(img instanceof ImageData ? img : new ImageData(new Uint8ClampedArray(img.data), img.width, img.height), 0, 0)
                cover = await shrinkImage(await new Promise(res => c.toBlob(res, 'image/png')), 600)
            }
        } catch (e) {}

        const warnings = hasText ? [] : ['В DjVu нет текстового слоя: поиск по тексту недоступен, пока не выполнено распознавание (OCR)']
        return { meta: {}, toc, pages, hasText, cover, warnings }
    } finally {
        w.terminate()
    }
}

/**
 * Обработать книгу. file — локальный File (сразу после загрузки), иначе скачаем.
 * force — переобработать уже готовую книгу (например, после обновления конвертера).
 * Возвращает актуальную карточку книги с сервера.
 */
export async function processBook(book, { file, force = false, onStage = () => {} } = {}) {
    const claim = await api('process_claim', { body: { id: book.id, force } })
    if (!claim.claimed) return claim.book
    book = claim.book
    const fmt = FORMATS[book.format]
    try {
        let res
        if (fmt.engine === 'pdf') {
            onStage('Проверка PDF…')
            res = await extractPdf(book)
        } else {
            if (!file) { onStage('Скачивание…'); file = await fetchBook(book, 'original', f => onStage(`Скачивание ${Math.round(f * 100)}%`)) }
            if (fmt.convert) {
                const conv = CONVERTERS[fmt.convert]
                onStage('Конвертация в EPUB…')
                const out = await (await conv.load()).convert(file, { name: book.orig_name })
                onStage('Сохранение версии для чтения…')
                await uploadFile(new File([out.epub], 'derived.epub', { lastModified: Date.now() }), {
                    as: 'derived', extra: { book_id: book.id, converter: conv.name, converter_version: conv.version },
                    onProgress: f => onStage(`Сохранение версии для чтения ${Math.round(f * 100)}%`),
                })
                // обложку и проверку делаем по готовому EPUB — так же, как его откроет читалка
                const check = await extractReflow(out.epub, { format: 'epub' })
                res = { meta: out.meta, toc: out.toc, warnings: out.warnings, structure_hash: out.structureHash, cover: check.cover }
            } else if (fmt.engine === 'djvu') {
                onStage('Проверка DjVu…')
                res = await extractDjvu(file)
            } else {
                onStage('Чтение метаданных…')
                res = await extractReflow(file, book)
            }
        }
        if (res.cover) {
            onStage('Обложка…')
            const img = await shrinkImage(res.cover, 600)
            if (img) await api('cover_upload', { params: { id: book.id }, raw: img }).catch(() => {})
        }
        const r = await api('process_result', { body: {
            id: book.id, ok: true, meta: res.meta || {}, toc: res.toc || [],
            ...(res.pages !== undefined ? { pages: res.pages } : {}),
            ...(res.hasText !== undefined ? { has_text: res.hasText } : {}),
            warnings: res.warnings || [], structure_hash: res.structure_hash || '',
        } })
        return r.book
    } catch (e) {
        console.error(e)
        const r = await api('process_result', { body: { id: book.id, ok: false, error: humanError(e) } }).catch(() => null)
        return r?.book || { ...book, status: 'error', error: humanError(e) }
    }
}
