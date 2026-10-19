// TXT → EPUB: определение кодировки, абзацы, заголовки глав, оглавление.
// Оригинальный файл не меняется — это отдельная версия для чтения.
import { htmlToEpub, newBody } from './epub-writer.js'

const CANDIDATES = ['windows-1251', 'koi8-r', 'ibm866', 'iso-8859-5', 'x-mac-cyrillic', 'windows-1252']
const ENC_LABEL = { 'windows-1251': 'Windows-1251', 'koi8-r': 'KOI8-R', ibm866: 'CP866 (DOS)', 'iso-8859-5': 'ISO-8859-5',
                    'x-mac-cyrillic': 'Mac Cyrillic', 'windows-1252': 'Windows-1252 (западная)' }

// Насколько текст похож на настоящий: строчных букв больше заглавных,
// нет псевдографики и слов из смеси латиницы с кириллицей
function score(t) {
    let lower = 0, upper = 0, latin = 0, junk = 0
    for (const ch of t) {
        const c = ch.charCodeAt(0)
        if ((c >= 0x430 && c <= 0x44F) || c === 0x451 || (c >= 0x4E0 && c <= 0x4FF)) lower++
        else if ((c >= 0x410 && c <= 0x42F) || c === 0x401) upper++
        else if ((c >= 0x61 && c <= 0x7A) || (c >= 0x41 && c <= 0x5A) || (c >= 0xE0 && c <= 0xFF)) latin++
        else if ((c >= 0x2500 && c <= 0x25FF) || c === 0xFFFD || (c >= 0x80 && c < 0xA0) || (c < 0x20 && c !== 10 && c !== 13 && c !== 9)) junk++
    }
    let mixed = 0
    for (const w of t.match(/\p{L}+/gu) || []) {
        if (/[a-zA-ZÀ-ÿ]/.test(w) && /[Ѐ-ӿ]/.test(w)) mixed += w.length
    }
    return lower * 3 + latin * 0.6 - upper * 1.2 - junk * 6 - mixed * 4
}

export function decodeText(buf) {
    if (buf[0] === 0xEF && buf[1] === 0xBB && buf[2] === 0xBF) return { text: new TextDecoder('utf-8').decode(buf.subarray(3)), encoding: 'UTF-8' }
    if (buf[0] === 0xFF && buf[1] === 0xFE) return { text: new TextDecoder('utf-16le').decode(buf.subarray(2)), encoding: 'UTF-16 LE' }
    if (buf[0] === 0xFE && buf[1] === 0xFF) return { text: new TextDecoder('utf-16be').decode(buf.subarray(2)), encoding: 'UTF-16 BE' }
    try { return { text: new TextDecoder('utf-8', { fatal: true }).decode(buf), encoding: 'UTF-8' } } catch (e) {}

    // UTF-16 без BOM: каждый второй байт нулевой
    const head = buf.subarray(0, 4000)
    let zEven = 0, zOdd = 0
    head.forEach((b, i) => { if (!b) (i % 2 ? zOdd++ : zEven++) })
    if (zOdd > head.length / 4) return { text: new TextDecoder('utf-16le').decode(buf), encoding: 'UTF-16 LE' }
    if (zEven > head.length / 4) return { text: new TextDecoder('utf-16be').decode(buf), encoding: 'UTF-16 BE' }

    const sample = buf.subarray(0, 300000)
    let best = null
    for (const enc of CANDIDATES) {
        let t
        try { t = new TextDecoder(enc).decode(sample) } catch (e) { continue }
        const s = score(t)
        if (!best || s > best.s) best = { enc, s }
    }
    return { text: new TextDecoder(best.enc).decode(buf), encoding: ENC_LABEL[best.enc] || best.enc }
}

const HEADING_WORD = /^(глава|часть|книга|том|раздел|пролог|эпилог|предисловие|послесловие|введение|заключение|приложение|chapter|part|book|volume|prologue|epilogue|preface|introduction|conclusion|appendix|боб|қисм|қисми|фасл|фасли|сарсухан|хотима)(\s|$|[.:—-])/i
const ROMAN = /^[IVXLCDM]{1,7}\.?$/
const NUMBER = /^\d{1,3}\.?$/
const SEPARATOR = /^\s*((\*\s*){3,}|([-–—=~_]\s*){3,}|#{3,})\s*$/

function isHeading(line) {
    const s = line.trim()
    if (!s || s.length > 120) return false
    if (HEADING_WORD.test(s)) return true
    if (ROMAN.test(s) || NUMBER.test(s)) return true
    const letters = s.match(/\p{L}/gu) || []
    return letters.length >= 4 && s.length <= 70 && !/\p{Ll}/u.test(s) && !/[.,;:!?…-]$/.test(s)
}

export async function convert(file, { name }) {
    const buf = new Uint8Array(await file.arrayBuffer())
    const { text, encoding } = decodeText(buf)
    const warnings = [`Кодировка определена как ${encoding}`]
    const lines = text.replace(/\r\n?/g, '\n').replace(/\t/g, '    ').split('\n')

    // как устроены абзацы: пустые строки между ними / отступ в начале / каждая строка — абзац
    const nonEmpty = lines.filter(l => l.trim()).length || 1
    const blank = lines.length - nonEmpty
    const indented = lines.filter(l => /^ {2,}\S/.test(l)).length
    const mode = blank >= nonEmpty * 0.15 ? 'blank' : indented >= nonEmpty * 0.3 ? 'indent' : 'line'

    // абзацы: массив массивов строк
    const paras = []
    let cur = []
    const push = () => { if (cur.length) paras.push(cur); cur = [] }
    for (const raw of lines) {
        const line = raw.replace(/\s+$/, '')
        if (!line.trim()) { push(); continue }
        if (mode === 'line') { paras.push([line]); continue }
        if (mode === 'indent' && /^ {2,}\S/.test(line)) push()
        // одиночный заголовок посреди сплошного текста
        if (isHeading(line) && (cur.length === 0 || mode === 'indent')) { push(); paras.push([line]); continue }
        cur.push(line)
    }
    push()

    const body = newBody()
    const doc = body.ownerDocument
    const add = (tag, cls, textOrLines) => {
        const el = doc.createElement(tag)
        if (cls) el.className = cls
        if (Array.isArray(textOrLines)) textOrLines.forEach((t, i) => { if (i) el.append(doc.createElement('br')); el.append(t) })
        else el.textContent = textOrLines
        body.append(el)
        return el
    }

    let headings = 0
    for (let i = 0; i < paras.length; i++) {
        const p = paras[i]
        const first = p[0].trim()
        if (p.length === 1 && SEPARATOR.test(first)) { add('p', 'sep', '* * *'); continue }
        if (p.length === 1 && isHeading(first)) {
            // «Глава 1» + короткое название следующей строкой → один заголовок
            const next = paras[i + 1]
            if (next && next.length === 1 && next[0].trim().length <= 70 && !/[.,;:!?…]$/.test(next[0].trim())
                && (HEADING_WORD.test(first) || ROMAN.test(first) || NUMBER.test(first)) && !isHeading(next[0])) {
                add('h1', '', first + '. ' + next[0].trim())
                i++
            } else add('h1', '', first)
            headings++
            continue
        }
        const short = p.every(l => l.trim().length < 60)
        if (p.length > 1 && short) add('p', 'verse', p.map(l => l.trim()))          // стихи: строки сохраняем
        else add('p', mode === 'blank' ? '' : 'txt', p.map(l => l.trim()).join(' '))
    }
    if (!headings) warnings.push('Заголовков глав не найдено — оглавления нет, навигация по прогрессу')

    // язык текста — только для переносов в книге, в метаданные не пишем
    const sampleText = text.slice(0, 20000)
    const cyr = (sampleText.match(/[Ѐ-ӿ]/g) || []).length
    const lat = (sampleText.match(/[a-zA-Z]/g) || []).length
    const tj = /[ӣӯҳқғҷ]/i.test(sampleText)
    const language = cyr > lat ? (tj ? 'tg' : 'ru') : 'en'

    const stem = name.replace(/\.[^.]+$/, '')
    const r = htmlToEpub(body, { title: stem, language, warnings })
    return { epub: r.blob, meta: {}, toc: r.toc, warnings: r.warnings, structureHash: r.structureHash }
}
