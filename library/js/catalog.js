// Каталог библиотеки: главная, полки, коллекции, заметки, настройки, загрузка.
import { api, esc, fmtSize, fmtDate, fmtAgo, pct, plural, toast, fileUrl, humanError, $, $$, device, parseDate } from './common.js'
import { FORMATS, STATUS, READ_STATUS, canRead, needsProcessing, outdatedConverter } from './formats.js'
import { uploadFile } from './upload.js'
import { processBook } from './process.js'
import { sync, flush, syncState, loadSettings } from './sync.js'
import { indexBook } from './indexer.js'
import { idb } from './store.js'
import * as offline from './offline.js'
import * as extra from './extra.js'

const LS = {
    get: (k, d) => { try { return localStorage.getItem(k) ?? d } catch (e) { return d } },
    set: (k, v) => { try { localStorage.setItem(k, v) } catch (e) {} },
}

export const S = {
    books: [], cols: [], links: new Map(), used: 0, quota: 0, maxFile: 0, loaded: false, offlineMode: false,
    view: LS.get('ikhlas_lib_view', 'grid'), sort: LS.get('ikhlas_lib_sort', 'added'),
    anns: null, offlineIds: new Set(),
}

// ── ВСПОМОГАТЕЛЬНОЕ ───────────────────────────────────────────────────────────
const stem = n => String(n || '').replace(/\.(fb2\.zip|[^.]+)$/i, '')
export const titleOf = b => b.title || stem(b.orig_name) || 'Без названия'
const norm = s => String(s || '').toLowerCase().replace(/ё/g, 'е')
const authorsOf = b => (b.authors || '').split(/\s*;\s*/).filter(Boolean)
const byOpened = (a, b) => (parseDate(b.last_opened_at) || 0) - (parseDate(a.last_opened_at) || 0)
const byAdded = (a, b) => (parseDate(b.created_at) || 0) - (parseDate(a.created_at) || 0) || b.id - a.id
const isReady = b => ['ready', 'converted'].includes(b.status)

const PALETTE = ['#2f6f5e', '#6b4f8a', '#8a5a3b', '#3b5f8a', '#7a3b4f', '#4f6b3b', '#5a5a7a', '#8a6f2f', '#2f5f6f', '#6f3b3b']
function colorOf(s) {
    let h = 0
    for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) >>> 0
    const a = PALETTE[h % PALETTE.length], b = PALETTE[(h >>> 4) % PALETTE.length]
    return `linear-gradient(160deg, ${a}, ${b})`
}

export function coverHtml(b, { fmt = true } = {}) {
    const t = titleOf(b)
    return `<div class="cover">
        <div class="ph" style="background:${colorOf(t)}"><span>${esc(t.slice(0, 80))}</span><small>${esc(authorsOf(b)[0] || '')}</small></div>
        ${b.cover ? `<img loading="lazy" src="${fileUrl(b, 'cover')}" alt="">` : ''}
        ${fmt ? `<span class="fmt">${esc(FORMATS[b.format]?.label || String(b.format).toUpperCase())}</span>` : ''}
        ${S.offlineIds.has(b.id) ? '<span class="badge" title="Сохранена на устройстве">⬇</span>' : ''}
    </div>`
}
// битую обложку убираем — остаётся заглушка (обработчик на всю страницу: CSP запрещает onerror в разметке)
document.addEventListener('error', e => { if (e.target.matches?.('.cover img')) e.target.remove() }, true)

export function statusChip(b) {
    if (isReady(b)) return ''
    const cls = ['error', 'unsupported'].includes(b.status) ? 'err' : 'busy'
    return `<span class="chip ${cls}">${esc(b.drm ? 'DRM' : STATUS[b.status] || b.status)}</span>`
}

function progressHtml(b) {
    if (b.read_status === 'finished') return '<div class="card-s"><span class="chip ok">✓ Прочитана</span></div>'
    if (b.progress > 0) return `<div class="bar"><i style="width:${(b.progress * 100).toFixed(1)}%"></i></div><div class="card-s">${pct(b.progress)}</div>`
    return ''
}

function cardHtml(b) {
    return `<div class="card" data-open="${b.id}" title="${esc(titleOf(b))}">
        ${coverHtml(b)}
        <button class="more" data-info="${b.id}" aria-label="Подробнее о книге">⋯</button>
        <div class="card-t">${esc(titleOf(b))}</div>
        <div class="card-a">${esc(b.authors || '')}</div>
        ${progressHtml(b)}
        ${statusChip(b) ? `<div class="card-s">${statusChip(b)}</div>` : ''}
    </div>`
}

function rowHtml(b) {
    return `<div class="row" data-open="${b.id}">
        ${coverHtml(b, { fmt: false })}
        <div style="min-width:0"><div class="row-t">${esc(titleOf(b))}</div><div class="row-a">${esc(b.authors || '—')}</div></div>
        <div class="row-c">${esc(FORMATS[b.format]?.label || b.format)}</div>
        <div class="row-c c-st">${b.read_status === 'finished' ? '✓ Прочитана' : esc(READ_STATUS[b.read_status])}${statusChip(b) ? '<br>' + statusChip(b) : ''}</div>
        <div class="row-c">${b.progress > 0 ? `<div class="bar"><i style="width:${b.progress * 100}%"></i></div>${pct(b.progress)}` : '—'}</div>
        <div class="row-c c-add">${fmtDate(b.created_at)}${b.last_opened_at ? `<br><span class="c-open">открыта ${fmtDate(b.last_opened_at)}</span>` : ''}</div>
        <button class="icon-btn" data-info="${b.id}" aria-label="Подробнее">⋯</button>
    </div>`
}

export const bookById = id => S.books.find(b => b.id === +id)

export function upsertBook(b) {
    const i = S.books.findIndex(x => x.id === b.id)
    if (i >= 0) S.books[i] = { ...S.books[i], ...b }
    else S.books.unshift(b)
    idb.put('books', { key: b.id, ...S.books.find(x => x.id === b.id) }).catch(() => {})
}

// ── ЗАГРУЗКА ДАННЫХ ───────────────────────────────────────────────────────────
export async function loadBooks() {
    try {
        const r = await api('books')
        S.books = r.books
        S.cols = r.collections
        S.links = new Map()
        for (const [bid, cid] of r.links) {
            if (!S.links.has(bid)) S.links.set(bid, new Set())
            S.links.get(bid).add(cid)
        }
        Object.assign(S, { used: r.used, quota: r.quota, maxFile: r.max_file, loaded: true, offlineMode: false })
        offline.cacheCatalog(r).catch(() => {})
    } catch (e) {
        // нет связи — показываем сохранённую копию каталога
        const cached = await offline.cachedCatalog().catch(() => null)
        if (!cached) { $('#view').innerHTML = `<div class="empty"><div class="empty-ico">📡</div>${esc(humanError(e))}</div>`; return }
        Object.assign(S, { books: cached.books, cols: cached.collections, used: cached.used, quota: cached.quota, loaded: true, offlineMode: true })
        S.links = new Map()
        for (const [b, c] of cached.links) { if (!S.links.has(b)) S.links.set(b, new Set()); S.links.get(b).add(c) }
        toast('Нет связи — показана сохранённая копия библиотеки', { error: true })
    }
    S.offlineIds = new Set(await offline.offlineIds().catch(() => []))
    render()
    if (!S.offlineMode) autoProcess()
}

// ── МАРШРУТЫ ──────────────────────────────────────────────────────────────────
function route() {
    const h = location.hash.slice(1) || 'home'
    const [name, qs] = h.split('?')
    return { name, p: new URLSearchParams(qs || '') }
}

export function render() {
    if (!S.loaded) return
    const { name, p } = route()
    $$('#tabs .tab').forEach(t => t.classList.toggle('active', t.dataset.t === name))
    const act = $('#tabs .tab.active')
    if (act) { const bar = $('#tabs'); if (act.offsetLeft < bar.scrollLeft || act.offsetLeft + act.offsetWidth > bar.scrollLeft + bar.clientWidth) bar.scrollLeft = act.offsetLeft - 12 }
    const v = $('#view')
    const views = { home: renderHome, library: renderLibrary, collections: renderCollections, notes: renderNotes,
                    diary: extra.renderDiary, stats: extra.renderStats, settings: renderSettings }
    ;(views[name] || renderHome)(v, p)
}
window.addEventListener('hashchange', () => { render(); window.scrollTo(0, 0) })

// ── ГЛАВНАЯ ───────────────────────────────────────────────────────────────────
function searchBooks(q) {
    const n = norm(q).trim()
    if (!n) return []
    const colName = new Map(S.cols.map(c => [c.id, norm(c.name)]))
    return S.books.filter(b => {
        const cols = [...(S.links.get(b.id) || [])].map(id => colName.get(id)).join(' ')
        return [b.title, b.authors, b.orig_name, b.series, b.publisher, cols, FORMATS[b.format]?.label].some(x => norm(x).includes(n))
    })
}

function renderHome(v) {
    const books = S.books
    if (!books.length) {
        v.innerHTML = `<div class="box"><div class="empty">
            <div class="empty-ico">📚</div>
            <div style="font-size:18px;font-weight:800;color:var(--text);margin-bottom:6px">Библиотека пуста</div>
            <div style="margin-bottom:18px">Загрузите книги: EPUB, FB2, MOBI, AZW3, PDF, DjVu, TXT, DOCX, RTF.<br>Можно перетащить файлы прямо в окно.</div>
            <button class="btn" data-upload>+ Загрузить книги</button></div></div>`
        return
    }
    const last = books.filter(b => b.last_opened_at && canRead(b) && b.read_status !== 'finished').sort(byOpened)[0]
    const reading = books.filter(b => b.read_status === 'reading' && b !== last).sort(byOpened)
    const recentOpened = books.filter(b => b.last_opened_at && b !== last).sort(byOpened).slice(0, 12)
    const recent = [...books].sort(byAdded).slice(0, 12)
    const pending = books.filter(b => !isReady(b))
    const outdated = books.filter(outdatedConverter)

    v.innerHTML = `
        <div class="topbar">
            <div class="search"><input class="inp" id="home-q" type="search" placeholder="Поиск по названию, автору, коллекции, заметкам" autocomplete="off"></div>
            <button class="btn" data-upload>+ Загрузить книгу</button>
        </div>
        <div id="home-results"></div>
        <div id="home-main">
        ${pending.length ? `<div class="alert ${pending.some(b => ['error', 'unsupported'].includes(b.status)) ? 'err' : ''}">
            <span>⚙ ${pending.length} ${plural(pending.length, 'книга требует', 'книги требуют', 'книг требуют')} внимания: обработка, ошибки или неподдерживаемый формат</span>
            <a class="btn sm ghost" href="#library?shelf=pending">Показать</a></div>` : ''}
        ${outdated.length ? `<div class="alert"><span>Вышла новая версия конвертера для ${outdated.length} ${plural(outdated.length, 'книги', 'книг', 'книг')}. Переобработка не удаляет закладки и заметки.</span>
            <button class="btn sm ghost" data-reprocess-all>Переобработать</button></div>` : ''}
        ${last ? `<div class="sec"><div class="sec-h"><h2>Продолжить чтение</h2></div>
            <div class="hero">
                <a href="/library/read.php?id=${last.id}" style="display:block;width:110px;flex-shrink:0" class="hero-c">${coverHtml(last)}</a>
                <div style="min-width:0;flex:1">
                    <div class="hero-t">${esc(titleOf(last))}</div>
                    <div class="hero-a">${esc(last.authors || '')}</div>
                    <div class="bar"><i style="width:${(last.progress * 100).toFixed(1)}%"></i></div>
                    <div class="hero-meta">${pct(last.progress)}${last.position_label ? ' · ' + esc(last.position_label) : ''} · открыта ${fmtDate(last.last_opened_at, true)}</div>
                    <a class="btn" href="/library/read.php?id=${last.id}">Продолжить чтение</a>
                </div>
            </div></div>` : ''}
        ${reading.length ? shelfHtml('Читаю сейчас', reading, '#library?shelf=reading') : ''}
        ${recentOpened.length ? shelfHtml('Последние открытые', recentOpened, '#library?sort=opened') : ''}
        ${shelfHtml('Недавно добавленные', recent, '#library?shelf=recent')}
        </div>`

    const q = $('#home-q', v)
    q.addEventListener('input', () => homeSearch(q.value))
}

function shelfHtml(title, list, more) {
    return `<div class="sec"><div class="sec-h"><h2>${esc(title)}</h2><a href="${more}">Все →</a></div>
        <div class="shelf">${list.map(cardHtml).join('')}</div></div>`
}

async function homeSearch(q) {
    const res = $('#home-results'), main = $('#home-main')
    if (!q.trim()) { res.innerHTML = ''; main.hidden = false; return }
    main.hidden = true
    const books = searchBooks(q)
    if (!S.anns) S.anns = await extra.loadAllAnnotations().catch(() => [])
    const n = norm(q)
    const notes = S.anns.filter(a => norm(a.text).includes(n) || norm(a.note).includes(n)).slice(0, 30)
    const content = await extra.contentSearch(q).catch(() => null)
    res.innerHTML = `
        <div class="sec"><div class="sec-h"><h2>Книги</h2><span class="muted">${books.length}</span></div>
            ${books.length ? `<div class="grid">${books.slice(0, 60).map(cardHtml).join('')}</div>` : '<div class="empty sm">Книг не найдено</div>'}</div>
        ${notes.length ? `<div class="sec"><div class="sec-h"><h2>Заметки и цитаты</h2><span class="muted">${notes.length}</span></div>
            <div class="box">${notes.map(extra.annHtml).join('')}</div></div>` : ''}
        ${content?.length ? `<div class="sec"><div class="sec-h"><h2>В тексте книг</h2><span class="muted">${content.length}</span></div>
            <div class="box">${content.map(extra.hitHtml).join('')}</div></div>` : ''}`
}

// ── БИБЛИОТЕКА ────────────────────────────────────────────────────────────────
const SHELVES = [
    ['all', 'Все книги', () => true],
    ['continue', 'Продолжить чтение', b => b.progress > 0 && b.read_status !== 'finished'],
    ['reading', 'Читаю сейчас', b => b.read_status === 'reading'],
    ['want', 'Хочу прочитать', b => b.read_status === 'want'],
    ['planned', 'Запланированные', b => b.read_status === 'planned'],
    ['finished', 'Прочитанные', b => b.read_status === 'finished'],
    ['paused', 'Отложенные', b => b.read_status === 'paused'],
    ['fav', 'Избранное', b => b.favorite],
    ['recent', 'Недавно добавленные', b => Date.now() - parseDate(b.created_at) < 30 * 86400000],
    ['offline', 'На этом устройстве', b => S.offlineIds.has(b.id)],
    ['pending', 'Требуют обработки', b => !isReady(b)],
]
const SORTS = { added: 'Недавно добавленные', opened: 'Недавно открытые', title: 'По названию', author: 'По автору', progress: 'По прогрессу' }

function filterBooks(p) {
    const shelf = SHELVES.find(s => s[0] === (p.get('shelf') || 'all')) || SHELVES[0]
    let list = S.books.filter(shelf[2])
    const author = p.get('author'), col = +p.get('col'), fmt = p.get('fmt'), q = p.get('q')
    if (author) list = list.filter(b => authorsOf(b).includes(author))
    if (col) list = list.filter(b => S.links.get(b.id)?.has(col))
    if (fmt) list = list.filter(b => b.format === fmt)
    if (q) { const found = new Set(searchBooks(q).map(b => b.id)); list = list.filter(b => found.has(b.id)) }
    const sort = p.get('sort') || (shelf[0] === 'continue' ? 'opened' : S.sort)
    const cmp = {
        added: byAdded, opened: byOpened,
        title: (a, b) => titleOf(a).localeCompare(titleOf(b), 'ru'),
        author: (a, b) => (authorsOf(a)[0] || 'я').localeCompare(authorsOf(b)[0] || 'я', 'ru') || titleOf(a).localeCompare(titleOf(b), 'ru'),
        progress: (a, b) => b.progress - a.progress,
    }[sort] || byAdded
    return { list: list.sort(cmp), shelf, sort }
}

function libLink(p, changes) {
    const n = new URLSearchParams(p)
    for (const [k, v] of Object.entries(changes)) v == null || v === '' ? n.delete(k) : n.set(k, v)
    const s = n.toString()
    return '#library' + (s ? '?' + s : '')
}

function renderLibrary(v, p) {
    const { list, shelf, sort } = filterBooks(p)
    const base = new URLSearchParams()   // сайдбар сбрасывает остальные фильтры
    const count = f => S.books.filter(f).length
    const authors = new Map()
    S.books.forEach(b => authorsOf(b).forEach(a => authors.set(a, (authors.get(a) || 0) + 1)))
    const fmts = new Map()
    S.books.forEach(b => fmts.set(b.format, (fmts.get(b.format) || 0) + 1))
    const colCount = id => S.books.filter(b => S.links.get(b.id)?.has(id)).length
    const cats = S.cols.filter(c => c.kind === 'category'), colls = S.cols.filter(c => c.kind === 'collection')
    const curAuthor = p.get('author'), curCol = +p.get('col'), curFmt = p.get('fmt')
    const link = (href, label, n, on) => `<a href="${href}" class="${on ? 'on' : ''}"><span>${esc(label)}</span><small>${n}</small></a>`

    const side = `
        <h4>Полки</h4>
        ${SHELVES.filter(s => s[0] !== 'offline' || S.offlineIds.size).map(s => link(libLink(base, { shelf: s[0] === 'all' ? null : s[0] }), s[1], count(s[2]),
            shelf[0] === s[0] && !curAuthor && !curCol && !curFmt)).join('')}
        ${cats.length ? `<h4>Категории</h4>${cats.map(c => link(libLink(base, { col: c.id }), c.name, colCount(c.id), curCol === c.id)).join('')}` : ''}
        ${colls.length ? `<h4>Коллекции</h4>${colls.map(c => link(libLink(base, { col: c.id }), c.name, colCount(c.id), curCol === c.id)).join('')}` : ''}
        <h4>Авторы</h4>
        ${[...authors].sort((a, b) => a[0].localeCompare(b[0], 'ru')).slice(0, 300).map(([a, n]) => link(libLink(base, { author: a }), a, n, curAuthor === a)).join('') || '<div class="muted" style="font-size:12px;padding:4px 9px">Авторы не указаны</div>'}
        <h4>Форматы</h4>
        ${[...fmts].map(([f, n]) => link(libLink(base, { fmt: f }), FORMATS[f]?.label || f.toUpperCase(), n, curFmt === f)).join('')}`

    let title = shelf[1]
    if (curAuthor) title = curAuthor
    if (curCol) title = S.cols.find(c => c.id === curCol)?.name || title
    if (curFmt) title = FORMATS[curFmt]?.label || curFmt

    v.innerHTML = `<div class="lib">
        <nav class="side">${side}</nav>
        <div style="min-width:0">
            <div class="lib-tools">
                <div class="lib-title">${esc(title)} <span class="muted" style="font-size:14px;font-weight:600">${list.length}</span></div>
                <select class="inp side-sel" id="shelf-sel" aria-label="Полка">
                    ${SHELVES.map(s => `<option value="${s[0]}" ${shelf[0] === s[0] ? 'selected' : ''}>${esc(s[1])} (${count(s[2])})</option>`).join('')}
                </select>
                <div class="search" style="flex:1 1 200px"><input class="inp" id="lib-q" type="search" placeholder="Поиск" value="${esc(p.get('q') || '')}"></div>
                <select class="inp" id="lib-sort" aria-label="Сортировка">${Object.entries(SORTS).map(([k, l]) => `<option value="${k}" ${sort === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
                <div class="seg" role="group" aria-label="Вид">
                    <button data-view="grid" class="${S.view === 'grid' ? 'on' : ''}" title="Карточки">▦</button>
                    <button data-view="list" class="${S.view === 'list' ? 'on' : ''}" title="Список">☰</button>
                </div>
                <button class="btn" data-upload>+ Книга</button>
            </div>
            ${shelf[0] === 'pending' && list.some(needsProcessing) ? `<div class="alert"><span>Обработка идёт в этом окне браузера: не закрывайте его, пока книги не обработаются.</span></div>` : ''}
            ${!list.length ? `<div class="box"><div class="empty sm">Здесь пока нет книг</div></div>`
              : S.view === 'list'
                ? `<div class="list"><div class="list-h"><span></span><span>Книга</span><span>Формат</span><span class="c-st">Статус</span><span>Прогресс</span><span class="c-add">Добавлена</span><span></span></div>${list.map(rowHtml).join('')}</div>`
                : `<div class="grid">${list.map(cardHtml).join('')}</div>`}
        </div></div>`

    $('#shelf-sel', v).onchange = e => location.hash = libLink(base, { shelf: e.target.value === 'all' ? null : e.target.value })
    $('#lib-sort', v).onchange = e => { S.sort = e.target.value; LS.set('ikhlas_lib_sort', S.sort); location.hash = libLink(p, { sort: null }); render() }
    const q = $('#lib-q', v)
    let qt
    q.oninput = () => { clearTimeout(qt); qt = setTimeout(() => { history.replaceState(null, '', libLink(p, { q: q.value })); render(); const n = $('#lib-q'); n.focus(); n.setSelectionRange(n.value.length, n.value.length) }, 250) }
}

// ── ПАНЕЛЬ КНИГИ ──────────────────────────────────────────────────────────────
let drawerBook = null

function closeDrawer() {
    $('#drawer-ov').classList.remove('on')
    drawerBook = null
}
$('#drawer-ov').addEventListener('click', e => { if (e.target.id === 'drawer-ov') closeDrawer() })
document.addEventListener('keydown', e => { if (e.key === 'Escape' && drawerBook) closeDrawer() })

export async function openDrawer(id) {
    const ov = $('#drawer-ov'), dr = $('#drawer')
    let b = bookById(id)
    if (!b) return
    drawerBook = b
    dr.innerHTML = `<button class="icon-btn dr-close" data-close>✕</button><div class="empty sm">Загрузка…</div>`
    ov.classList.add('on')
    let positions = []
    if (!S.offlineMode) {
        try {
            const [r, pos] = await Promise.all([api('book', { params: { id } }), api('positions', { params: { id } })])
            b = { ...b, ...r.book }
            upsertBook(r.book)
            positions = pos.positions
        } catch (e) { toast(humanError(e), { error: true }) }
    }
    if (drawerBook?.id !== b.id) return
    drawerBook = b
    renderDrawer(b, positions)
}

function tocHtml(items) {
    if (!items?.length) return ''
    return `<ul>${items.map(i => `<li>${esc(i.label)}${i.page ? ` <span class="muted">· с. ${i.page}</span>` : ''}${tocHtml(i.subitems)}</li>`).join('')}</ul>`
}

function renderDrawer(b, positions) {
    const dr = $('#drawer')
    const fmt = FORMATS[b.format]
    const mine = new Set(S.links.get(b.id) || [])
    const warns = [...(b.warnings?.upload || []), ...(b.warnings?.process || [])]
    const dev = device()
    const readable = canRead(b)
    const tagList = kind => S.cols.filter(c => c.kind === kind)
        .map(c => `<button class="tag ${mine.has(c.id) ? 'on' : ''}" data-col="${c.id}">${esc(c.name)}</button>`).join('')
        + `<button class="tag add" data-newcol="${kind}">+ ${kind === 'category' ? 'категория' : 'коллекция'}</button>`

    dr.innerHTML = `
        <button class="icon-btn dr-close" data-close aria-label="Закрыть">✕</button>
        <div class="dr-top">
            ${coverHtml(b)}
            <div style="min-width:0">
                <div class="dr-t">${esc(titleOf(b))}</div>
                <div class="dr-a">${esc(b.authors || 'Автор не указан')}</div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <span class="chip">${esc(fmt?.label || b.format)}</span>
                    ${isReady(b) ? `<span class="chip ok">${esc(STATUS[b.status])}</span>` : statusChip(b)}
                    ${fmt?.convert && b.derived ? '<span class="chip">читается версия EPUB</span>' : ''}
                </div>
                <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
                    <select class="inp" id="dr-rs" aria-label="Статус чтения">${Object.entries(READ_STATUS).map(([k, l]) => `<option value="${k}" ${b.read_status === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
                    <button class="icon-btn ${b.favorite ? 'on' : ''}" id="dr-fav" title="Избранное" aria-pressed="${b.favorite}">★</button>
                </div>
            </div>
        </div>
        ${b.progress > 0 ? `<div class="bar"><i style="width:${b.progress * 100}%"></i></div><div class="muted" style="font-size:12px;margin-top:6px">Прочитано ${pct(b.progress)}${b.position_label ? ' · ' + esc(b.position_label) : ''}</div>` : ''}
        <div class="dr-acts">
            ${readable ? `<a class="btn" href="/library/read.php?id=${b.id}">${b.progress > 0 ? 'Продолжить' : 'Читать'}</a>` : ''}
            ${fmt?.view && b.status !== 'unsupported' ? `<a class="btn ghost" href="/library/read.php?id=${b.id}&mode=view">Вид документа</a>` : ''}
            <a class="btn ghost" href="${fileUrl(b, 'original', true)}">⬇ Оригинал</a>
            ${b.derived ? `<a class="btn ghost" href="${fileUrl(b, 'derived', true)}">⬇ EPUB-версия</a>` : ''}
            ${readable ? `<button class="btn ghost" id="dr-off">${S.offlineIds.has(b.id) ? '✓ На устройстве' : '⬇ Сохранить на устройстве'}</button>` : ''}
            ${fmt && !b.drm && b.status !== 'unsupported' ? `<button class="btn ghost" id="dr-re">↻ ${b.status === 'error' ? 'Повторить обработку' : 'Переобработать'}</button>` : ''}
            ${fmt && ['pdf', 'djvu'].includes(b.format) && b.has_text === false ? `<a class="btn ghost" href="/library/read.php?id=${b.id}&ocr=1">${b.ocr_status === 'done' ? 'Распознать заново (OCR)' : b.ocr_pages ? 'Продолжить распознавание (OCR)' : 'Распознать текст (OCR)'}</a>` : ''}
            <button class="btn danger" id="dr-del">Удалить</button>
        </div>
        <div id="dr-stage" class="muted" style="font-size:13px;margin:-8px 0 14px"></div>
        ${b.error ? `<div class="alert err"><span>${esc(b.error)}</span></div>` : ''}
        ${b.structure_changed ? `<div class="alert"><span>После переобработки структура книги изменилась: старые закладки и позиции могут указывать не точно.</span><button class="btn sm ghost" id="dr-sack">Понятно</button></div>` : ''}
        ${outdatedConverter(b) ? `<div class="alert"><span>Книга сделана старой версией конвертера (v${b.converter_version}). Переобработка улучшит результат; закладки и заметки сохранятся.</span></div>` : ''}
        ${warns.length ? `<div class="box dr-sec"><div class="box-h"><b>Замечания обработки</b></div><div class="box-b"><ul class="warns">${warns.map(w => `<li>${esc(w)}</li>`).join('')}</ul></div></div>` : ''}

        <div class="box dr-sec"><div class="box-h"><b>Категории и коллекции</b></div><div class="box-b">
            <div class="muted" style="font-size:12px;margin-bottom:6px">Категории</div><div class="tags" style="margin-bottom:12px">${tagList('category')}</div>
            <div class="muted" style="font-size:12px;margin-bottom:6px">Коллекции</div><div class="tags">${tagList('collection')}</div>
        </div></div>

        ${positions.length ? `<div class="box dr-sec"><div class="box-h"><b>Где вы остановились</b><span>по устройствам</span></div>
            ${positions.map(p => `<div class="item"><div class="grow"><b>${esc(p.device_name || 'Устройство')}</b>${p.device_id === dev.id ? ' <span class="chip">это устройство</span>' : ''}
                <div class="muted" style="font-size:12px">${pct(p.fraction)}${p.label ? ' · ' + esc(p.label) : ''}</div></div>
                <div class="muted" style="font-size:12px">${fmtAgo(p.client_ts)}</div></div>`).join('')}</div>` : ''}

        <form class="box dr-sec" id="dr-meta"><div class="box-h"><b>Описание книги</b><span>поля, исправленные вручную, обработка не перезаписывает</span></div><div class="box-b">
            <div class="form">
                <label class="full">Название<input class="inp" name="title" value="${esc(b.title)}" placeholder="${esc(stem(b.orig_name))}"></label>
                <label class="full">Авторы (через «;»)<input class="inp" name="authors" value="${esc(b.authors)}"></label>
                <label>Язык<input class="inp" name="language" value="${esc(b.language)}" placeholder="ru, tg, en…"></label>
                <label>Год<input class="inp" name="year" value="${esc(b.year)}"></label>
                <label>Издательство<input class="inp" name="publisher" value="${esc(b.publisher)}"></label>
                <label>Серия<input class="inp" name="series" value="${esc(b.series)}"></label>
                <label class="full">ISBN / идентификатор<input class="inp" name="identifier" value="${esc(b.identifier || '')}"></label>
                <label class="full">Аннотация<textarea class="inp" name="description" rows="4">${esc(b.description || '')}</textarea></label>
            </div>
            <div style="margin-top:12px;display:flex;gap:8px;align-items:center"><button class="btn">Сохранить</button><span class="muted" style="font-size:12px" id="dr-meta-s"></span></div>
        </div></form>

        ${b.toc?.length ? `<div class="box dr-sec"><div class="box-h"><b>Оглавление</b></div><div class="box-b toc-l">${tocHtml(b.toc)}</div></div>` : ''}

        <div class="box dr-sec"><div class="box-h"><b>Файл</b></div><div class="box-b"><dl class="kv">
            <dt>Оригинал</dt><dd>${esc(b.orig_name)}</dd>
            <dt>Формат</dt><dd>${esc(fmt?.label || b.format)}${b.drm ? ' · защищён DRM' : ''}</dd>
            <dt>Размер</dt><dd>${fmtSize(b.orig_size)}</dd>
            ${b.pages ? `<dt>Страниц</dt><dd>${b.pages}</dd>` : ''}
            ${b.has_text != null ? `<dt>Текстовый слой</dt><dd>${b.has_text ? 'есть' : 'нет (скан)'}</dd>` : ''}
            ${b.has_text === false ? `<dt>Распознано (OCR)</dt><dd>${b.ocr_pages ? `${b.ocr_pages} из ${b.pages || '?'} стр.${b.ocr_lang ? ' · ' + esc(b.ocr_lang) : ''}` : 'нет'}</dd>` : ''}
            <dt>Поиск по тексту</dt><dd>${b.indexed || b.ocr_pages ? 'текст проиндексирован' : 'не проиндексирован'}</dd>
            <dt>SHA-256</dt><dd class="num" style="font-size:11px">${esc(b.sha256)}</dd>
            <dt>Добавлена</dt><dd>${fmtDate(b.created_at, true)}</dd>
            ${b.last_opened_at ? `<dt>Открыта</dt><dd>${fmtDate(b.last_opened_at, true)}</dd>` : ''}
            ${b.finished_at ? `<dt>Прочитана</dt><dd>${fmtDate(b.finished_at)}</dd>` : ''}
            <dt>Обработка</dt><dd>${esc(STATUS[b.status] || b.status)}</dd>
            ${b.derived ? `<dt>Версия для чтения</dt><dd>EPUB, ${fmtSize(b.derived_size)} · ${esc(b.converter)} v${b.converter_version}</dd>` : ''}
        </dl></div></div>`

    const rs = $('#dr-rs', dr)
    rs.onchange = () => saveBook(b, { read_status: rs.value })
    $('#dr-fav', dr).onclick = () => saveBook(b, { favorite: !b.favorite }, true)
    $('#dr-del', dr).onclick = () => deleteBook(b)
    const re = $('#dr-re', dr)
    if (re) re.onclick = () => reprocess(b)
    const off = $('#dr-off', dr)
    if (off) off.onclick = () => offline.toggle(b, off).then(() => { S.offlineIds = new Set(offline.idsSync()); render() })
    const sack = $('#dr-sack', dr)
    if (sack) sack.onclick = async () => { await api('structure_ack', { body: { id: b.id } }); b.structure_changed = false; upsertBook({ id: b.id, structure_changed: false }); sack.closest('.alert').remove() }
    $('#dr-meta', dr).onsubmit = async e => {
        e.preventDefault()
        const data = Object.fromEntries(new FormData(e.target))
        await saveBook(b, data, true)
        $('#dr-meta-s').textContent = 'Сохранено'
    }
    dr.querySelectorAll('[data-col]').forEach(t => t.onclick = () => toggleCollection(b, +t.dataset.col))
    dr.querySelectorAll('[data-newcol]').forEach(t => t.onclick = async () => {
        const kind = t.dataset.newcol
        const name = (prompt(kind === 'category' ? 'Новая категория' : 'Новая коллекция') || '').trim()
        if (!name) return
        try {
            const r = await api('collection_add', { body: { name, kind } })
            S.cols.push({ id: r.id, kind, name })
            await toggleCollection(b, r.id)
        } catch (e) { toast(humanError(e), { error: true }) }
    })
}

async function saveBook(b, data, rerender = false) {
    try {
        const r = await api('book_save', { body: { id: b.id, ...data } })
        upsertBook(r.book)
        Object.assign(b, r.book)
        if (rerender && drawerBook?.id === b.id) renderDrawer(b, [])
        render()
    } catch (e) { toast(humanError(e), { error: true }) }
}

async function toggleCollection(b, cid) {
    const cur = new Set(S.links.get(b.id) || [])
    cur.has(cid) ? cur.delete(cid) : cur.add(cid)
    try {
        const r = await api('book_collections', { body: { book_id: b.id, ids: [...cur] } })
        S.links.set(b.id, new Set(r.ids))
        if (drawerBook?.id === b.id) renderDrawer(b, [])
        render()
    } catch (e) { toast(humanError(e), { error: true }) }
}

async function deleteBook(b) {
    if (!confirm(`Удалить «${titleOf(b)}» из библиотеки?\n\nУдалятся оригинал, версия для чтения, закладки, заметки и история чтения этой книги. Это нельзя отменить — при необходимости сначала скачайте оригинал.`)) return
    try {
        await api('book_del', { method: 'DELETE', params: { id: b.id } })
        await offline.remove(b.id).catch(() => {})
        S.books = S.books.filter(x => x.id !== b.id)
        S.offlineIds.delete(b.id)
        closeDrawer()
        render()
        toast('Книга удалена')
    } catch (e) { toast(humanError(e), { error: true }) }
}

async function reprocess(b) {
    if (b.derived && !confirm('Переобработать книгу?\n\nВерсия для чтения будет создана заново. Закладки и заметки сохранятся, но если структура книги изменится, старые позиции могут указывать не точно.')) return
    const st = $('#dr-stage')
    const nb = await processBook(b, { force: true, onStage: s => { if (st) st.textContent = s } })
    upsertBook(nb)
    if (drawerBook?.id === b.id) openDrawer(b.id)
    render()
    toast(isReady(nb) ? 'Книга обработана' : (nb.error || STATUS[nb.status]), { error: !isReady(nb) })
}

// ── ЗАГРУЗКА КНИГ ─────────────────────────────────────────────────────────────
const queue = []
let queueRunning = false
let queueSeq = 0

export function addFiles(files) {
    for (const file of files) {
        const it = { id: ++queueSeq, name: file.name, file, state: 'wait', msg: 'В очереди' }
        if (S.maxFile && file.size > S.maxFile) { it.state = 'error'; it.msg = `Файл больше ${fmtSize(S.maxFile)}` }
        if (!file.size) { it.state = 'error'; it.msg = 'Пустой файл' }
        queue.push(it)
    }
    renderQueue()
    runQueue()
}

function queueItemHtml(it) {
    const cls = it.state === 'error' ? 'err' : ['done', 'dup'].includes(it.state) ? 'ok' : ''
    const icon = { wait: '⏳', upload: '⬆', process: '⚙', done: '✓', dup: '↺', error: '⚠' }[it.state]
    return `<div class="uq-i" data-q="${it.id}"><div class="uq-n">${esc(it.name)}</div>
        <div class="uq-s ${cls}">${icon} ${esc(it.msg)}${it.bookId && ['done', 'dup'].includes(it.state) ? ` · <a href="#" data-info="${it.bookId}">открыть</a>` : ''}</div>
        ${it.state === 'upload' ? `<div class="bar"><i style="width:${(it.progress || 0) * 100}%"></i></div>` : ''}</div>`
}

function renderQueue() {
    const el = $('#uq')
    if (!queue.length) { el.hidden = true; return }
    const done = queue.filter(i => ['done', 'dup', 'error'].includes(i.state)).length
    el.hidden = false
    el.innerHTML = `<div class="uq-h">Загрузка книг · ${done} из ${queue.length}${!queueRunning ? '<button class="icon-btn" data-uq-close aria-label="Закрыть">✕</button>' : ''}</div>
        <div class="uq-l">${queue.map(queueItemHtml).join('')}</div>`
}

function updateQueueItem(it) {
    const el = document.querySelector(`.uq-i[data-q="${it.id}"]`)
    if (el) el.outerHTML = queueItemHtml(it)
    else renderQueue()
}

async function runQueue() {
    if (queueRunning) return
    queueRunning = true
    renderQueue()
    for (let it; (it = queue.find(i => i.state === 'wait'));) {
        it.state = 'upload'; it.msg = 'Загрузка 0%'
        updateQueueItem(it)
        try {
            const r = await uploadFile(it.file, { onProgress: f => { it.progress = f; it.msg = 'Загрузка ' + pct(f); updateQueueItem(it) } })
            upsertBook(r.book)
            it.bookId = r.book.id
            if (r.duplicate) { it.state = 'dup'; it.msg = 'Уже есть в библиотеке: ' + titleOf(r.book) }
            else if (r.book.status === 'unsupported') { it.state = 'error'; it.msg = r.book.error || STATUS.unsupported }
            else {
                it.state = 'process'; it.msg = 'Проверка…'
                updateQueueItem(it)
                const b = await processBook(r.book, { file: it.file, onStage: s => { it.msg = s; updateQueueItem(it) } })
                upsertBook(b)
                if (isReady(b) && (await loadSettings().catch(() => ({}))).index_content !== false) {
                    try {
                        await indexBook(b, f => { it.msg = 'Индексация текста для поиска ' + pct(f); updateQueueItem(it) })
                        upsertBook({ ...b, indexed: true })
                    } catch (e) { console.warn('индексация', e) }
                }
                it.state = isReady(b) ? 'done' : 'error'
                it.msg = isReady(b) ? STATUS[b.status] : (b.error || STATUS[b.status])
            }
        } catch (e) {
            it.state = 'error'; it.msg = humanError(e)
        }
        it.file = null
        updateQueueItem(it)
        refreshViewSoft()
    }
    queueRunning = false
    renderQueue()
    loadBooks()
}

// Перерисовать список, если человек сейчас ничего не печатает
function refreshViewSoft() {
    const a = document.activeElement
    if (a && /INPUT|TEXTAREA|SELECT/.test(a.tagName) && $('#view').contains(a)) return
    render()
}

// Книги, ожидающие обработки (например, загружены с другого устройства, а вкладку закрыли)
let autoRunning = false
async function autoProcess() {
    if (autoRunning || queueRunning) return
    const list = S.books.filter(needsProcessing)
    if (!list.length) return
    autoRunning = true
    for (const b of list) {
        const it = { id: ++queueSeq, name: titleOf(b), state: 'process', msg: 'Обработка…', bookId: b.id }
        queue.push(it)
        renderQueue()
        const nb = await processBook(b, { onStage: s => { it.msg = s; updateQueueItem(it) } })
        upsertBook(nb)
        it.state = isReady(nb) ? 'done' : 'error'
        it.msg = isReady(nb) ? STATUS[nb.status] : (nb.error || STATUS[nb.status])
        updateQueueItem(it)
        refreshViewSoft()
    }
    autoRunning = false
    renderQueue()
}

window.addEventListener('beforeunload', e => {
    if (queueRunning || autoRunning) { e.preventDefault(); e.returnValue = '' }
})

// перетаскивание файлов в окно
let dragDepth = 0
window.addEventListener('dragenter', e => { if ([...e.dataTransfer.types].includes('Files')) { dragDepth++; $('#drop-ov').classList.add('on') } })
window.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; $('#drop-ov').classList.remove('on') } })
window.addEventListener('dragover', e => e.preventDefault())
window.addEventListener('drop', e => {
    e.preventDefault()
    dragDepth = 0
    $('#drop-ov').classList.remove('on')
    const files = [...(e.dataTransfer?.files || [])]
    if (files.length) addFiles(files)
})
$('#file-input').addEventListener('change', e => { addFiles([...e.target.files]); e.target.value = '' })

// ── КОЛЛЕКЦИИ И КАТЕГОРИИ ─────────────────────────────────────────────────────
function renderCollections(v) {
    const box = (kind, title, hint) => {
        const list = S.cols.filter(c => c.kind === kind)
        const n = id => S.books.filter(b => S.links.get(b.id)?.has(id)).length
        return `<div class="box"><div class="box-h"><b>${title}</b><span>${hint}</span></div>
            ${list.map(c => `<div class="item"><div class="grow"><a href="#library?col=${c.id}" style="color:var(--text);font-weight:600;text-decoration:none">${esc(c.name)}</a>
                <div class="muted" style="font-size:12px">${n(c.id)} ${plural(n(c.id), 'книга', 'книги', 'книг')}</div></div>
                <button class="icon-btn" data-ren="${c.id}" title="Переименовать">✎</button>
                <button class="icon-btn" data-delcol="${c.id}" title="Удалить">✕</button></div>`).join('') || '<div class="empty sm">Пока пусто</div>'}
            <form class="item" data-addcol="${kind}" style="background:var(--surface2)"><input class="inp grow" name="name" placeholder="${kind === 'category' ? 'Новая категория, напр. «Фикх»' : 'Новая коллекция, напр. «Летнее чтение»'}"><button class="btn">+ Добавить</button></form>
        </div>`
    }
    v.innerHTML = `<div class="cols">
        ${box('category', 'Категории', 'тематика: история, фикх, художественное…')}
        ${box('collection', 'Коллекции', 'свои подборки, книга может быть в нескольких')}
    </div>
    <p class="muted" style="font-size:13px;margin-top:14px">Добавить книгу в категорию или коллекцию — в карточке книги (кнопка ⋯).</p>`
    v.querySelectorAll('[data-addcol]').forEach(f => f.onsubmit = async e => {
        e.preventDefault()
        const name = f.name.value.trim()
        if (!name) return
        try { const r = await api('collection_add', { body: { name, kind: f.dataset.addcol } }); S.cols.push({ id: r.id, kind: f.dataset.addcol, name }); render() }
        catch (e) { toast(humanError(e), { error: true }) }
    })
    v.querySelectorAll('[data-ren]').forEach(b => b.onclick = async () => {
        const c = S.cols.find(x => x.id === +b.dataset.ren)
        const name = (prompt('Новое название', c.name) || '').trim()
        if (!name || name === c.name) return
        try { await api('collection_save', { body: { id: c.id, name } }); c.name = name; render() } catch (e) { toast(humanError(e), { error: true }) }
    })
    v.querySelectorAll('[data-delcol]').forEach(b => b.onclick = async () => {
        const c = S.cols.find(x => x.id === +b.dataset.delcol)
        if (!confirm(`Удалить «${c.name}»? Книги останутся в библиотеке.`)) return
        try {
            await api('collection_del', { method: 'DELETE', params: { id: c.id } })
            S.cols = S.cols.filter(x => x.id !== c.id)
            S.links.forEach(s => s.delete(c.id))
            render()
        } catch (e) { toast(humanError(e), { error: true }) }
    })
}

// ── ЗАМЕТКИ И ЦИТАТЫ ──────────────────────────────────────────────────────────
async function renderNotes(v, p) {
    v.innerHTML = '<div class="empty sm">Загрузка заметок…</div>'
    S.anns = await extra.loadAllAnnotations().catch(() => [])
    const type = p.get('type') || ''
    const q = p.get('q') || ''
    let list = S.anns.filter(a => !type || a.type === type)
    if (q) { const n = norm(q); list = list.filter(a => norm(a.text).includes(n) || norm(a.note).includes(n) || norm(titleOf(bookById(a.book_id) || {})).includes(n)) }
    const groups = new Map()
    list.sort((a, b) => a.book_id - b.book_id || a.fraction - b.fraction).forEach(a => {
        if (!groups.has(a.book_id)) groups.set(a.book_id, [])
        groups.get(a.book_id).push(a)
    })
    const types = { '': 'Все', quote: 'Цитаты', highlight: 'Выделения', note: 'Заметки', bookmark: 'Закладки' }
    const cnt = t => S.anns.filter(a => !t || a.type === t).length
    v.innerHTML = `
        <div class="lib-tools">
            <div class="lib-title">Заметки и цитаты <span class="muted" style="font-size:14px">${list.length}</span></div>
            <div class="search" style="flex:1 1 220px"><input class="inp" id="n-q" type="search" placeholder="Поиск по заметкам" value="${esc(q)}"></div>
            <button class="btn ghost" data-export="md">⬇ Markdown</button>
            <button class="btn ghost" data-export="csv">⬇ CSV</button>
            <button class="btn ghost" data-export="json">⬇ JSON</button>
        </div>
        <div class="tags" style="margin-bottom:16px">${Object.entries(types).map(([k, l]) => `<a class="tag ${type === k ? 'on' : ''}" href="#notes${k ? '?type=' + k : ''}" style="text-decoration:none">${l} · ${cnt(k)}</a>`).join('')}</div>
        ${groups.size ? [...groups].map(([bid, items]) => {
            const b = bookById(bid)
            return `<div class="box" style="margin-bottom:16px"><div class="box-h"><b>${esc(b ? titleOf(b) : 'Удалённая книга')}</b><span>${esc(b?.authors || '')}</span></div>
                ${items.map(extra.annHtml).join('')}</div>`
        }).join('') : `<div class="box"><div class="empty"><div class="empty-ico">📝</div>Заметок пока нет.<br>Выделите текст в книге — появится меню «Выделить / Заметка / Цитата».</div></div>`}`
    const nq = $('#n-q', v)
    let t
    nq.oninput = () => { clearTimeout(t); t = setTimeout(() => { history.replaceState(null, '', '#notes?' + new URLSearchParams({ ...(type ? { type } : {}), q: nq.value })); renderNotes(v, route().p).then(() => { const n = $('#n-q'); n.focus(); n.setSelectionRange(n.value.length, n.value.length) }) }, 300) }
    v.querySelectorAll('[data-export]').forEach(b => b.onclick = () => extra.exportNotes(b.dataset.export, list))
}

// ── НАСТРОЙКИ ─────────────────────────────────────────────────────────────────
async function renderSettings(v) {
    const dev = device()
    const st = syncState()
    v.innerHTML = `<div class="cols">
        <div class="box"><div class="box-h"><b>Хранилище на сервере</b></div><div class="box-b">
            <div class="meter"><i style="width:${Math.min(100, S.used / S.quota * 100).toFixed(1)}%"></i></div>
            <div class="muted" style="font-size:13px">${fmtSize(S.used)} из ${fmtSize(S.quota)} · ${S.books.length} ${plural(S.books.length, 'книга', 'книги', 'книг')} · один файл — до ${fmtSize(S.maxFile)}</div>
        </div></div>
        <div class="box"><div class="box-h"><b>Синхронизация</b></div><div class="box-b" id="set-sync">
            <dl class="kv">
                <dt>Это устройство</dt><dd>${esc(dev.name)}</dd>
                <dt>Последняя синхронизация</dt><dd id="set-last">${st.lastOk ? fmtDate(st.lastOk, true) : '—'}</dd>
                <dt>Ждут отправки</dt><dd id="set-pend">${st.pending}</dd>
            </dl>
            <button class="btn ghost" id="set-flush" style="margin-top:12px">Синхронизировать сейчас</button>
            <p class="muted" style="font-size:12px;margin-top:10px">Позиция, закладки, заметки и настройки чтения сохраняются на этом устройстве и отправляются на сервер, как только есть связь. «Сохранено» показывается только после ответа сервера.</p>
        </div></div>
        <div class="box" id="set-offline"></div>
        <div class="box" id="set-extra"></div>
    </div>`
    $('#set-flush', v).onclick = async () => { await flush(); renderSettings(v); toast(syncState().error ? 'Не удалось: ' + syncState().error : 'Синхронизировано', { error: !!syncState().error }) }
    offline.renderSettings($('#set-offline', v), () => { S.offlineIds = new Set(offline.idsSync()) })
    extra.renderSettingsExtra($('#set-extra', v))
}

// ── ОБЩИЕ СОБЫТИЯ ─────────────────────────────────────────────────────────────
document.addEventListener('click', e => {
    const t = e.target
    if (t.closest('[data-upload]')) { $('#file-input').click(); return }
    if (t.closest('[data-close]')) { closeDrawer(); return }
    if (t.closest('[data-uq-close]')) { queue.length = 0; renderQueue(); return }
    if (t.closest('[data-reprocess-all]')) { reprocessAll(); return }
    const info = t.closest('[data-info]')
    if (info) { e.preventDefault(); e.stopPropagation(); openDrawer(+info.dataset.info); return }
    const open = t.closest('[data-open]')
    if (open) {
        const b = bookById(open.dataset.open)
        if (b && canRead(b) && (!S.offlineMode || S.offlineIds.has(b.id))) location.href = '/library/read.php?id=' + b.id
        else if (b) openDrawer(b.id)
    }
})

async function reprocessAll() {
    for (const b of S.books.filter(outdatedConverter)) {
        const it = { id: ++queueSeq, name: titleOf(b), state: 'process', msg: 'Переобработка…', bookId: b.id }
        queue.push(it)
        renderQueue()
        const nb = await processBook(b, { force: true, onStage: s => { it.msg = s; updateQueueItem(it) } })
        upsertBook(nb)
        it.state = isReady(nb) ? 'done' : 'error'
        it.msg = isReady(nb) ? 'Готово' : (nb.error || STATUS[nb.status])
        updateQueueItem(it)
    }
    render()
}

// индикатор синхронизации в шапке
sync.addEventListener('state', e => {
    const s = e.detail, pill = $('#sync-pill')
    if (!pill) return
    pill.className = 'sync-pill' + (s.error && s.pending ? (s.online ? ' warn' : ' err') : '')
    pill.textContent = s.pending ? (s.online && !s.error ? '⟳ Сохраняется…' : `⚠ Не отправлено: ${s.pending}`) : s.lastOk ? '☁ Синхронизировано ' + fmtAgo(s.lastOk) : ''
    const last = $('#set-last'), pend = $('#set-pend')
    if (last) last.textContent = s.lastOk ? fmtDate(s.lastOk, true) : '—'
    if (pend) pend.textContent = s.pending
})

offline.registerSW()
loadBooks()
