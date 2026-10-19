// Оболочка читалки: один интерфейс для всех форматов, движок выбирается по реестру.
import { api, esc, fmtAgo, pct, toast, humanError, device, uuid, fileUrl, $, $$ } from '../common.js'
import { FORMATS, STATUS, readSource } from '../formats.js'
import { sync, savePosition, beaconPosition, lastLocalPosition, loadAnnotations, saveAnnotation, deleteAnnotation,
         loadSettings, saveSettings, saveSession, flush, syncState } from '../sync.js'
import { idb } from '../store.js'
import { getOfflineFile } from '../offline.js'
import { fetchBook } from '../process.js'
import { COLORS } from './reflow.js'

const params = new URLSearchParams(location.search)
const BOOK_ID = +params.get('id')
const DEV = device()
const DEFAULTS = {
    theme: 'light', fontSize: 100, font: 'publisher', lineHeight: 1.5, margin: 'normal', width: 720, columns: 2,
    flow: 'paginated', justify: true, hyphens: true, pdf: { zoom: 'page-width', scroll: 'continuous', invert: false },
}
const TYPE = { bookmark: 'Закладка', highlight: 'Выделение', note: 'Заметка', quote: 'Цитата' }
const ICON = { bookmark: '🔖', highlight: '🖍', note: '📝', quote: '❝' }

let book, engine, src, settings, allSettings = {}
let annotations = []
let cur = null              // последнее место: {locator, fraction, label, detail}
let lastMove = 0            // когда место сменилось на этом устройстве
let lastQueuedTs = 0
let askedFinish = false
let sel = null

const title = () => book.title || String(book.orig_name).replace(/\.(fb2\.zip|[^.]+)$/i, '')

// ── ЗАПУСК ────────────────────────────────────────────────────────────────────
function fail(html) {
    $('#rd-load').innerHTML = `<div class="rd-err"><b>Книгу не удалось открыть</b>${html}
        <p style="margin-top:14px"><a href="/library/#library">← В библиотеку</a>${book ? ` · <a href="${fileUrl(book, 'original', true)}">Скачать оригинал</a>` : ''}</p></div>`
}

function loadProgress(f, text) {
    if (text) $('#rd-load-t').textContent = text
    $('#rd-load-bar').style.width = Math.round(f * 100) + '%'
}

async function init() {
    if (!BOOK_ID) return fail('<p>Не указана книга.</p>')
    try {
        book = (await api('book', { params: { id: BOOK_ID } })).book
        idb.put('books', { key: book.id, ...book }).catch(() => {})
    } catch (e) {
        book = await idb.get('books', BOOK_ID).catch(() => null)
        if (!book) return fail(`<p>${esc(humanError(e))}</p>`)
    }
    document.title = title() + ' — Ikhlas'
    $('#rd-title').textContent = title()
    $('#rd-sub').textContent = book.authors || ''

    allSettings = await loadSettings().catch(() => ({}))
    settings = { ...DEFAULTS, ...(allSettings.reader || {}) }
    settings.pdf = { ...DEFAULTS.pdf, ...(allSettings.reader?.pdf || {}) }
    applyTheme()

    src = readSource(book)
    const fmt = FORMATS[book.format]
    if (params.get('mode') === 'view' && fmt?.view) src = { kind: 'original', engine: 'docxview', name: 'book.docx', converted: false }
    if (!src) {
        const why = book.drm || book.status === 'unsupported' ? book.error || STATUS.unsupported
                  : `Книга ещё не готова: ${STATUS[book.status] || book.status}. Откройте библиотеку — обработка запустится сама.`
        return fail(`<p>${esc(why)}</p>`)
    }

    // файл: копия на устройстве или сервер (PDF — частями по мере надобности)
    let file = await getOfflineFile(book).catch(() => null)
    let url = null
    if (!file) {
        if (!navigator.onLine) return fail('<p>Нет связи, а книга не сохранена на этом устройстве. В карточке книги есть кнопка «Сохранить на устройстве».</p>')
        if (src.engine === 'pdf') url = fileUrl(book, src.kind)
        else {
            try { file = await fetchBook(book, src.kind, f => loadProgress(f, `Загрузка книги ${Math.round(f * 100)}%`)) }
            catch (e) { return fail(`<p>${esc(humanError(e))}</p>`) }
        }
    }
    loadProgress(1, 'Открываем книгу…')

    try {
        const mod = await import(`./${src.engine}.js`)
        engine = await mod.open({
            container: $('#rd-main'), file, url, fileName: src.name, book, settings,
            onRelocate, onSelection, onAnnotationClick: openAnnotation, onFootnote, onTap, onKey,
            askPassword, onEngineSettings: patch => { settings = { ...settings, ...patch }; persistSettings() }, toast,
        })
    } catch (e) {
        console.error(e)
        return fail(`<p>${esc(humanError(e))}</p>`)
    }
    $('#rd-load').remove()
    document.body.classList.add('engine-' + engine.kind)
    if (engine.kind !== 'reflow') $('#rd-aa').title = 'Оформление'

    if (src.converted) banner(`Вы читаете версию для чтения, сделанную из ${esc(fmt.label)} (${esc(book.converter)} v${book.converter_version}). Оригинал не изменён: <a href="${fileUrl(book, 'original', true)}">скачать ${esc(fmt.label)}</a>`, 'conv')
    if (book.structure_changed) banner('После переобработки структура книги изменилась — старые закладки и позиции могут указывать не точно.')

    annotations = await loadAnnotations(book.id).catch(() => [])
    engine.setAnnotations(annotations)

    const start = await startPosition()
    try { await engine.restore(start.locator, start.fraction) } catch (e) { console.warn('restore', e); await engine.restore(null, start.fraction) }
    if (start.note) banner(start.note.html, null, start.note.action)
    if (params.get('q')) openPanel('search', params.get('q'))

    if (!syncState().online) setSync('offline')
    api('opened', { body: { id: book.id } }).then(r => { book.read_status = r.read_status }).catch(() => {})
    startSession()
    if (params.get('ocr')) openOcr()
}

// С какого места открыть: заметка из ссылки → явная страница → самая свежая позиция среди устройств
async function startPosition() {
    const annId = location.hash.match(/ann=([\w-]+)/)?.[1]
    if (annId) {
        const a = annotations.find(x => x.id === annId)
        if (a) return { locator: a.locator, fraction: a.fraction }
    }
    if (params.get('page')) return { locator: { type: 'page', page: +params.get('page') }, fraction: 0 }
    if (params.get('section')) return { locator: { type: 'section', index: +params.get('section') }, fraction: 0 }

    let positions = []
    try { positions = (await api('positions', { params: { id: book.id } })).positions } catch (e) {}
    const local = await lastLocalPosition(book.id).catch(() => null)
    const all = [...positions]
    if (local) {
        const i = all.findIndex(p => p.device_id === DEV.id)
        if (i < 0 || all[i].client_ts < local.client_ts) all.splice(i < 0 ? all.length : i, i < 0 ? 0 : 1, local)
    }
    if (!all.length) return { locator: null, fraction: 0 }
    all.sort((a, b) => b.client_ts - a.client_ts)
    const latest = all[0]
    const mine = all.find(p => p.device_id === DEV.id)
    const res = { locator: latest.locator, fraction: latest.fraction }
    if (latest.device_id !== DEV.id && mine && Math.abs(mine.fraction - latest.fraction) > 0.003) {
        res.note = {
            html: `Продолжаем с места на «${esc(latest.device_name || 'другом устройстве')}» (${fmtAgo(latest.client_ts)}, ${pct(latest.fraction)}).`,
            action: { label: `Вернуться к месту на этом устройстве (${pct(mine.fraction)})`, run: () => engine.restore(mine.locator, mine.fraction) },
        }
    }
    return res
}

// ── ПОЗИЦИЯ И СИНХРОНИЗАЦИЯ ───────────────────────────────────────────────────
let saveTimer = null
function onRelocate(d) {
    cur = d
    lastMove = Date.now()
    session.activity()
    session.to = d.fraction
    if (session.from == null) session.from = d.fraction
    const slider = $('#rd-slider')
    if (document.activeElement !== slider) slider.value = d.fraction
    $('#rd-loc').textContent = pct(d.fraction) + (d.detail ? ' · ' + d.detail : '')
    $('#rd-sub').textContent = d.label || book.authors || ''
    updateBookmarkBtn()
    if (!$('#rd-panel').hidden && panelTab === 'toc') markTocCurrent()

    clearTimeout(saveTimer)
    setSync('pending')
    saveTimer = setTimeout(() => {
        lastQueuedTs = Date.now()
        savePosition(book.id, { locator: d.locator, fraction: d.fraction, label: [d.label, d.detail].filter(Boolean).join(' · ').slice(0, 250) })
    }, 1200)

    if (d.fraction >= 0.995 && book.read_status !== 'finished' && !askedFinish) {
        askedFinish = true
        toast('Книга дочитана. Отметить как прочитанную?', { action: { label: 'Отметить', run: markFinished }, ms: 12000 })
    }
}

async function markFinished() {
    try {
        await api('book_save', { body: { id: book.id, read_status: 'finished' } })
        book.read_status = 'finished'
        toast('Книга отмечена как прочитанная 🎉')
    } catch (e) { toast(humanError(e), { error: true }) }
}

function setSync(state, savedAt) {
    const el = $('#rd-sync')
    el.className = 'rd-sync' + (state === 'offline' ? ' err' : '')
    if (state === 'pending') el.textContent = '⟳ Сохраняется…'
    else if (state === 'saved') el.textContent = '☁ Сохранено ' + (savedAt ? savedAt.slice(11, 16) : '')
    else if (state === 'offline') el.textContent = '⚠ Нет связи — сохранится позже'
}

sync.addEventListener('position-saved', e => {
    if (e.detail.book_id === book?.id && e.detail.client_ts >= lastQueuedTs - 5) setSync('saved', e.detail.saved_at)
})
sync.addEventListener('state', e => {
    const s = e.detail
    if (s.pending && (s.error || !s.online)) setSync('offline')
})
sync.addEventListener('annotation', e => {
    const a = e.detail
    if (a.book_id !== book?.id) return
    annotations = annotations.filter(x => x.id !== a.id)
    if (!a.deleted) annotations.push(a)
    engine?.setAnnotations(annotations)
    if (panelTab === 'notes' && !$('#rd-panel').hidden) renderNotes()
})

// Вернулись во вкладку — проверить, не читали ли книгу дальше на другом устройстве
document.addEventListener('visibilitychange', async () => {
    if (document.hidden) { leave(); return }
    session.resume()
    if (!book || !engine) return
    try {
        const { positions } = await api('positions', { params: { id: book.id } })
        const other = positions.filter(p => p.device_id !== DEV.id && p.client_ts > lastMove).sort((a, b) => b.client_ts - a.client_ts)[0]
        if (other && cur && Math.abs(other.fraction - cur.fraction) > 0.003) {
            banner(`На «${esc(other.device_name || 'другом устройстве')}» книгу читали ${fmtAgo(other.client_ts)}: ${pct(other.fraction)}${other.label ? ' · ' + esc(other.label) : ''}.`, null,
                   { label: 'Перейти туда', run: () => engine.restore(other.locator, other.fraction) })
        }
    } catch (e) {}
})

function leave() {
    if (!book) return
    clearTimeout(saveTimer)
    if (cur) {
        lastQueuedTs = Date.now()
        savePosition(book.id, { locator: cur.locator, fraction: cur.fraction, label: [cur.label, cur.detail].filter(Boolean).join(' · ').slice(0, 250) })
            .then(() => beaconPosition(book.id))
    }
    session.hiddenAt = Date.now()
    session.save()
    flush()
}
window.addEventListener('pagehide', leave)

// ── СЕАНС ЧТЕНИЯ (дневник, статистика) ────────────────────────────────────────
const session = {
    id: null, started: 0, seconds: 0, from: null, to: 0, tick: 0, active: 0, hiddenAt: 0,
    activity() { this.active = Date.now() },
    resume() {
        if (this.hiddenAt && Date.now() - this.hiddenAt > 30 * 60 * 1000) { this.save(); startSession() }   // долгий перерыв — новый сеанс
        this.tick = Date.now(); this.hiddenAt = 0
    },
    save() {
        if (!this.id || this.seconds < 20) return
        saveSession({ id: this.id, book_id: book.id, device_id: DEV.id, device_name: DEV.name, started_ts: this.started,
                      ended_ts: Date.now(), seconds: Math.round(this.seconds), from: this.from ?? 0, to: this.to })
    },
}
function startSession() {
    Object.assign(session, { id: uuid(), started: Date.now(), seconds: 0, from: cur?.fraction ?? null, to: cur?.fraction ?? 0, tick: Date.now(), active: Date.now() })
}
setInterval(() => {
    const now = Date.now()
    if (session.id && !document.hidden && now - session.active < 3 * 60 * 1000) session.seconds += (now - session.tick) / 1000
    session.tick = now
}, 10000)
setInterval(() => { if (!document.hidden) session.save() }, 60000)
for (const ev of ['pointerdown', 'keydown', 'wheel']) document.addEventListener(ev, () => session.activity(), { passive: true })

// ── ОФОРМЛЕНИЕ ────────────────────────────────────────────────────────────────
function applyTheme() {
    document.documentElement.dataset.theme = settings.theme
    document.querySelector('meta[name="theme-color"]').content = { light: '#fbfbf9', warm: '#f4ecd8', dark: '#121313' }[settings.theme]
}

let persistTimer = null
function persistSettings() {
    clearTimeout(persistTimer)
    persistTimer = setTimeout(() => {
        allSettings = { ...allSettings, reader: settings }
        saveSettings(allSettings)
    }, 700)
}

function setSetting(patch) {
    settings = { ...settings, ...patch }
    applyTheme()
    engine?.setSettings(settings)
    persistSettings()
    renderAa()
}

function renderAa() {
    const pop = $('#rd-pop')
    if (pop.hidden || pop.dataset.kind !== 'aa') return
    const seg = (key, opts) => `<div class="pp-seg">${opts.map(([v, l, cls]) => `<button class="${cls || ''} ${settings[key] === v ? 'on' : ''}" data-set="${key}" data-v="${v}">${l}</button>`).join('')}</div>`
    const step = (key, label, d) => `<div class="pp-step"><button data-step="${key}" data-d="${-d}">−</button><span>${label}</span><button data-step="${key}" data-d="${d}">+</button></div>`
    const reflow = engine?.kind === 'reflow'
    pop.innerHTML = `
        <div class="pp-row"><span class="pp-lbl">Тема</span>${seg('theme', [['light', 'Светлая', 't-light'], ['warm', 'Тёплая', 't-warm'], ['dark', 'Тёмная', 't-dark']])}</div>
        ${reflow ? `
        <div class="pp-row"><span class="pp-lbl">Размер</span>${step('fontSize', settings.fontSize + '%', 10)}</div>
        <div class="pp-row"><span class="pp-lbl">Шрифт</span>${seg('font', [['publisher', 'Книги'], ['serif', 'С засечками'], ['sans', 'Без засечек']])}</div>
        <div class="pp-row"><span class="pp-lbl">Интервал</span>${step('lineHeight', settings.lineHeight.toFixed(1), 0.1)}</div>
        <div class="pp-row"><span class="pp-lbl">Поля</span>${seg('margin', [['narrow', 'Узкие'], ['normal', 'Обычные'], ['wide', 'Широкие']])}</div>
        <div class="pp-row"><span class="pp-lbl">Ширина текста</span>${step('width', settings.width + ' px', 80)}</div>
        <div class="pp-row"><span class="pp-lbl">Листание</span>${seg('flow', [['paginated', 'Страницы'], ['scrolled', 'Прокрутка']])}</div>
        <div class="pp-row"><span class="pp-lbl">Колонки</span>${seg('columns', [[1, 'Одна'], [2, 'Две на широком']])}</div>
        <div class="pp-row"><span class="pp-lbl">Выравнивание</span>${seg('justify', [[true, 'По ширине'], [false, 'По левому']])}</div>
        <div class="pp-note">Настройки общие для всех ваших устройств.</div>`
        : engine?.kind === 'pdf' ? `
        <div class="pp-row"><span class="pp-lbl">Страницы</span><div class="pp-seg"><button data-pdfinv="0" class="${!settings.pdf.invert ? 'on' : ''}">Как есть</button><button data-pdfinv="1" class="${settings.pdf.invert ? 'on' : ''}">Тёмные (в тёмной теме)</button></div></div>
        <div class="pp-note">В PDF размер шрифта не меняется — страница свёрстана заранее. Пользуйтесь масштабом (−/+, ↔ по ширине, ↕ целиком) внизу справа.</div>`
        : `<div class="pp-note">${engine?.kind === 'djvu' ? 'DjVu — отсканированные страницы: масштаб и режимы просмотра — в панели просмотрщика.' : 'Вид документа повторяет вёрстку Word. Для настройки шрифта откройте режим «Чтение».'}</div>`}`
}

$('#rd-pop').addEventListener('click', e => {
    const b = e.target.closest('button')
    if (!b) return
    if (b.dataset.set) {
        let v = b.dataset.v
        if (v === 'true' || v === 'false') v = v === 'true'
        else if (/^\d+$/.test(v)) v = +v
        setSetting({ [b.dataset.set]: v })
    } else if (b.dataset.step) {
        const k = b.dataset.step, d = +b.dataset.d
        const lim = { fontSize: [60, 220], lineHeight: [1.1, 2.4], width: [400, 1400] }[k]
        setSetting({ [k]: Math.round(Math.min(lim[1], Math.max(lim[0], settings[k] + d)) * 10) / 10 })
    } else if (b.dataset.pdfinv) {
        setSetting({ pdf: { ...settings.pdf, invert: b.dataset.pdfinv === '1' } })
    }
})

function togglePop(kind, render) {
    const pop = $('#rd-pop')
    if (!pop.hidden && pop.dataset.kind === kind) { pop.hidden = true; return }
    pop.dataset.kind = kind
    pop.hidden = false
    render()
}
$('#rd-aa').onclick = e => { e.stopPropagation(); togglePop('aa', renderAa) }
$('#rd-more').onclick = e => { e.stopPropagation(); togglePop('more', renderMore) }
document.addEventListener('click', e => {
    if (!e.target.closest('#rd-pop, #rd-aa, #rd-more')) $('#rd-pop').hidden = true
    if (!e.target.closest('#rd-sel')) hideSel()
})

function renderMore() {
    const pop = $('#rd-pop')
    const fmt = FORMATS[book.format]
    pop.innerHTML = `<div class="menu">
        <dl><dt>Формат</dt><dd>${esc(fmt?.label || book.format)}${src?.converted ? ' → EPUB для чтения' : ''}</dd>
            ${book.pages ? `<dt>Страниц</dt><dd>${book.pages}</dd>` : ''}
            <dt>Прогресс</dt><dd>${pct(cur?.fraction || 0)}</dd></dl>
        ${fmt?.view && engine?.kind !== 'view' ? `<a href="?id=${book.id}&mode=view">📄 Вид документа (как в Word)</a>` : ''}
        ${engine?.kind === 'view' ? `<a href="?id=${book.id}">📖 Режим чтения</a>` : ''}
        ${['pdf', 'djvu'].includes(engine?.kind) && engine.renderPageImage ? `<button data-m="ocr">🔤 Распознать текст (OCR)…</button>` : ''}
        <button data-m="finish">✓ Отметить прочитанной</button>
        <button data-m="fs">⤢ Во весь экран</button>
        <a href="${fileUrl(book, 'original', true)}">⬇ Скачать оригинал</a>
        ${book.derived ? `<a href="${fileUrl(book, 'derived', true)}">⬇ Скачать EPUB-версию</a>` : ''}
        <a href="/library/#library">← В библиотеку</a></div>`
    pop.querySelectorAll('[data-m]').forEach(b => b.onclick = () => {
        pop.hidden = true
        if (b.dataset.m === 'finish') markFinished()
        if (b.dataset.m === 'ocr') openOcr()
        if (b.dataset.m === 'fs') { const el = document.documentElement; document.fullscreenElement ? document.exitFullscreen?.() : (el.requestFullscreen || el.webkitRequestFullscreen)?.call(el) }
    })
}

async function openOcr() {
    if (!engine?.renderPageImage) { toast('Распознавание доступно для PDF и DjVu', { error: true }); return }
    const { openOcrDialog } = await import('./ocr.js')
    openOcrDialog({ book, engine, modal: $('#rd-modal') })
}

// ── ПАНЕЛЬ: ОГЛАВЛЕНИЕ · ПОИСК · ЗАМЕТКИ ──────────────────────────────────────
let panelTab = 'toc'
function openPanel(tab, query) {
    panelTab = tab
    $('#rd-panel').hidden = false
    $('#rd-pop').hidden = true
    $$('.rd-ptabs button').forEach(b => b.classList.toggle('on', b.dataset.ptab === tab))
    if (tab === 'toc') renderToc()
    if (tab === 'search') renderSearch(query)
    if (tab === 'notes') renderNotes()
}
const closePanel = () => { $('#rd-panel').hidden = true; if (panelTab === 'search') engine?.clearSearch() }
$$('[data-panel]').forEach(b => b.onclick = () => (!$('#rd-panel').hidden && panelTab === b.dataset.panel) ? closePanel() : openPanel(b.dataset.panel))
$$('.rd-ptabs button').forEach(b => b.onclick = () => openPanel(b.dataset.ptab))
$('#rd-panel-x').onclick = closePanel
const narrow = () => matchMedia('(max-width: 640px)').matches

function renderToc() {
    const box = $('#rd-panel-b')
    const toc = engine?.toc || []
    if (!toc.length) { box.innerHTML = '<div class="p-empty">В книге нет оглавления.<br>Переходите по ползунку внизу или через поиск.</div>'; return }
    const items = []
    const tree = (list, depth) => `<div class="${depth ? 'sub' : ''}">${list.map(i => {
        items.push(i)
        return `<a href="#" data-toc="${items.length - 1}">${esc(i.label)}${i.page ? `<small>${i.page}</small>` : ''}</a>${i.subitems?.length ? tree(i.subitems, depth + 1) : ''}`
    }).join('')}</div>`
    box.innerHTML = `<nav class="toc">${tree(toc, 0)}</nav>`
    box.querySelectorAll('[data-toc]').forEach(a => a.onclick = e => {
        e.preventDefault()
        engine.goTo(items[+a.dataset.toc].target)
        if (narrow()) closePanel()
    })
    box._items = items
    markTocCurrent()
}
function markTocCurrent() {
    const box = $('#rd-panel-b'), items = box._items
    if (!items || !cur) return
    let idx = -1
    if (cur.tocHref) idx = items.findIndex(i => i.target === cur.tocHref)
    else if (cur.locator?.page) items.forEach((i, n) => { if (i.page && i.page <= cur.locator.page && (idx < 0 || i.page >= items[idx].page)) idx = n })
    box.querySelectorAll('.toc a.cur').forEach(a => a.classList.remove('cur'))
    const a = box.querySelector(`[data-toc="${idx}"]`)
    if (a) { a.classList.add('cur'); a.scrollIntoView({ block: 'nearest' }) }
}

let searchSeq = 0
function renderSearch(query = '') {
    const box = $('#rd-panel-b')
    box.innerHTML = `<form class="p-form" id="s-form"><input id="s-q" type="search" placeholder="Найти в книге" value="${esc(query)}" autocomplete="off"><button class="btn">Найти</button></form>
        <div class="p-note" id="s-note"></div><div id="s-res"></div>`
    const q = $('#s-q')
    q.addEventListener('keydown', e => e.stopPropagation())
    $('#s-form').onsubmit = e => { e.preventDefault(); runSearch(q.value.trim()) }
    if (query) runSearch(query)
    else setTimeout(() => q.focus(), 50)
}

async function runSearch(query) {
    const seq = ++searchSeq
    const res = $('#s-res'), note = $('#s-note')
    res.innerHTML = ''
    engine.clearSearch()
    if (query.length < 2) { note.textContent = 'Введите хотя бы 2 символа'; return }
    note.textContent = 'Поиск…'
    const hl = s => esc(s)
    let count = 0
    const add = item => {
        if (seq !== searchSeq) return
        if (item.progress != null) { note.textContent = `Поиск… ${Math.round(item.progress * 100)}%`; return }
        count++
        const b = document.createElement('button')
        b.className = 'res'
        b.innerHTML = `${item.label ? `<small>${esc(item.label)}</small>` : ''}…${hl(item.excerpt.pre)}<mark>${hl(item.excerpt.match)}</mark>${hl(item.excerpt.post)}…`
        b.onclick = () => {
            if (engine.kind === 'reflow') engine.select(item.target)
            else engine.showSearchHit(item.target)
            if (narrow()) $('#rd-panel').hidden = true
        }
        res.append(b)
    }
    let n = await engine.search(query, add).catch(e => { console.warn(e); return 0 })
    if (seq !== searchSeq) return
    // нет текстового слоя (скан) — ищем в распознанном тексте на сервере
    if (n <= 0 && ['pdf', 'djvu'].includes(engine.kind) && book.has_text === false) {
        try {
            const r = await api('search', { params: { q: query, book_id: book.id } })
            for (const h of r.hits) add({ label: `Страница ${h.locator?.page} · распознанный текст`, excerpt: { pre: '', match: h.snippet, post: '' }, target: { page: h.locator?.page, query } })
            n = r.hits.length
            if (!n) { note.textContent = r.ocr ? 'Ничего не найдено в распознанном тексте' : 'В этом документе нет текстового слоя. Чтобы искать, распознайте текст (меню ⋯ → «Распознать текст»).'; return }
        } catch (e) { note.textContent = humanError(e); return }
    }
    note.textContent = n > 0 ? `Найдено: ${count}${count >= 1000 ? '+' : ''}` : 'Ничего не найдено'
}

let notesFilter = ''
function renderNotes() {
    const box = $('#rd-panel-b')
    const list = annotations.filter(a => !a.deleted && (!notesFilter || a.type === notesFilter)).sort((a, b) => a.fraction - b.fraction || a.created_ts - b.created_ts)
    const f = [['', 'Все'], ['bookmark', 'Закладки'], ['highlight', 'Выделения'], ['note', 'Заметки'], ['quote', 'Цитаты']]
    box.innerHTML = `<div class="nt-f">${f.map(([k, l]) => `<button data-nf="${k}" class="${notesFilter === k ? 'on' : ''}">${l}</button>`).join('')}</div>
        ${list.map(a => `<div class="nt" data-nt="${a.id}">
            <div class="nt-m">${ICON[a.type]} ${TYPE[a.type]} · ${esc([a.chapter, a.locator?.page ? 'с. ' + a.locator.page : '', pct(a.fraction)].filter(Boolean).join(' · '))}</div>
            ${a.text ? `<div class="nt-q" style="border-color:${COLORS[a.color] || 'var(--accent)'}">${esc(a.text.slice(0, 400))}</div>` : ''}
            ${a.note ? `<div class="nt-n">${esc(a.note)}</div>` : ''}</div>`).join('')
          || `<div class="p-empty">${notesFilter ? 'Ничего нет' : 'Закладок и заметок пока нет.<br>🔖 — закладка на текущей странице.' + (engine?.supports.highlights ? '<br>Выделите текст, чтобы сделать выделение, заметку или цитату.' : '')}</div>`}`
    box.querySelectorAll('[data-nf]').forEach(b => b.onclick = () => { notesFilter = b.dataset.nf; renderNotes() })
    box.querySelectorAll('[data-nt]').forEach(el => el.onclick = () => {
        const a = annotations.find(x => x.id === el.dataset.nt)
        if (!a) return
        goToAnnotation(a)
        if (narrow()) $('#rd-panel').hidden = true
    })
}

function goToAnnotation(a) {
    if (a.locator?.type === 'cfi') engine.goTo(a.locator.cfi)
    else engine.restore(a.locator, a.fraction)
}

// ── ЗАКЛАДКИ ──────────────────────────────────────────────────────────────────
const bookmarks = () => annotations.filter(a => a.type === 'bookmark' && !a.deleted)
function updateBookmarkBtn() {
    const on = !!engine?.bookmarkHere(bookmarks())
    $('#rd-bm').classList.toggle('on', on)
    $('#rd-bm').setAttribute('aria-pressed', on)
}
$('#rd-bm').onclick = async () => {
    if (!engine) return
    const existing = engine.bookmarkHere(bookmarks())
    if (existing) {
        await deleteAnnotation(existing)
        annotations = annotations.filter(a => a.id !== existing.id)
        toast('Закладка удалена')
    } else {
        const h = engine.here()
        if (!h) return
        const a = { id: uuid(), book_id: book.id, type: 'bookmark', locator: h.locator, text: '', note: '', color: '',
                    chapter: [h.chapter, cur?.detail].filter(Boolean).join(' · ').slice(0, 250), fraction: h.fraction, created_ts: Date.now(), deleted: false }
        await saveAnnotation(a)
        annotations.push(a)
        toast('Закладка добавлена')
    }
    updateBookmarkBtn()
    if (!$('#rd-panel').hidden && panelTab === 'notes') renderNotes()
}

// ── ВЫДЕЛЕНИЕ ТЕКСТА → МЕНЮ ───────────────────────────────────────────────────
function onSelection(s) {
    sel = s
    const m = $('#rd-sel')
    if (!s) { m.hidden = true; return }
    m.innerHTML = `${Object.entries(COLORS).map(([k, c]) => `<button class="dot" data-hl="${k}" style="background:${c}" title="Выделить"></button>`).join('')}
        <span class="sep"></span><button data-act="note">Заметка</button><button data-act="quote">Цитата</button><button data-act="copy">Копировать</button><button data-act="find">Найти</button>`
    m.hidden = false
    const w = m.offsetWidth, h = m.offsetHeight
    let x = (s.rect.left + s.rect.right) / 2 - w / 2
    let y = s.rect.top - h - 10
    if (y < 60) y = s.rect.bottom + 10
    m.style.left = Math.max(8, Math.min(innerWidth - w - 8, x)) + 'px'
    m.style.top = Math.max(8, Math.min(innerHeight - h - 8, y)) + 'px'
}
function hideSel() { $('#rd-sel').hidden = true }

$('#rd-sel').addEventListener('pointerdown', e => e.preventDefault())     // не сбрасывать выделение
$('#rd-sel').addEventListener('click', async e => {
    e.stopPropagation()
    const b = e.target.closest('button')
    if (!b || !sel) return
    const s = sel
    if (b.dataset.hl) return createAnnotation('highlight', s, b.dataset.hl)
    if (b.dataset.act === 'quote') return createAnnotation('quote', s, 'yellow')
    if (b.dataset.act === 'note') { hideSel(); return editNote(null, s) }
    if (b.dataset.act === 'copy') { await navigator.clipboard?.writeText(s.text).catch(() => {}); toast('Скопировано'); s.clear(); hideSel() }
    if (b.dataset.act === 'find') { s.clear(); hideSel(); openPanel('search', s.text.slice(0, 80)) }
})

async function createAnnotation(type, s, color, note = '') {
    const a = { id: uuid(), book_id: book.id, type, locator: s.locator, text: s.text.slice(0, 8000), note, color,
                chapter: (s.chapter || '').slice(0, 250), fraction: s.fraction || cur?.fraction || 0, created_ts: Date.now(), deleted: false }
    await saveAnnotation(a)
    annotations.push(a)
    engine.addAnnotation(a)
    s.clear()
    hideSel()
    toast(type === 'quote' ? 'Цитата сохранена' : type === 'note' ? 'Заметка сохранена' : 'Выделено')
    if (!$('#rd-panel').hidden && panelTab === 'notes') renderNotes()
}

// окно заметки: новая (по выделению) или правка существующей
function editNote(a, s) {
    const modal = $('#rd-modal')
    let color = a?.color || 'yellow'
    let type = a?.type || 'note'
    const text = a ? a.text : s.text
    modal.innerHTML = `<div>
        <h3>${a ? TYPE[a.type] : 'Заметка'}</h3>
        ${text ? `<blockquote>${esc(text.slice(0, 1500))}</blockquote>` : ''}
        <textarea id="nt-text" placeholder="Ваш комментарий">${esc(a?.note || '')}</textarea>
        <div class="acts">
            ${a?.type !== 'bookmark' ? Object.entries(COLORS).map(([k, c]) => `<button class="dot ${k === color ? 'on' : ''}" data-c="${k}" style="background:${c}"></button>`).join('') : ''}
            ${a && a.type !== 'bookmark' ? `<select id="nt-type" style="border:1px solid var(--border);border-radius:8px;padding:5px;background:var(--surface2)">
                ${['highlight', 'note', 'quote'].map(t => `<option value="${t}" ${t === type ? 'selected' : ''}>${TYPE[t]}</option>`).join('')}</select>` : ''}
        </div>
        <div class="acts">
            <button class="btn" id="nt-save">Сохранить</button>
            <button class="btn ghost" id="nt-cancel">Отмена</button>
            ${a ? '<span class="grow"></span><button class="btn danger" id="nt-del">Удалить</button>' : ''}
        </div></div>`
    modal.hidden = false
    const ta = $('#nt-text')
    ta.addEventListener('keydown', e => e.stopPropagation())
    setTimeout(() => ta.focus(), 30)
    modal.querySelectorAll('[data-c]').forEach(d => d.onclick = () => { color = d.dataset.c; modal.querySelectorAll('[data-c]').forEach(x => x.classList.toggle('on', x === d)) })
    const close = () => { modal.hidden = true; modal.innerHTML = '' }
    $('#nt-cancel').onclick = close
    modal.onclick = e => { if (e.target === modal) close() }
    $('#nt-save').onclick = async () => {
        if (!a) { close(); return createAnnotation('note', s, color, ta.value.trim()) }
        engine.removeAnnotation(a)
        a.note = ta.value.trim()
        if (a.type !== 'bookmark') { a.color = color; a.type = $('#nt-type')?.value || a.type }
        await saveAnnotation(a)
        engine.addAnnotation(a)
        close()
        toast('Сохранено')
        if (!$('#rd-panel').hidden && panelTab === 'notes') renderNotes()
    }
    const del = $('#nt-del')
    if (del) del.onclick = async () => {
        await deleteAnnotation(a)
        engine.removeAnnotation(a)
        annotations = annotations.filter(x => x.id !== a.id)
        close()
        toast('Удалено')
        updateBookmarkBtn()
        if (!$('#rd-panel').hidden && panelTab === 'notes') renderNotes()
    }
}
function openAnnotation(a) { editNote(a) }

// ── СНОСКИ, ПАРОЛЬ ────────────────────────────────────────────────────────────
function onFootnote(view) {
    const modal = $('#rd-modal')
    modal.innerHTML = `<div><h3>Сноска</h3><div id="fn-box" style="position:relative;height:min(45vh,340px);border:1px solid var(--border);border-radius:10px;overflow:hidden"></div>
        <div class="acts"><button class="btn ghost" id="fn-x">Закрыть</button></div></div>`
    view.style.cssText = 'position:absolute;inset:0;display:block'
    $('#fn-box').append(view)
    modal.hidden = false
    const close = () => { modal.hidden = true; view.close?.(); modal.innerHTML = '' }
    $('#fn-x').onclick = close
    modal.onclick = e => { if (e.target === modal) close() }
}

function askPassword(wrong) {
    return new Promise(resolve => {
        const modal = $('#rd-modal')
        modal.innerHTML = `<div><h3>${wrong ? 'Неверный пароль' : 'PDF защищён паролем'}</h3>
            <p style="font-size:13px;color:var(--muted);margin-bottom:10px">Пароль не сохраняется и никуда не отправляется — PDF открывается на этом устройстве.</p>
            <input type="password" id="pw" style="width:100%;height:40px;border:1px solid var(--border);border-radius:10px;padding:0 12px;background:var(--surface2)">
            <div class="acts"><button class="btn" id="pw-ok">Открыть</button><button class="btn ghost" id="pw-x">Отмена</button></div></div>`
        modal.hidden = false
        const inp = $('#pw')
        setTimeout(() => inp.focus(), 30)
        const done = v => { modal.hidden = true; modal.innerHTML = ''; resolve(v) }
        $('#pw-ok').onclick = () => done(inp.value)
        $('#pw-x').onclick = () => done(null)
        inp.onkeydown = e => { e.stopPropagation(); if (e.key === 'Enter') done(inp.value) }
    })
}

// ── НАВИГАЦИЯ: касания, клавиши, ползунок ─────────────────────────────────────
function onTap(x, fixed) {
    if (!$('#rd-panel').hidden && narrow()) { closePanel(); return }
    if (!$('#rd-pop').hidden) { $('#rd-pop').hidden = true; return }
    if (fixed) return
    const w = innerWidth
    if (x < w * 0.22) engine.prev()
    else if (x > w * 0.78) engine.next()
    else $('#rd').classList.toggle('immersive')
}

function onKey(e) {
    if (e.target.closest?.('input, textarea, select')) return
    if (!$('#rd-modal').hidden) { if (e.key === 'Escape') $('#rd-modal').hidden = true; return }
    const k = e.key
    if ((e.ctrlKey || e.metaKey) && k.toLowerCase() === 'f') { e.preventDefault(); openPanel('search'); return }
    if (k === 'Escape') { closePanel(); $('#rd-pop').hidden = true; hideSel(); $('#rd').classList.remove('immersive'); return }
    if (engine?.kind === 'pdf' || engine?.kind === 'view') {
        if (k === 'PageDown') { e.preventDefault(); engine.next() }
        if (k === 'PageUp') { e.preventDefault(); engine.prev() }
        return
    }
    if (['ArrowLeft', 'PageUp'].includes(k)) { e.preventDefault(); engine?.prev() }
    if (['ArrowRight', 'PageDown', ' '].includes(k)) { e.preventDefault(); k === ' ' && e.shiftKey ? engine?.prev() : engine?.next() }
}
document.addEventListener('keydown', onKey)

$('#rd-prev').onclick = () => engine?.prev()
$('#rd-next').onclick = () => engine?.next()
const slider = $('#rd-slider')
slider.addEventListener('change', () => engine?.goToFraction(+slider.value))
slider.addEventListener('input', () => { $('#rd-loc').textContent = pct(+slider.value) })

// ── БАННЕР ────────────────────────────────────────────────────────────────────
function banner(html, key, action) {
    if (key) { try { if (localStorage.getItem('ikhlas_lib_banner_' + key + '_' + BOOK_ID)) return } catch (e) {} }
    const b = $('#rd-banner')
    b.innerHTML = `<span>${html}</span>${action ? '<button id="bn-act"></button>' : ''}<button class="x" id="bn-x" aria-label="Скрыть">✕</button>`
    b.hidden = false
    if (action) { $('#bn-act').textContent = action.label; $('#bn-act').onclick = () => { action.run(); b.hidden = true } }
    $('#bn-x').onclick = () => { b.hidden = true; if (key) try { localStorage.setItem('ikhlas_lib_banner_' + key + '_' + BOOK_ID, '1') } catch (e) {} }
}

init()
