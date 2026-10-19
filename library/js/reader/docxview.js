// «Вид документа» для DOCX: страницы как в Word (docx-preview) — таблицы, колонтитулы,
// поля, шрифты. Это просмотр оригинала; для чтения на телефоне — режим «Чтение» (EPUB).
// Позиция — доля прокрутки.
import { loadScript, VENDOR } from '../common.js'

export async function open(ctx) {
    await loadScript(VENDOR + '/docx-preview/jszip.min.js')
    await loadScript(VENDOR + '/docx-preview/docx-preview.min.js')
    const wrap = document.createElement('div')
    wrap.className = 'docx-wrap'
    ctx.container.append(wrap)
    try {
        await window.docx.renderAsync(ctx.file, wrap, null, {
            className: 'docx', inWrapper: true, ignoreWidth: false, ignoreHeight: false, breakPages: true,
            renderHeaders: true, renderFooters: true, renderFootnotes: true, renderEndnotes: true, useBase64URL: true,
        })
    } catch (e) {
        throw new Error('Документ не отображается: ' + (e?.message || e))
    }

    const frac = () => wrap.scrollHeight > wrap.clientHeight ? wrap.scrollTop / (wrap.scrollHeight - wrap.clientHeight) : 0
    let t
    wrap.addEventListener('scroll', () => {
        clearTimeout(t)
        t = setTimeout(() => ctx.onRelocate({ locator: { type: 'scroll', fraction: frac() }, fraction: frac(), label: 'Вид документа', detail: '' }), 200)
    })
    const go = f => { wrap.scrollTop = f * (wrap.scrollHeight - wrap.clientHeight) }
    const norm = s => s.toLowerCase().replace(/ё/g, 'е')

    return {
        kind: 'view',
        toc: [...wrap.querySelectorAll('h1, h2, h3')].slice(0, 300).map((h, i) => { h.dataset.tocI = i; return { label: h.textContent.trim().slice(0, 150), target: { el: i }, subitems: [] } }).filter(x => x.label),
        supports: { text: true, search: true, highlights: false, fontSettings: false },
        async restore(l, fraction) { requestAnimationFrame(() => go(l?.type === 'scroll' ? l.fraction : fraction || 0)); return 'fraction' },
        goTo: tg => wrap.querySelector(`[data-toc-i="${tg.el}"]`)?.scrollIntoView({ block: 'start' }),
        goToFraction: go,
        next: () => wrap.scrollBy({ top: wrap.clientHeight * 0.9, behavior: 'smooth' }),
        prev: () => wrap.scrollBy({ top: -wrap.clientHeight * 0.9, behavior: 'smooth' }),
        async search(query, onItem) {
            const q = norm(query)
            let n = 0
            const blocks = [...wrap.querySelectorAll('p, li, td, h1, h2, h3, h4')]
            blocks.forEach((el, i) => {
                const t = el.textContent, tn = norm(t)
                const at = tn.indexOf(q)
                if (at < 0 || n >= 500) return
                n++
                el.dataset.hit = i
                onItem({ label: '', excerpt: { pre: t.slice(Math.max(0, at - 50), at), match: t.slice(at, at + q.length), post: t.slice(at + q.length, at + q.length + 50) }, target: { hit: i } })
            })
            return n
        },
        showSearchHit: tg => wrap.querySelector(`[data-hit="${tg.hit}"]`)?.scrollIntoView({ block: 'center' }),
        clearSearch() {},
        setAnnotations() {}, addAnnotation() {}, removeAnnotation() {},
        bookmarkHere: bookmarks => bookmarks.find(b => b.locator?.type === 'scroll' && Math.abs(b.locator.fraction - frac()) < 0.01) || null,
        here: () => ({ locator: { type: 'scroll', fraction: frac() }, fraction: frac(), chapter: 'Вид документа' }),
        setSettings() {},
        destroy() { wrap.remove() },
    }
}
