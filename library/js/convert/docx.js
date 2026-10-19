// DOCX → EPUB через mammoth: заголовки, абзацы, списки, таблицы, картинки, сноски.
// Чего mammoth не переносит (формулы, диаграммы, колонтитулы…), перечисляем в
// предупреждениях — для них есть режим «Вид документа» и скачивание оригинала.
import { loadScript, VENDOR } from '../common.js'
import { unzipSync, strFromU8 } from '../../vendor/fflate/fflate.js'
import { htmlToEpub } from './epub-writer.js'

const IMG_EXT = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/gif': 'gif', 'image/bmp': 'bmp', 'image/svg+xml': 'svg', 'image/webp': 'webp' }

function coreMeta(bytes) {
    if (!bytes) return {}
    const xml = new DOMParser().parseFromString(strFromU8(bytes), 'application/xml')
    const get = tag => [...xml.getElementsByTagName(tag)].map(e => e.textContent.trim()).filter(Boolean)
    return {
        title: get('dc:title')[0] || '',
        authors: get('dc:creator').join('; '),
        language: get('dc:language')[0] || '',
        description: get('dc:description')[0] || '',
    }
}

function translate(msg) {
    let m
    if ((m = /Unrecognised paragraph style: '([^']*)'/.exec(msg))) return `Стиль абзаца «${m[1]}» не распознан — показан как обычный текст`
    if ((m = /Unrecognised run style: '([^']*)'/.exec(msg))) return `Стиль текста «${m[1]}» не распознан — показан как обычный текст`
    if (/An unrecognised element was ignored: v:/.test(msg)) return 'Векторная графика (VML) не перенесена'
    if ((m = /unrecognised element was ignored: ([\w:]+)/i.exec(msg))) return `Элемент Word «${m[1]}» не перенесён`
    return 'Конвертер: ' + msg
}

export async function convert(file, { name }) {
    await loadScript(VENDOR + '/mammoth/mammoth.browser.min.js')
    const mammoth = window.mammoth
    const ab = await file.arrayBuffer()

    let zip = {}
    try {
        zip = unzipSync(new Uint8Array(ab), {
            filter: f => /^(docProps\/core\.xml|word\/document\.xml|word\/(header|footer)\d*\.xml|word\/comments\.xml)$/.test(f.name),
        })
    } catch (e) {
        throw new Error('DOCX повреждён: не удаётся распаковать архив')
    }
    if (!zip['word/document.xml']) throw new Error('В DOCX нет основного текста (word/document.xml)')
    const meta = coreMeta(zip['docProps/core.xml'])
    const xml = strFromU8(zip['word/document.xml'])

    // что не войдёт в версию для чтения
    const lost = []
    const count = re => (xml.match(re) || []).length
    const f = count(/<m:oMath\b/g);  if (f) lost.push(`формулы (${f})`)
    const c = count(/<c:chart\b/g);  if (c) lost.push(`диаграммы (${c})`)
    const s = count(/<dgm:relIds\b/g); if (s) lost.push(`схемы SmartArt (${s})`)
    const o = count(/<w:object\b/g); if (o) lost.push(`встроенные объекты (${o})`)
    if (Object.keys(zip).some(k => /header|footer/.test(k))) lost.push('колонтитулы')
    if (zip['word/comments.xml']) lost.push('примечания рецензентов')
    if (/<w:del\b/.test(xml)) lost.push('удалённый текст из режима исправлений (показан итоговый вариант)')

    const images = []
    let n = 0
    const result = await mammoth.convertToHtml({ arrayBuffer: ab }, {
        includeDefaultStyleMap: true,
        styleMap: [
            "p[style-name='Title'] => h1.book-title:fresh",
            "p[style-name='Subtitle'] => p.subtitle:fresh",
            "p[style-name='Название'] => h1.book-title:fresh",
            "p[style-name='Заголовок 1'] => h1:fresh",
            "p[style-name='Заголовок 2'] => h2:fresh",
            "p[style-name='Заголовок 3'] => h3:fresh",
            "p[style-name='Quote'] => blockquote > p:fresh",
            "p[style-name='Цитата'] => blockquote > p:fresh",
        ],
        convertImage: mammoth.images.imgElement(async image => {
            const ext = IMG_EXT[image.contentType]
            if (!ext) {
                lost.push(`рисунок в формате ${image.contentType || 'неизвестном'}`)
                return { src: '', alt: `[рисунок не показан: формат ${image.contentType || 'неизвестен'}]` }
            }
            const data = new Uint8Array(await image.read())
            const path = `images/img${++n}.${ext}`
            images.push({ path, data, mime: image.contentType })
            return { src: path, alt: image.altText || '' }
        }),
    })

    const warnings = []
    if (lost.length) warnings.push('В версии для чтения нет: ' + [...new Set(lost)].join(', ') + '. Они есть в оригинале и в режиме «Вид документа».')
    const seen = new Set()
    for (const m of result.messages) {
        const t = translate(m.message)
        if (!seen.has(t) && seen.size < 15) { seen.add(t); warnings.push(t) }
    }

    const body = new DOMParser().parseFromString('<!DOCTYPE html><body>' + result.value + '</body>', 'text/html').body
    if (!body.textContent.trim() && !images.length) throw new Error('В документе нет текста')

    const stem = name.replace(/\.[^.]+$/, '')
    const r = htmlToEpub(body, {
        title: meta.title || stem,
        authors: meta.authors ? meta.authors.split(/\s*;\s*/) : [],
        language: meta.language || 'ru',
        images, warnings,
    })
    return { epub: r.blob, meta, toc: r.toc, warnings: r.warnings, structureHash: r.structureHash }
}
