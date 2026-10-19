// Движок DjVu: готовый просмотрщик djvu.js (масштаб, режимы, страницы, полный экран)
// + наша позиция, закладки и поиск. Поиск — только если в файле есть текстовый слой
// или выполнено распознавание (OCR); иначе честно говорим, что искать негде.
import { djvuViewerLib } from '../engines.js'

export async function open(ctx) {
    const DjVu = await djvuViewerLib()
    const wrap = document.createElement('div')
    wrap.className = 'djvu-wrap'
    ctx.container.append(wrap)
    const v = new DjVu.Viewer()
    v.render(wrap)
    const buffer = await ctx.file.arrayBuffer()
    try { await v.loadDocument(buffer.slice(0), ctx.book.title || ctx.book.orig_name) }
    catch (e) { throw new Error('DjVu не открывается: ' + (e?.message || e)) }
    v.configure({
        language: 'ru',
        theme: ctx.settings.theme === 'dark' ? 'dark' : 'light',
        uiOptions: { hideOpenAndCloseButtons: true, hidePrintButton: true, hideSaveButton: true, showContentsAutomatically: false },
    })

    const total = ctx.book.pages || 0
    const flat = []
    const walk = items => (items || []).forEach(i => { if (i.page) flat.push(i); walk(i.subitems) })
    walk(ctx.book.toc)
    flat.sort((a, b) => a.page - b.page)
    const chapterOf = p => { let c = ''; for (const n of flat) { if (n.page <= p) c = n.label; else break } return c }
    const mapToc = items => (items || []).map(i => ({ label: i.label, page: i.page, target: { page: i.page }, subitems: mapToc(i.subitems) }))

    let page = 1
    const report = () => {
        const p = v.getPageNumber()
        if (!p) return
        page = p
        ctx.onRelocate({
            locator: { type: 'page', page: p },
            fraction: total > 1 ? (p - 1) / (total - 1) : 1,
            label: chapterOf(p),
            detail: total ? `стр. ${p} из ${total}` : `стр. ${p}`,
            page: p,
        })
    }
    const Events = DjVu.Viewer.Events || {}
    if (Events.PAGE_NUMBER_CHANGED && v.on) v.on(Events.PAGE_NUMBER_CHANGED, report)
    const poll = setInterval(() => { if (v.getPageNumber() !== page) report() }, 1500)   // подстраховка
    const go = p => v.configure({ pageNumber: Math.max(1, total ? Math.min(total, p) : p) })

    // текстовый слой для поиска — через отдельный поток djvu.js
    let worker = null
    const pageText = async p => {
        if (!worker) { worker = new DjVu.Worker(); await worker.createDocument(buffer.slice(0)) }
        return String(await worker.doc.getPage(p).getText().run() || '')
    }
    const norm = s => s.toLowerCase().replace(/ё/g, 'е')

    return {
        kind: 'djvu',
        toc: mapToc(ctx.book.toc),
        total,
        supports: { text: ctx.book.has_text !== false, search: true, highlights: false, fontSettings: false, zoom: true },
        async restore(l, fraction) {
            if (l?.type === 'page' && l.page) { go(l.page); return 'exact' }
            if (fraction > 0 && total) { go(Math.round(fraction * (total - 1)) + 1); return 'fraction' }
            return 'start'
        },
        goTo: t => t?.page && go(t.page),
        goToFraction: f => total && go(Math.round(f * (total - 1)) + 1),
        next: () => go(page + 1),
        prev: () => go(page - 1),
        async search(query, onItem) {
            if (ctx.book.has_text === false) return -1          // нет текстового слоя — ищем в OCR (main.js)
            const q = norm(query)
            let n = 0
            for (let p = 1; p <= total && n < 1000; p++) {
                const t = await pageText(p).catch(() => '')
                const tn = norm(t)
                for (let i = tn.indexOf(q); i >= 0 && n < 1000; i = tn.indexOf(q, i + q.length)) {
                    n++
                    onItem({ label: `Страница ${p}`, excerpt: { pre: t.slice(Math.max(0, i - 50), i), match: t.slice(i, i + q.length), post: t.slice(i + q.length, i + q.length + 50) }, target: { page: p } })
                }
                if (p % 10 === 0) onItem({ progress: p / total })
            }
            return n
        },
        showSearchHit: t => go(t.page),
        clearSearch() {},
        setAnnotations() {},
        addAnnotation() {},
        removeAnnotation() {},
        bookmarkHere: bookmarks => bookmarks.find(b => b.locator?.page === page) || null,
        here: () => ({ locator: { type: 'page', page }, fraction: total > 1 ? (page - 1) / (total - 1) : 0, chapter: chapterOf(page) }),
        pageCount: () => total,
        // картинка страницы для распознавания (OCR)
        renderPageImage: async p => {
            if (!worker) { worker = new DjVu.Worker(); await worker.createDocument(buffer.slice(0)) }
            const img = await worker.doc.getPage(p).getImageData().run()
            const c = document.createElement('canvas')
            c.width = img.width; c.height = img.height
            c.getContext('2d').putImageData(img instanceof ImageData ? img : new ImageData(new Uint8ClampedArray(img.data), img.width, img.height), 0, 0)
            return c
        },
        setSettings(s) { v.configure({ theme: s.theme === 'dark' ? 'dark' : 'light' }) },
        destroy() { clearInterval(poll); worker?.terminate(); v.destroy?.(); wrap.remove() },
    }
}
