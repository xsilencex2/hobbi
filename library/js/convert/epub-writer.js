// Сборка EPUB 3 (с toc.ncx для совместимости с EPUB 2) из HTML-документа.
// Общая часть конвертеров TXT / DOCX / RTF: делит текст на главы по заголовкам,
// строит оглавление, выносит картинки в файлы, чинит внутренние ссылки.
import { zipSync, strToU8 } from '../../vendor/fflate/fflate.js'
import { uuid, hashStr } from '../common.js'

const XHTML = 'http://www.w3.org/1999/xhtml'
const BAD_XML = /[\u0000-\u0008\u000B\u000C\u000E-\u001F￾￿]/g
const MAX_CHAPTER = 150000            // символов разметки в одном файле — дальше дробим

export const cleanXml = s => String(s ?? '').replace(BAD_XML, '')
const x = s => cleanXml(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]))

const CSS = `
body { margin: 0 4%; line-height: 1.5; }
h1, h2, h3, h4 { line-height: 1.25; margin: 1.4em 0 .7em; text-indent: 0; }
h1 { font-size: 1.5em; } h2 { font-size: 1.3em; } h3 { font-size: 1.15em; }
p { margin: 0 0 .55em; }
p.txt { text-indent: 1.5em; margin: 0; }
p.verse { text-indent: 0; margin: 0 0 .8em; }
p.sep { text-align: center; text-indent: 0; margin: 1em 0; }
p.subtitle { font-style: italic; }
.lost { color: #a33; font-style: italic; }
img, svg { max-width: 100%; height: auto; }
table { border-collapse: collapse; margin: 1em 0; max-width: 100%; }
td, th { border: 1px solid #999; padding: .25em .45em; vertical-align: top; }
blockquote { margin: .8em 1.5em; font-style: italic; }
pre { white-space: pre-wrap; }
`

const MIME_EXT = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/gif': 'gif', 'image/webp': 'webp', 'image/svg+xml': 'svg', 'image/bmp': 'bmp' }

function dataUrlToBytes(src) {
    const m = /^data:([^;,]+)(;base64)?,(.*)$/s.exec(src)
    if (!m) return null
    const raw = m[2] ? atob(m[3]) : decodeURIComponent(m[3])
    const bytes = new Uint8Array(raw.length)
    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i)
    return { mime: m[1].toLowerCase(), bytes }
}

const textOf = el => (el.textContent || '').replace(/\s+/g, ' ').trim()

/**
 * body — элемент HTML-документа (DOMParser / createHTMLDocument) с содержимым книги.
 * images — уже вынесенные картинки [{path:'images/x.png', data, mime}] (src в тексте — 'images/…').
 * Возвращает { blob, toc, structureHash, chapters }.
 */
export function htmlToEpub(body, { title, authors = [], language = 'ru', images = [], warnings = [] }) {
    const doc = body.ownerDocument
    images = [...images]

    // картинки: data: → файлы; пустые (формат не поддерживается) → пометка в тексте
    let imgN = images.length
    for (const img of [...body.querySelectorAll('img')]) {
        const src = img.getAttribute('src') || ''
        if (src.startsWith('data:')) {
            const d = dataUrlToBytes(src)
            const ext = d && MIME_EXT[d.mime]
            if (ext) {
                const path = `images/img${++imgN}.${ext}`
                images.push({ path, data: d.bytes, mime: d.mime })
                img.setAttribute('src', '../' + path)
                continue
            }
        } else if (src.startsWith('images/')) {
            img.setAttribute('src', '../' + src)
            continue
        }
        const span = doc.createElement('span')
        span.className = 'lost'
        span.textContent = img.getAttribute('alt') || '[рисунок не показан]'
        img.replaceWith(span)
    }

    // уровень, по которому делим на главы: самый крупный заголовок, встречающийся хотя бы дважды
    // (или один раз h1); если заголовков нет — делим только по размеру
    const levels = ['h1', 'h2', 'h3']
    const counts = levels.map(l => body.querySelectorAll(':scope > ' + l).length)
    let split = levels.findIndex((l, i) => counts[i] >= 2 || (i === 0 && counts[0] >= 1))
    const splitTag = split >= 0 ? levels[split] : null
    const subTag = split >= 0 && split < 2 ? levels[split + 1] : null

    // главы
    const chapters = []
    let cur = { title: '', nodes: [], heading: null }
    for (const node of [...body.childNodes]) {
        if (node.nodeType === 3 && !node.textContent.trim()) continue
        if (splitTag && node.nodeType === 1 && node.localName === splitTag) {
            if (cur.nodes.length) chapters.push(cur)
            cur = { title: textOf(node), nodes: [node], heading: node }
        } else cur.nodes.push(node)
    }
    if (cur.nodes.length) chapters.push(cur)
    if (!chapters.length) chapters.push({ title: '', nodes: [doc.createElement('p')], heading: null })

    // слишком большие главы дробим на части по границам абзацев
    const ser = new XMLSerializer()
    const parts = []
    for (const ch of chapters) {
        let part = { ...ch, nodes: [], size: 0, first: true }
        for (const n of ch.nodes) {
            const sz = (n.outerHTML || n.textContent || '').length
            if (part.size + sz > MAX_CHAPTER && part.nodes.length) {
                parts.push(part)
                part = { title: ch.title, nodes: [], size: 0, first: false, heading: null }
            }
            part.nodes.push(n)
            part.size += sz
        }
        if (part.nodes.length) parts.push(part)
    }

    // id для подзаголовков (подпункты оглавления) и карта id → файл
    const fileOf = new Map()
    let anchorN = 0
    parts.forEach((p, i) => {
        p.file = `text/ch${String(i + 1).padStart(3, '0')}.xhtml`
        for (const n of p.nodes) {
            if (n.nodeType !== 1) continue
            const els = [n, ...n.querySelectorAll('[id], [name]')]
            if (subTag) els.push(...(n.localName === subTag ? [n] : []), ...n.querySelectorAll(subTag))
            for (const el of els) {
                if (subTag && el.localName === subTag && !el.id) el.id = 'h' + (++anchorN)
                if (el.id) fileOf.set(el.id, p.file)
                const nm = el.getAttribute?.('name')
                if (nm) fileOf.set(nm, p.file)
            }
        }
    })

    // внутренние ссылки (#сноска) → нужный файл главы
    for (const p of parts) for (const n of p.nodes) {
        if (n.nodeType !== 1) continue
        for (const a of [n, ...n.querySelectorAll('a[href^="#"]')]) {
            const href = a.getAttribute?.('href')
            if (!href || !href.startsWith('#')) continue
            const target = fileOf.get(decodeURIComponent(href.slice(1)))
            if (target) a.setAttribute('href', (target === p.file ? '' : '../' + target) + href)
        }
    }

    // оглавление
    const toc = []
    for (const p of parts) {
        if (!p.first || !p.heading || !p.title) continue
        const item = { label: p.title.slice(0, 200), href: p.file, subitems: [] }
        if (subTag) for (const n of p.nodes) {
            if (n.nodeType !== 1) continue
            for (const h of n.localName === subTag ? [n] : n.querySelectorAll(subTag)) {
                const label = textOf(h)
                if (label) item.subitems.push({ label: label.slice(0, 200), href: p.file + '#' + h.id })
            }
        }
        toc.push(item)
    }

    // XHTML-файлы глав
    const lang = x(language || 'ru')
    const files = {}
    const svgFiles = new Set()
    for (const p of parts) {
        const html = cleanXml(p.nodes.map(n => ser.serializeToString(n)).join('\n'))
        if (/<svg[\s>]/.test(html)) svgFiles.add(p.file)
        files['OEBPS/' + p.file] = strToU8(`<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html>
<html xmlns="${XHTML}" xmlns:epub="http://www.idpf.org/2007/ops" lang="${lang}" xml:lang="${lang}">
<head><meta charset="utf-8"/><title>${x(p.title || title)}</title><link rel="stylesheet" type="text/css" href="../style.css"/></head>
<body>
${html}
</body>
</html>`)
    }

    const navList = items => `<ol>${items.map(i => `<li><a href="${x(i.href)}">${x(i.label)}</a>${i.subitems?.length ? navList(i.subitems) : ''}</li>`).join('')}</ol>`
    const navItems = toc.length ? toc : [{ label: title, href: parts[0].file }]
    let order = 0
    const ncxPoints = items => items.map(i => `<navPoint id="np${++order}" playOrder="${order}"><navLabel><text>${x(i.label)}</text></navLabel><content src="${x(i.href)}"/>${i.subitems?.length ? ncxPoints(i.subitems) : ''}</navPoint>`).join('')

    const id = 'urn:uuid:' + uuid()
    const modified = new Date().toISOString().replace(/\.\d+Z$/, 'Z')
    const manifest = [
        '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>',
        '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>',
        '<item id="css" href="style.css" media-type="text/css"/>',
        ...parts.map((p, i) => `<item id="c${i + 1}" href="${p.file}" media-type="application/xhtml+xml"${svgFiles.has(p.file) ? ' properties="svg"' : ''}/>`),
        ...images.map((im, i) => `<item id="i${i + 1}" href="${x(im.path)}" media-type="${x(im.mime)}"/>`),
    ]

    const out = {
        mimetype: [strToU8('application/epub+zip'), { level: 0 }],
        'META-INF/container.xml': strToU8(`<?xml version="1.0" encoding="utf-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>
</container>`),
        'OEBPS/content.opf': strToU8(`<?xml version="1.0" encoding="utf-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="uid" xml:lang="${lang}">
<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
<dc:identifier id="uid">${id}</dc:identifier>
<dc:title>${x(title)}</dc:title>
${authors.map(a => `<dc:creator>${x(a)}</dc:creator>`).join('\n')}
<dc:language>${lang}</dc:language>
<meta property="dcterms:modified">${modified}</meta>
</metadata>
<manifest>
${manifest.join('\n')}
</manifest>
<spine toc="ncx">
${parts.map((p, i) => `<itemref idref="c${i + 1}"/>`).join('\n')}
</spine>
</package>`),
        'OEBPS/nav.xhtml': strToU8(`<?xml version="1.0" encoding="utf-8"?>
<!DOCTYPE html>
<html xmlns="${XHTML}" xmlns:epub="http://www.idpf.org/2007/ops" lang="${lang}" xml:lang="${lang}">
<head><meta charset="utf-8"/><title>${x(title)}</title></head>
<body><nav epub:type="toc" id="toc"><h1>Оглавление</h1>${navList(navItems)}</nav></body>
</html>`),
        'OEBPS/toc.ncx': strToU8(`<?xml version="1.0" encoding="utf-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
<head><meta name="dtb:uid" content="${id}"/></head>
<docTitle><text>${x(title)}</text></docTitle>
<navMap>${ncxPoints(navItems)}</navMap>
</ncx>`),
        'OEBPS/style.css': strToU8(CSS),
        ...files,
    }
    for (const im of images) out['OEBPS/' + im.path] = [im.data, { level: 0 }]

    const zipped = zipSync(out, { level: 6 })
    const structureHash = hashStr(parts.length + '|' + toc.map(t => t.label).join('|') + '|' + body.textContent.length)
    return {
        blob: new Blob([zipped], { type: 'application/epub+zip' }),
        toc: toc.map(t => ({ label: t.label, href: t.href, subitems: t.subitems.map(s => ({ label: s.label, href: s.href })) })),
        structureHash,
        chapters: parts.length,
        warnings,
    }
}

// Чистый HTML-документ для сборки содержимого
export function newBody() {
    return document.implementation.createHTMLDocument('').body
}
