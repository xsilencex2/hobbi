// Движок адаптивного текста на foliate-js: EPUB, FB2, MOBI, KF8/AZW3
// и EPUB-версии, сделанные из TXT / DOCX / RTF.
// Позиция — EPUB CFI (устойчива к смене шрифта и размера экрана), запасной
// вариант — доля прочитанного.
import { foliate } from '../engines.js'
import { VENDOR } from '../common.js'

export const COLORS = { yellow: '#f5d14a', green: '#7fd17f', blue: '#7fb6f0', pink: '#f08fb5', purple: '#b59af0' }
const FONTS = {
    serif: `Georgia, 'Times New Roman', 'PT Serif', 'Noto Serif', serif`,
    sans: `'Inter', 'Segoe UI', system-ui, -apple-system, Roboto, sans-serif`,
    mono: `ui-monospace, 'Cascadia Mono', Menlo, Consolas, monospace`,
}
const THEME = {
    light: { fg: '#1d1d1b', bg: '#fbfbf9', link: '#0b7a5c' },
    warm:  { fg: '#3b2f1e', bg: '#f4ecd8', link: '#8a5a1e' },
    dark:  { fg: '#dcdcd6', bg: '#121313', link: '#5fd4b0' },
}
const GAP = { narrow: '4%', normal: '7%', wide: '12%' }

function css(s) {
    const t = THEME[s.theme] || THEME.light
    const font = s.font && s.font !== 'publisher' ? FONTS[s.font] : null
    return `
    @namespace epub "http://www.idpf.org/2007/ops";
    html { color: ${t.fg}; background: ${t.bg} !important; font-size: ${s.fontSize}% !important; color-scheme: ${s.theme === 'dark' ? 'dark' : 'light'}; }
    body { color: ${t.fg}; background: transparent !important; }
    ${font ? `body, p, li, div, blockquote, dd, dt, td, th, span, a, em, strong, i, b, h1, h2, h3, h4, h5, h6 { font-family: ${font} !important; }
    pre, code, kbd, samp { font-family: ${FONTS.mono} !important; }` : ''}
    p, li, blockquote, dd {
        line-height: ${s.lineHeight} !important;
        text-align: ${s.justify ? 'justify' : 'start'};
        -webkit-hyphens: ${s.hyphens ? 'auto' : 'manual'}; hyphens: ${s.hyphens ? 'auto' : 'manual'};
        widows: 2; orphans: 2;
    }
    [align="left"] { text-align: left; } [align="right"] { text-align: right; } [align="center"] { text-align: center; }
    a:link, a:visited { color: ${t.link}; }
    ${s.theme === 'dark' ? `body *:not(img):not(svg):not(svg *):not(video) { color: inherit !important; background-color: transparent !important; border-color: #444 !important; }
    img { opacity: .9; }` : ''}
    ${s.theme === 'warm' ? `body *:not(img):not(svg):not(svg *) { background-color: transparent !important; }` : ''}
    pre { white-space: pre-wrap !important; }
    aside[epub|type~="footnote"], aside[epub|type~="endnote"], aside[epub|type~="note"], aside[epub|type~="rearnote"] { display: none; }
    `
}

const mapToc = items => (items || []).map(i => ({ label: i.label, target: i.href, subitems: mapToc(i.subitems) }))

export async function open(ctx) {
    const { makeBook } = await foliate()
    const { Overlayer } = await import(VENDOR + '/foliate-js/overlayer.js')
    const CFI = await import(VENDOR + '/foliate-js/epubcfi.js')
    const { FootnoteHandler } = await import(VENDOR + '/foliate-js/footnotes.js')

    let book
    try { book = await makeBook(new File([ctx.file], ctx.fileName)) }
    catch (e) { throw new Error('Книга не открывается: ' + (e.message || e)) }
    // битые ресурсы внутри книги (картинка, шрифт) не должны ронять главу
    book.transformTarget?.addEventListener('data', ({ detail }) => {
        detail.data = Promise.resolve(detail.data).catch(e => { console.warn('ресурс книги:', detail.name, e); return '' })
    })

    const view = document.createElement('foliate-view')
    ctx.container.append(view)
    await view.open(book)

    let settings = ctx.settings
    const layout = () => {
        const r = view.renderer
        r.setAttribute('flow', settings.flow === 'scrolled' ? 'scrolled' : 'paginated')
        r.setAttribute('gap', GAP[settings.margin] || GAP.normal)
        r.setAttribute('max-inline-size', (settings.width || 720) + 'px')
        r.setAttribute('max-column-count', settings.columns === 1 ? '1' : '2')
        r.setAttribute('margin', '40px')
        r.toggleAttribute('animated', settings.flow !== 'scrolled')
        r.setStyles?.(css(settings))
    }
    layout()

    // ── выделения и заметки ──
    const anns = new Map()            // cfi → заметка
    const indexOfCfi = cfi => { try { return view.resolveCFI(cfi)?.index } catch (e) { return null } }
    const drawFor = index => { for (const [cfi, a] of anns) if (a._index === index) view.addAnnotation({ value: cfi }).catch?.(() => {}) }
    view.addEventListener('create-overlay', e => drawFor(e.detail.index))
    view.addEventListener('draw-annotation', e => {
        const { draw, annotation } = e.detail
        const a = anns.get(annotation.value)
        const color = COLORS[a?.color] || COLORS.yellow
        if (a?.type === 'quote') draw(Overlayer.underline, { color, width: 2 })
        else draw(Overlayer.highlight, { color })
    })
    view.addEventListener('show-annotation', e => { const a = anns.get(e.detail.value); if (a) ctx.onAnnotationClick(a) })

    // ── сноски во всплывающем окне ──
    const footnotes = new FootnoteHandler()
    footnotes.addEventListener('before-render', e => {
        const r = e.detail.view.renderer
        r.setAttribute('flow', 'scrolled'); r.setAttribute('margin', '0px'); r.setAttribute('gap', '5%')
        r.setStyles?.(css({ ...settings, fontSize: Math.min(settings.fontSize, 110) }))
    })
    footnotes.addEventListener('render', e => ctx.onFootnote(e.detail.view))
    view.addEventListener('link', e => { footnotes.handle(book, e)?.catch(err => console.warn('сноска:', err)) })

    // ── выделение текста, касания, клавиши внутри книги ──
    view.addEventListener('load', e => {
        const { doc, index } = e.detail
        const check = () => {
            const sel = doc.getSelection()
            if (!sel || sel.isCollapsed || !sel.rangeCount) { ctx.onSelection(null); return }
            const text = sel.toString().trim()
            if (!text) return
            const range = sel.getRangeAt(0)
            const frame = doc.defaultView.frameElement?.getBoundingClientRect() || { left: 0, top: 0 }
            const rects = [...range.getClientRects()]
            const r0 = rects[0] || range.getBoundingClientRect(), r1 = rects[rects.length - 1] || r0
            const cfi = view.getCFI(index, range)
            const { tocItem } = view.getProgressOf(index, range)
            ctx.onSelection({
                text, locator: { type: 'cfi', cfi }, chapter: tocItem?.label || '',
                fraction: view.lastLocation?.fraction || 0,
                rect: { left: frame.left + r0.left, top: frame.top + r0.top, right: frame.left + r1.right, bottom: frame.top + r1.bottom },
                clear: () => sel.removeAllRanges(),
            })
        }
        doc.addEventListener('pointerup', () => setTimeout(check, 20))
        doc.addEventListener('keyup', e => { if (e.shiftKey) check() })
        doc.addEventListener('selectionchange', () => { if (doc.getSelection()?.isCollapsed) ctx.onSelection(null) })
        doc.addEventListener('keydown', e => ctx.onKey(e))
        doc.addEventListener('click', e => {
            if (e.target.closest('a[href]') || !doc.getSelection()?.isCollapsed) return
            const frame = doc.defaultView.frameElement?.getBoundingClientRect() || { left: 0 }
            ctx.onTap(frame.left + e.clientX)
        })
    })

    let last = null
    view.addEventListener('relocate', e => {
        last = e.detail
        const loc = last.location
        ctx.onRelocate({
            locator: { type: 'cfi', cfi: last.cfi },
            fraction: last.fraction || 0,
            label: last.tocItem?.label || '',
            detail: last.pageItem?.label ? `стр. ${last.pageItem.label}` : loc ? `${loc.current + 1} из ${loc.total}` : '',
            tocHref: last.tocItem?.href,
        })
    })

    return {
        kind: 'reflow',
        toc: mapToc(book.toc),
        dir: book.dir,
        sectionFractions: view.getSectionFractions(),
        supports: { text: true, search: true, highlights: true, fontSettings: true },

        async restore(loc, fraction) {
            if (loc?.type === 'cfi' && loc.cfi && view.resolveNavigation(loc.cfi)?.index != null) {
                await view.init({ lastLocation: loc.cfi })
                return 'exact'
            }
            if (loc?.type === 'section' && book.sections[loc.index]) { await view.goTo(loc.index); return 'section' }
            if (fraction > 0) { await view.init({ lastLocation: { fraction } }); return 'fraction' }
            await view.init({ showTextStart: true })
            return 'start'
        },
        goTo: target => view.goTo(target),
        goToFraction: f => view.goToFraction(f),
        next: () => view.goRight(),
        prev: () => view.goLeft(),
        forward: () => view.next(),

        async search(query, onItem) {
            let n = 0
            for await (const r of view.search({ query, matchCase: false, matchDiacritics: false, matchWholeWords: false })) {
                if (r === 'done') break
                if (r.progress != null) { onItem({ progress: r.progress }); continue }
                for (const s of r.subitems || []) {
                    if (++n > 1000) return n
                    onItem({ label: r.label, excerpt: s.excerpt, target: s.cfi })
                }
            }
            return n
        },
        clearSearch: () => view.clearSearch(),
        select: cfi => view.select(cfi),

        setAnnotations(list) {
            for (const cfi of anns.keys()) view.deleteAnnotation({ value: cfi }).catch?.(() => {})
            anns.clear()
            for (const a of list) if (a.type !== 'bookmark' && a.locator?.type === 'cfi') {
                a._index = indexOfCfi(a.locator.cfi)
                anns.set(a.locator.cfi, a)
            }
            for (const { index } of view.renderer.getContents()) drawFor(index)
        },
        addAnnotation(a) {
            a._index = indexOfCfi(a.locator.cfi)
            anns.set(a.locator.cfi, a)
            view.addAnnotation({ value: a.locator.cfi }).catch?.(() => {})
        },
        removeAnnotation(a) {
            anns.delete(a.locator?.cfi)
            view.deleteAnnotation({ value: a.locator?.cfi }).catch?.(() => {})
        },

        // закладка, попадающая на видимую страницу
        bookmarkHere(bookmarks) {
            if (!last?.cfi) return null
            const start = CFI.collapse(last.cfi), end = CFI.collapse(last.cfi, true)
            return bookmarks.find(b => {
                const c = b.locator?.cfi
                if (!c) return false
                try { return CFI.compare(start, c) <= 0 && CFI.compare(c, end) <= 0 } catch (e) { return false }
            }) || null
        },
        here() {
            if (!last) return null
            return { locator: { type: 'cfi', cfi: CFI.collapse(last.cfi) }, fraction: last.fraction || 0, chapter: last.tocItem?.label || '' }
        },

        setSettings(s) { settings = s; layout() },
        destroy() { view.close(); view.remove() },
    }
}
