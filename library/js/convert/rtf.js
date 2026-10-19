// RTF → EPUB через rtf.js (+ WMF/EMF-картинки как SVG).
// В RTF нет разметки «это заголовок», поэтому заголовки угадываем по размеру
// шрифта и жирности; таблицы rtf.js не поддерживает — предупреждаем.
import { loadScript, VENDOR } from '../common.js'
import { htmlToEpub, newBody } from './epub-writer.js'

const CHAPTER = /^(глава|часть|книга|раздел|пролог|эпилог|предисловие|послесловие|введение|заключение|chapter|part|prologue|epilogue|боб|қисм|фасл)(\s|$|[.:—-])/i

// Размер шрифта абзаца (максимальный в нём, в pt) и жирный ли он целиком
function paraStyle(el) {
    let size = 0, bold = true, any = false
    const walk = n => {
        if (n.nodeType === 3) {
            if (!n.textContent.trim()) return
            any = true
            const p = n.parentElement
            const cs = p.style
            const fs = parseFloat(cs.fontSize) || 0
            if (fs > size) size = fs
            const w = cs.fontWeight
            if (!(w === 'bold' || parseInt(w) >= 600 || p.closest('b,strong'))) bold = false
            return
        }
        n.childNodes.forEach(walk)
    }
    walk(el)
    return { size, bold: any && bold }
}

export async function convert(file, { name }) {
    await loadScript(VENDOR + '/rtf/WMFJS.bundle.min.js')
    await loadScript(VENDOR + '/rtf/EMFJS.bundle.min.js')
    await loadScript(VENDOR + '/rtf/RTFJS.bundle.min.js')
    const { RTFJS, WMFJS, EMFJS } = window
    RTFJS.loggingEnabled(false); WMFJS.loggingEnabled(false); EMFJS.loggingEnabled(false)

    const ab = await file.arrayBuffer()
    const head = new TextDecoder('latin1').decode(new Uint8Array(ab))
    const warnings = []
    if (/\\trowd/.test(head)) warnings.push('Таблицы показаны как обычные абзацы: конвертер RTF не умеет таблицы. Точный вид — в оригинале.')
    if (/\\object\b/.test(head)) warnings.push('Встроенные объекты (OLE) не перенесены')
    if (/\\field[^}]*\{\\\*\\fldinst[^}]*\bEQ\b/.test(head)) warnings.push('Формулы (поля EQ) не перенесены')

    let elements
    try {
        const doc = new RTFJS.Document(ab, {})
        var meta = doc.metadata() || {}
        elements = await doc.render()
    } catch (e) {
        throw new Error('Не удалось разобрать RTF: ' + (e.message || e))
    }

    // абзацы rtf.js → p / h1 / h2
    const body = newBody()
    const d = body.ownerDocument
    const paras = []
    for (const el of elements) {
        const text = (el.textContent || '').replace(/\s+/g, ' ').trim()
        const hasMedia = el.querySelector?.('img, svg')
        if (!text && !hasMedia) continue
        paras.push({ el, text, ...paraStyle(el) })
    }
    const sizes = paras.filter(p => p.text.length > 80).map(p => p.size).filter(Boolean).sort((a, b) => a - b)
    const base = sizes.length ? sizes[Math.floor(sizes.length / 2)] : 12
    const big = [...new Set(paras.filter(p => p.text && p.text.length <= 120 && p.size >= base * 1.25).map(p => p.size))].sort((a, b) => b - a)

    for (const p of paras) {
        let tag = 'p'
        if (p.text && p.text.length <= 120 && !p.el.querySelector?.('img, svg')) {
            if (p.size >= base * 1.25) tag = big.indexOf(p.size) === 0 ? 'h1' : 'h2'
            else if (p.bold && CHAPTER.test(p.text)) tag = 'h1'
        }
        const out = d.createElement(tag)
        if (tag === 'p') {
            // сохраняем выравнивание и начертание, но не шрифты и размеры
            const align = p.el.style?.textAlign
            if (align && align !== 'left' && align !== 'justify') out.style.textAlign = align
            out.append(...[...p.el.childNodes].map(n => d.importNode(n, true)))
            for (const s of out.querySelectorAll('[style]')) {
                const keep = ['font-weight', 'font-style', 'text-decoration', 'vertical-align']
                    .map(k => s.style.getPropertyValue(k) ? `${k}:${s.style.getPropertyValue(k)}` : '').filter(Boolean).join(';')
                keep ? s.setAttribute('style', keep) : s.removeAttribute('style')
            }
        } else out.textContent = p.text
        body.append(out)
    }
    if (!body.textContent.trim() && !body.querySelector('img, svg')) throw new Error('В документе нет текста')

    const m = {
        title: (meta.title || '').trim(),
        authors: (meta.author || '').trim(),
        description: (meta.subject || '').trim(),
    }
    const stem = name.replace(/\.[^.]+$/, '')
    const r = htmlToEpub(body, { title: m.title || stem, authors: m.authors ? [m.authors] : [], language: 'ru', warnings })
    return { epub: r.blob, meta: m, toc: r.toc, warnings: r.warnings, structureHash: r.structureHash }
}
