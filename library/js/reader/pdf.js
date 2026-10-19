// Движок PDF на pdf.js: непрерывная прокрутка / постранично, масштаб, подгонка,
// переход к странице, полноэкранный режим, оглавление, поиск, выделения.
// Позиция — номер страницы + место на странице (координаты PDF), не просто номер.
import { pdfjs, pdfViewer, PDF_OPTS } from '../engines.js'
import { esc } from '../common.js'
import { COLORS } from './reflow.js'

const norm = s => s.toLowerCase().replace(/ё/g, 'е')

export async function open(ctx) {
    const lib = await pdfjs()
    const V = await pdfViewer()
    const wrap = document.createElement('div')
    wrap.className = 'pdf-wrap'
    const inner = document.createElement('div')
    inner.className = 'pdfViewer'
    wrap.append(inner)
    ctx.container.append(wrap)

    let settings = ctx.settings
    const eventBus = new V.EventBus()
    const linkService = new V.PDFLinkService({ eventBus, externalLinkTarget: 2 })
    const findController = new V.PDFFindController({ eventBus, linkService })
    const mobile = matchMedia('(max-width: 768px)').matches
    const viewer = new V.PDFViewer({
        container: wrap, viewer: inner, eventBus, linkService, findController,
        textLayerMode: 1, removePageBorders: true,
        maxCanvasPixels: mobile ? 4096 * 4096 : 8192 * 8192,
    })
    linkService.setViewer(viewer)

    const src = ctx.url ? { url: ctx.url } : { data: new Uint8Array(await ctx.file.arrayBuffer()) }
    const task = lib.getDocument({ ...src, ...PDF_OPTS, disableAutoFetch: true, disableStream: true, enableScripting: false })
    task.onPassword = (update, reason) => {
        ctx.askPassword(reason === lib.PasswordResponses.INCORRECT_PASSWORD).then(p => p == null ? task.destroy() : update(p))
    }
    let pdf
    try { pdf = await task.promise } catch (e) {
        if (e?.name === 'PasswordException') throw new Error('PDF защищён паролем — пароль не введён')
        throw new Error('PDF не открывается: ' + (e?.message || e))
    }
    const total = pdf.numPages
    const pagesInit = new Promise(r => eventBus.on('pagesinit', r, { once: true }))
    viewer.setDocument(pdf)
    linkService.setDocument(pdf, null)
    await pagesInit

    const applyView = () => {
        viewer.scrollMode = settings.pdf?.scroll === 'page' ? V.ScrollMode.PAGE : V.ScrollMode.VERTICAL
        viewer.currentScaleValue = settings.pdf?.zoom || 'page-width'
        wrap.classList.toggle('invert', !!settings.pdf?.invert)
    }
    applyView()

    // ── оглавление (outline) с номерами страниц ──
    const toc = []
    const outline = await pdf.getOutline().catch(() => null)
    const flat = []
    const conv = async (items, depth) => {
        const out = []
        for (const it of items || []) {
            const node = { label: String(it.title || '').trim(), target: { dest: it.dest, url: it.url }, subitems: [] }
            try {
                const dest = typeof it.dest === 'string' ? await pdf.getDestination(it.dest) : it.dest
                if (dest?.[0]) { node.page = (await pdf.getPageIndex(dest[0])) + 1; node.target.page = node.page; flat.push(node) }
            } catch (e) {}
            if (depth < 5 && it.items?.length) node.subitems = await conv(it.items, depth + 1)
            if (node.label) out.push(node)
        }
        return out
    }
    if (outline) toc.push(...await conv(outline, 0))
    flat.sort((a, b) => a.page - b.page)
    const chapterOf = page => { let c = ''; for (const n of flat) { if (n.page <= page) c = n.label; else break } return c }

    // ── позиция ──
    let loc = { page: 1, top: null, left: null }
    eventBus.on('updateviewarea', ({ location }) => {
        if (!location) return
        loc = { page: location.pageNumber, top: Math.round(location.top), left: Math.round(location.left) }
        const pv = viewer.getPageView(loc.page - 1)
        const h = pv?.viewport?.viewBox ? pv.viewport.viewBox[3] - pv.viewport.viewBox[1] : 0
        const intra = h && location.top != null ? Math.min(1, Math.max(0, 1 - location.top / h)) : 0
        ctx.onRelocate({
            locator: { type: 'page', page: loc.page, top: loc.top, left: loc.left },
            fraction: Math.min(1, (loc.page - 1 + intra) / total),
            label: chapterOf(loc.page),
            detail: `стр. ${loc.page} из ${total}`,
            page: loc.page,
        })
        pageInput.value = loc.page
    })

    // ── выделения поверх страниц (координаты в долях страницы — не зависят от масштаба) ──
    const anns = new Map()
    const drawPage = (pageNumber, div) => {
        div.querySelector('.hl-layer')?.remove()
        const list = [...anns.values()].filter(a => a.locator.page === pageNumber && a.locator.rects?.length)
        if (!list.length) return
        const layer = document.createElement('div')
        layer.className = 'hl-layer'
        for (const a of list) for (const [x, y, w, h] of a.locator.rects) {
            const r = document.createElement('div')
            if (a.type === 'quote') { r.className = 'underline'; r.style.borderColor = COLORS[a.color] || COLORS.yellow }
            else r.style.background = COLORS[a.color] || COLORS.yellow
            Object.assign(r.style, { left: x * 100 + '%', top: y * 100 + '%', width: w * 100 + '%', height: h * 100 + '%' })
            r.onclick = () => ctx.onAnnotationClick(a)
            layer.append(r)
        }
        div.append(layer)
    }
    const redraw = page => { const pv = viewer.getPageView(page - 1); if (pv?.div) drawPage(page, pv.div) }
    eventBus.on('pagerendered', ({ pageNumber, source }) => drawPage(pageNumber, source.div))

    // выделение текста в текстовом слое
    const onUp = () => setTimeout(() => {
        const sel = getSelection()
        if (!sel || sel.isCollapsed || !sel.rangeCount || !wrap.contains(sel.anchorNode)) { ctx.onSelection(null); return }
        const text = sel.toString().replace(/\s+/g, ' ').trim()
        if (!text) return
        const range = sel.getRangeAt(0)
        const pageDiv = (range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement)?.closest('.page')
        if (!pageDiv) return
        const page = +pageDiv.dataset.pageNumber
        const pr = pageDiv.getBoundingClientRect()
        const seen = new Set()
        const rects = [...range.getClientRects()]
            .filter(r => r.width > 1 && r.height > 1 && r.bottom > pr.top && r.top < pr.bottom)
            .map(r => [(r.left - pr.left) / pr.width, (r.top - pr.top) / pr.height, r.width / pr.width, r.height / pr.height].map(v => Math.round(v * 10000) / 10000))
            .filter(r => { const k = r.join(); if (seen.has(k)) return false; seen.add(k); return true })
            .slice(0, 400)
        const box = range.getBoundingClientRect()
        ctx.onSelection({
            text, chapter: chapterOf(page), fraction: (page - 1) / total,
            locator: { type: 'page', page, rects },
            rect: { left: box.left, top: box.top, right: box.right, bottom: box.bottom },
            clear: () => sel.removeAllRanges(),
        })
    }, 20)
    wrap.addEventListener('pointerup', onUp)
    wrap.addEventListener('keyup', e => { if (e.shiftKey) onUp() })
    wrap.addEventListener('click', e => { if (!e.target.closest('a') && getSelection().isCollapsed && !e.target.closest('.hl-layer')) ctx.onTap(e.clientX, true) })

    // ── панель инструментов: масштаб, режим, страница, полный экран ──
    const tools = document.createElement('div')
    tools.className = 'pdf-tools'
    tools.innerHTML = `
        <button class="rb" data-z="out" title="Уменьшить">−</button>
        <button class="rb" data-z="in" title="Увеличить">+</button>
        <button class="rb" data-z="page-width" title="По ширине">↔</button>
        <button class="rb" data-z="page-fit" title="Страница целиком">↕</button>
        <button class="rb" data-mode title="Постранично / прокрутка">▤</button>
        <input type="number" min="1" max="${total}" value="1" aria-label="Страница"><span>/ ${total}</span>
        <button class="rb" data-fs title="Во весь экран">⤢</button>`
    ctx.container.append(tools)
    const pageInput = tools.querySelector('input')
    const saveView = patch => { settings = { ...settings, pdf: { ...settings.pdf, ...patch } }; ctx.onEngineSettings({ pdf: settings.pdf }) }
    tools.addEventListener('click', e => {
        const b = e.target.closest('button')
        if (!b) return
        if (b.dataset.z === 'in') { viewer.increaseScale(); saveView({ zoom: String(viewer.currentScale) }) }
        else if (b.dataset.z === 'out') { viewer.decreaseScale(); saveView({ zoom: String(viewer.currentScale) }) }
        else if (b.dataset.z) { viewer.currentScaleValue = b.dataset.z; saveView({ zoom: b.dataset.z }) }
        else if ('mode' in b.dataset) {
            const page = viewer.currentPageNumber
            saveView({ scroll: settings.pdf?.scroll === 'page' ? 'continuous' : 'page' })
            viewer.scrollMode = settings.pdf.scroll === 'page' ? V.ScrollMode.PAGE : V.ScrollMode.VERTICAL
            viewer.currentPageNumber = page
            ctx.toast(settings.pdf.scroll === 'page' ? 'Постраничный режим' : 'Непрерывная прокрутка')
        } else if ('fs' in b.dataset) {
            const el = document.documentElement
            document.fullscreenElement ? document.exitFullscreen?.() : (el.requestFullscreen || el.webkitRequestFullscreen)?.call(el)
        }
    })
    pageInput.addEventListener('change', () => { const p = Math.max(1, Math.min(total, +pageInput.value || 1)); viewer.currentPageNumber = p })
    pageInput.addEventListener('keydown', e => e.stopPropagation())

    // ── поиск: список совпадений по страницам, подсветка через pdf.js ──
    const texts = new Map()
    const pageText = async p => {
        if (!texts.has(p)) {
            const tc = await (await pdf.getPage(p)).getTextContent()
            texts.set(p, tc.items.map(i => i.str + (i.hasEOL ? '\n' : '')).join(''))
        }
        return texts.get(p)
    }
    let lastQuery = ''

    return {
        kind: 'pdf',
        toc,
        total,
        supports: { text: true, search: true, highlights: true, fontSettings: false, zoom: true },

        async restore(l, fraction) {
            if (l?.type === 'page' && l.page) {
                viewer.scrollPageIntoView({ pageNumber: Math.min(total, l.page), destArray: l.top != null ? [null, { name: 'XYZ' }, l.left ?? null, l.top, null] : undefined, allowNegativeOffset: true })
                return 'exact'
            }
            if (fraction > 0) { viewer.currentPageNumber = Math.max(1, Math.min(total, Math.round(fraction * total) + 1)); return 'fraction' }
            return 'start'
        },
        goTo(t) {
            if (t?.dest) return linkService.goToDestination(t.dest)
            if (t?.page) { viewer.currentPageNumber = t.page; return }
            if (t?.url) window.open(t.url, '_blank', 'noopener')
        },
        goToFraction: f => { viewer.currentPageNumber = Math.max(1, Math.min(total, Math.round(f * total) + 1)) },
        next: () => viewer.nextPage(),
        prev: () => viewer.previousPage(),

        async search(query, onItem) {
            lastQuery = query
            const q = norm(query)
            let n = 0
            for (let p = 1; p <= total; p++) {
                if (lastQuery !== query) return n
                const t = await pageText(p).catch(() => '')
                const tn = norm(t)
                for (let i = tn.indexOf(q); i >= 0 && n < 1000; i = tn.indexOf(q, i + q.length)) {
                    n++
                    onItem({ label: `Страница ${p}`, excerpt: { pre: t.slice(Math.max(0, i - 50), i), match: t.slice(i, i + q.length), post: t.slice(i + q.length, i + q.length + 50) }, target: { page: p, query } })
                }
                if (p % 10 === 0) onItem({ progress: p / total })
            }
            return n
        },
        hasTextLayer: async () => {
            let c = 0
            for (const p of [1, 2, Math.ceil(total / 2)].filter(p => p <= total)) c += (await pageText(p).catch(() => '')).replace(/\s/g, '').length
            return c >= 40
        },
        showSearchHit(t) {
            viewer.currentPageNumber = t.page
            eventBus.dispatch('find', { source: null, type: '', query: t.query, caseSensitive: false, entireWord: false,
                                        highlightAll: true, findPrevious: false, matchDiacritics: false })
        },
        clearSearch() { lastQuery = ''; eventBus.dispatch('findbarclose', { source: null }) },

        setAnnotations(list) {
            const pages = new Set([...anns.values()].map(a => a.locator.page))
            anns.clear()
            for (const a of list) if (a.type !== 'bookmark' && a.locator?.type === 'page') { anns.set(a.id, a); pages.add(a.locator.page) }
            pages.forEach(redraw)
        },
        addAnnotation(a) { anns.set(a.id, a); redraw(a.locator.page) },
        removeAnnotation(a) { anns.delete(a.id); redraw(a.locator.page) },

        bookmarkHere: bookmarks => bookmarks.find(b => b.locator?.page === loc.page) || null,
        here: () => ({ locator: { type: 'page', page: loc.page, top: loc.top, left: loc.left }, fraction: (loc.page - 1) / total, chapter: chapterOf(loc.page) }),
        pageCount: () => total,
        renderPageImage: async (p, scale = 2) => {
            const page = await pdf.getPage(p)
            const vp = page.getViewport({ scale })
            const c = document.createElement('canvas')
            c.width = Math.ceil(vp.width); c.height = Math.ceil(vp.height)
            await page.render({ canvasContext: c.getContext('2d'), viewport: vp, background: '#ffffff' }).promise
            return c
        },

        setSettings(s) { settings = s; applyView() },
        destroy() { tools.remove(); viewer.cleanup?.(); task.destroy(); wrap.remove() },
    }
}
