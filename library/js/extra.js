// Заметки (вывод и экспорт), поиск по тексту книг, дневник, статистика,
// экспорт, резервные копии, индексация содержимого.
import { api, esc, fmtSize, fmtDate, pct, plural, toast, humanError, parseDate, $ } from './common.js'
import { idb } from './store.js'
import { loadSettings, saveSettings } from './sync.js'
import { uploadFile } from './upload.js'
import { S, bookById, titleOf, render, loadBooks } from './catalog.js'
import { indexBook, clearIndex } from './indexer.js'

export const ANN_TYPE = { bookmark: 'Закладка', highlight: 'Выделение', note: 'Заметка', quote: 'Цитата' }
const ANN_ICON = { bookmark: '🔖', highlight: '🖍', note: '📝', quote: '❝' }
export const COLORS = { yellow: '#f5d14a', green: '#7fd17f', blue: '#7fb6f0', pink: '#f08fb5', purple: '#b59af0' }

// Все заметки: сервер + неотправленные с этого устройства (свежая правка побеждает)
export async function loadAllAnnotations() {
    const local = await idb.all('annotations').catch(() => [])
    const byId = new Map(local.map(a => [a.id, a]))
    try {
        const r = await api('annotations', { params: { with_deleted: 1 } })
        for (const s of r.items) {
            const l = byId.get(s.id)
            if (!l || s.updated_ts >= l.updated_ts) byId.set(s.id, s)
        }
    } catch (e) {}
    return [...byId.values()].filter(a => !a.deleted)
}

export function annHtml(a) {
    const where = [a.chapter, a.locator?.page ? 'с. ' + a.locator.page : '', a.fraction ? pct(a.fraction) : ''].filter(Boolean).join(' · ')
    return `<div class="ann" data-ann="${esc(a.id)}" data-book="${a.book_id}">
        <div class="ann-m">${ANN_ICON[a.type] || ''} ${ANN_TYPE[a.type] || a.type}${where ? ' · ' + esc(where) : ''} · ${fmtDate(a.updated_ts)}</div>
        ${a.text ? `<div class="ann-q" style="border-color:${COLORS[a.color] || 'var(--accent)'}">${esc(a.text)}</div>` : ''}
        ${a.note ? `<div class="ann-n">${esc(a.note)}</div>` : ''}
    </div>`
}

document.addEventListener('click', e => {
    const a = e.target.closest('[data-ann][data-book]')
    if (a) location.href = `/library/read.php?id=${a.dataset.book}#ann=${a.dataset.ann}`
    const h = e.target.closest('[data-hit]')
    if (h) location.href = h.dataset.hit
})

function download(name, text, type) {
    const url = URL.createObjectURL(new Blob([text], { type }))
    const a = Object.assign(document.createElement('a'), { href: url, download: name })
    document.body.append(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 5000)
}
const csvCell = v => /[";\n\r]/.test(String(v ?? '')) ? '"' + String(v).replace(/"/g, '""') + '"' : String(v ?? '')
const stamp = () => new Date().toISOString().slice(0, 10)

export function exportNotes(fmt, list) {
    const title = id => { const b = bookById(id); return b ? titleOf(b) : 'Удалённая книга' }
    if (fmt === 'json') return download(`notes-${stamp()}.json`, JSON.stringify(list, null, 2), 'application/json')
    if (fmt === 'csv') {
        const rows = [['Книга', 'Автор', 'Тип', 'Текст', 'Комментарий', 'Глава', 'Прогресс', 'Страница', 'Цвет', 'Создано', 'Изменено']]
        for (const a of list) {
            const b = bookById(a.book_id)
            rows.push([title(a.book_id), b?.authors || '', ANN_TYPE[a.type], a.text, a.note, a.chapter, pct(a.fraction), a.locator?.page || '',
                       a.color, new Date(a.created_ts).toISOString(), new Date(a.updated_ts).toISOString()])
        }
        return download(`notes-${stamp()}.csv`, '﻿' + rows.map(r => r.map(csvCell).join(';')).join('\r\n'), 'text/csv')
    }
    let md = `# Заметки и цитаты\n\nВыгружено ${new Date().toLocaleString('ru-RU')}\n`
    const groups = new Map()
    list.forEach(a => { if (!groups.has(a.book_id)) groups.set(a.book_id, []); groups.get(a.book_id).push(a) })
    for (const [bid, items] of groups) {
        const b = bookById(bid)
        md += `\n## ${title(bid)}${b?.authors ? ' — ' + b.authors : ''}\n`
        for (const a of items.sort((x, y) => x.fraction - y.fraction)) {
            const where = [a.chapter, a.locator?.page ? 'с. ' + a.locator.page : '', pct(a.fraction)].filter(Boolean).join(', ')
            md += `\n**${ANN_TYPE[a.type]}** · ${where}\n`
            if (a.text) md += '\n' + a.text.split('\n').map(l => '> ' + l).join('\n') + '\n'
            if (a.note) md += '\n' + a.note + '\n'
        }
    }
    download(`notes-${stamp()}.md`, md, 'text/markdown')
}

// ── ПОИСК ПО ТЕКСТУ КНИГ ──────────────────────────────────────────────────────
export async function contentSearch(q) {
    if (q.trim().length < 3) return []
    const r = await api('search', { params: { q } })
    return r.hits
}

export function hitHtml(h) {
    const b = bookById(h.book_id)
    const loc = h.locator || {}
    const href = `/library/read.php?id=${h.book_id}&q=${encodeURIComponent(h.query)}` + (loc.page ? `&page=${loc.page}` : loc.index != null ? `&section=${loc.index}` : '')
    const snippet = esc(h.snippet).replace(new RegExp(esc(h.query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), m => `<mark>${m}</mark>`)
    return `<div class="ann" data-hit="${esc(href)}"><div class="ann-m">${esc(b ? titleOf(b) : '')}${loc.page ? ' · с. ' + loc.page : ''}${h.source === 'ocr' ? ' · распознанный текст' : ''}</div>
        <div class="ann-n">…${snippet}…</div></div>`
}

// ── ДНЕВНИК ЧТЕНИЯ ────────────────────────────────────────────────────────────
const localDay = ts => { const d = new Date(ts); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }
const hm = ts => { const d = new Date(ts); return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') }
const fmtDur = s => s < 60 ? `${s} с` : s < 3600 ? `${Math.round(s / 60)} мин` : `${Math.floor(s / 3600)} ч ${Math.round(s % 3600 / 60)} мин`

export async function renderDiary(v) {
    v.innerHTML = '<div class="empty sm">Загрузка дневника…</div>'
    let sessions = []
    try { sessions = (await api('diary', { params: { limit: 300 } })).sessions } catch (e) { v.innerHTML = `<div class="empty">${esc(humanError(e))}</div>`; return }
    const finished = S.books.filter(b => b.finished_at).sort((a, b) => parseDate(b.finished_at) - parseDate(a.finished_at))
    const days = new Map()
    for (const s of sessions) {
        const d = localDay(s.started_ts)
        if (!days.has(d)) days.set(d, [])
        days.get(d).push(s)
    }
    v.innerHTML = `<div class="cols" style="grid-template-columns:minmax(0,2fr) minmax(280px,1fr)">
        <div>${days.size ? [...days].map(([d, list]) => {
            const total = list.reduce((s, x) => s + x.seconds, 0)
            return `<div class="box" style="margin-bottom:14px"><div class="box-h"><b>${fmtDate(d + ' 12:00')}</b><span>${fmtDur(total)}</span></div>
                ${list.map(s => {
                    const b = bookById(s.book_id)
                    return `<div class="item"><div class="grow"><a href="/library/read.php?id=${s.book_id}" style="color:var(--text);font-weight:600;text-decoration:none">${esc(b ? titleOf(b) : 'Удалённая книга')}</a>
                        <div class="muted" style="font-size:12px">${hm(s.started_ts)}–${hm(s.ended_ts)} · ${esc(s.device_name || '')} · ${pct(s.from_fraction)} → ${pct(s.to_fraction)}</div></div>
                        <div class="num" style="font-weight:700">${fmtDur(s.seconds)}</div></div>`
                }).join('')}</div>`
        }).join('') : '<div class="box"><div class="empty"><div class="empty-ico">📖</div>Дневник заполняется сам, пока вы читаете: время, книга, сколько прочитано.</div></div>'}</div>
        <div class="box"><div class="box-h"><b>Прочитанные книги</b><span>${finished.length}</span></div>
            ${finished.map(b => `<div class="item"><div class="grow"><b>${esc(titleOf(b))}</b><div class="muted" style="font-size:12px">${esc(b.authors || '')} · ${fmtDate(b.finished_at)}</div></div></div>`).join('') || '<div class="empty sm">Пока нет. Отметьте книгу «Прочитана» в её карточке или дочитайте до конца.</div>'}
        </div></div>`
}

// ── СТАТИСТИКА ────────────────────────────────────────────────────────────────
export async function renderStats(v) {
    v.innerHTML = '<div class="empty sm">Загрузка статистики…</div>'
    let st, settings
    try { [st, settings] = await Promise.all([api('stats', { params: { tz: new Date().getTimezoneOffset() } }), loadSettings()]) } catch (e) { v.innerHTML = `<div class="empty">${esc(humanError(e))}</div>`; return }
    const year = new Date().getFullYear()
    const goal = +settings.goal_books || 0
    const doneYear = st.finished_by_year[year] || 0
    const sum = n => Object.entries(st.days).filter(([d]) => Date.now() - Date.parse(d) < n * 86400000).reduce((s, [, x]) => s + x, 0)

    // последние 30 дней — минуты чтения
    const days = [...Array(30)].map((_, i) => {
        const d = new Date(Date.now() - (29 - i) * 86400000)
        const key = localDay(d.getTime())
        return { key, d, min: Math.round((st.days[key] || 0) / 60) }
    })
    const max = Math.max(10, ...days.map(x => x.min))
    const W = 640, H = 200, L = 34, B = 24, T = 10, ih = H - T - B, cw = (W - L - 6) / days.length
    const y = m => T + ih - m / max * ih
    let svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Минуты чтения по дням за 30 дней" style="width:100%;height:auto;display:block">`
    for (let i = 0; i <= 2; i++) {
        const m = Math.round(max / 2 * i)
        svg += `<line x1="${L}" x2="${W - 6}" y1="${y(m)}" y2="${y(m)}" stroke="var(--border)"/><text x="${L - 6}" y="${y(m) + 4}" text-anchor="end" font-size="10" fill="var(--muted)">${m}</text>`
    }
    days.forEach((x, i) => {
        const bw = Math.min(14, cw * .62), bx = L + i * cw + (cw - bw) / 2, h = T + ih - y(x.min)
        if (h > 0) {
            const r = Math.min(4, bw / 2, h)
            svg += `<path d="M${bx},${T + ih}V${y(x.min) + r}Q${bx},${y(x.min)} ${bx + r},${y(x.min)}H${bx + bw - r}Q${bx + bw},${y(x.min)} ${bx + bw},${y(x.min) + r}V${T + ih}Z" fill="var(--accent)"><title>${fmtDate(x.key + ' 12:00')}: ${x.min} мин</title></path>`
        }
        svg += `<rect x="${L + i * cw}" y="${T}" width="${cw}" height="${ih}" fill="transparent"><title>${fmtDate(x.key + ' 12:00')}: ${x.min} мин</title></rect>`
        if (i % 5 === 4 || i === 29) svg += `<text x="${bx + bw / 2}" y="${H - 6}" text-anchor="middle" font-size="10" fill="var(--muted)">${x.d.getDate()}.${String(x.d.getMonth() + 1).padStart(2, '0')}</text>`
    })
    svg += '</svg>'

    v.innerHTML = `
        <div class="kpi">
            <div class="kpi-card"><div class="kpi-lbl">За 7 дней</div><div class="kpi-val">${fmtDur(sum(7))}</div></div>
            <div class="kpi-card"><div class="kpi-lbl">За 30 дней</div><div class="kpi-val">${fmtDur(sum(30))}</div></div>
            <div class="kpi-card"><div class="kpi-lbl">Дней подряд</div><div class="kpi-val">${st.streak}</div><div class="kpi-sub">с чтением хотя бы 1 минуту</div></div>
            <div class="kpi-card"><div class="kpi-lbl">Прочитано в ${year}</div><div class="kpi-val">${doneYear}${goal ? ` <span style="font-size:15px;color:var(--muted)">из ${goal}</span>` : ''}</div>
                ${goal ? `<div class="meter"><i style="width:${Math.min(100, doneYear / goal * 100)}%"></i></div>` : '<div class="kpi-sub">цель на год — ниже</div>'}</div>
        </div>
        <div class="cols">
            <div class="box"><div class="box-h"><b>Минуты чтения по дням</b><span>последние 30 дней · наведите на столбец</span></div><div class="box-b">${svg}
                <details style="margin-top:8px;font-size:12px"><summary class="muted">Таблицей</summary>
                <div style="columns:3;margin-top:6px">${days.filter(x => x.min).map(x => `<div>${fmtDate(x.key + ' 12:00')} — ${x.min} мин</div>`).join('') || 'Пока нет данных'}</div></details></div></div>
            <div class="box"><div class="box-h"><b>Цель на год</b></div><div class="box-b">
                <form id="goal-f" style="display:flex;gap:8px;align-items:center"><input class="inp" type="number" min="0" max="1000" name="goal" value="${goal || ''}" placeholder="Сколько книг" style="width:140px"><button class="btn">Сохранить</button></form>
                <dl class="kv" style="margin-top:14px">
                    <dt>Всего книг</dt><dd>${S.books.length}</dd>
                    <dt>Прочитано всего</dt><dd>${S.books.filter(b => b.read_status === 'finished').length}</dd>
                    <dt>Читаю сейчас</dt><dd>${S.books.filter(b => b.read_status === 'reading').length}</dd>
                    <dt>Всего времени</dt><dd>${fmtDur(st.total_seconds)}</dd>
                    <dt>Сеансов чтения</dt><dd>${st.sessions}</dd>
                </dl>
                ${Object.keys(st.finished_by_year).length ? `<div class="muted" style="font-size:12px;margin-top:12px">По годам: ${Object.entries(st.finished_by_year).sort().reverse().map(([y, n]) => `${y} — ${n}`).join(', ')}</div>` : ''}
            </div></div>
            <div class="box"><div class="box-h"><b>Больше всего времени</b></div>
                ${st.per_book.slice(0, 10).map(x => { const b = bookById(x.book_id); return b ? `<div class="item"><div class="grow"><b>${esc(titleOf(b))}</b><div class="muted" style="font-size:12px">${esc(b.authors || '')}</div></div><div class="num">${fmtDur(x.seconds)}</div></div>` : '' }).join('') || '<div class="empty sm">Пока нет данных</div>'}
            </div>
        </div>`
    $('#goal-f', v).onsubmit = async e => {
        e.preventDefault()
        const s = await loadSettings()
        await saveSettings({ ...s, goal_books: Math.max(0, +e.target.goal.value || 0) })
        toast('Цель сохранена')
        renderStats(v)
    }
}

// ── НАСТРОЙКИ: индексация, экспорт, резервные копии ───────────────────────────
export async function renderSettingsExtra(el) {
    const s = await loadSettings()
    const indexOn = s.index_content !== false
    const pending = S.books.filter(b => ['ready', 'converted'].includes(b.status) && !b.indexed).length
    el.innerHTML = `<div class="box-h"><b>Поиск по тексту, экспорт и резервные копии</b></div><div class="box-b">
        <label style="display:flex;gap:10px;align-items:flex-start;font-size:14px;cursor:pointer">
            <input type="checkbox" id="idx-on" ${indexOn ? 'checked' : ''} style="margin-top:3px">
            <span>Индексировать текст книг для поиска<br><span class="muted" style="font-size:12px">Текст извлекается в браузере и хранится на вашем сервере отдельно от оригиналов. Выключите — индекс можно очистить.</span></span></label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 18px">
            <button class="btn sm ghost" id="idx-run" ${!indexOn || !pending ? 'disabled' : ''}>Проиндексировать (${pending})</button>
            <button class="btn sm ghost" id="idx-clear">Очистить индекс</button>
        </div>
        <div style="font-weight:700;font-size:13px;margin-bottom:8px">Экспорт</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px">
            <a class="btn sm ghost" href="/api/library.php?action=export">⬇ Все данные (JSON)</a>
            <button class="btn sm ghost" id="exp-csv">⬇ Список книг (CSV)</button>
            <a class="btn sm ghost" href="#notes">Заметки → Markdown / CSV</a>
        </div>
        <p class="muted" style="font-size:12px;margin-bottom:16px">JSON — описание книг с контрольными суммами, коллекции, заметки, закладки, позиции, история чтения и настройки. Сами файлы — в полном архиве.</p>
        <div style="font-weight:700;font-size:13px;margin-bottom:8px">Резервная копия</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px">
            <a class="btn sm" href="/api/library.php?action=backup">⬇ Полный архив (${fmtSize(S.used)})</a>
            <button class="btn sm ghost" id="bk-verify">Проверить архив</button>
            <button class="btn sm ghost" id="bk-restore">Восстановить из архива</button>
        </div>
        <p class="muted" style="font-size:12px">ZIP с оригиналами, версиями для чтения, обложками и всеми данными (library.json). Восстановление не дублирует книги, которые уже есть, и сверяет контрольные суммы каждого файла.</p>
        <div id="bk-report" style="margin-top:10px"></div>
        <div style="border-top:1px solid var(--border);margin-top:18px;padding-top:14px">
            <div style="font-weight:700;font-size:13px;margin-bottom:6px">Доступ для ИИ-помощника</div>
            <p class="muted" style="font-size:12px;margin-bottom:8px">Ключ даёт внешнему ИИ-помощнику доступ <b>только на чтение</b> к каталогу, заметкам и проиндексированному тексту — чтобы спрашивать «что я читал о…», «собери мои цитаты по теме». Файлы книг по ключу не отдаются, изменить ничего нельзя. Адрес: <code>${esc(location.origin)}/api/library_ai.php</code>, ключ — в заголовке <code>Authorization: Bearer …</code>.</p>
            <div id="ai-list" style="font-size:13px;margin-bottom:8px"></div>
            <button class="btn sm ghost" id="ai-add">+ Создать ключ</button>
        </div>
        <div style="border-top:1px solid var(--border);margin-top:18px;padding-top:14px">
            <button class="btn sm danger" id="wipe">Удалить всю библиотеку…</button>
            <p class="muted" style="font-size:12px;margin-top:6px">Удаляет книги, заметки и историю с сервера. Сначала скачайте полный архив.</p>
        </div>
    </div>
    <input type="file" id="bk-file" accept=".zip" hidden>`

    $('#idx-on', el).onchange = async e => {
        await saveSettings({ ...(await loadSettings()), index_content: e.target.checked })
        toast(e.target.checked ? 'Индексация включена' : 'Индексация выключена — новые книги не индексируются')
        renderSettingsExtra(el)
    }
    $('#idx-run', el).onclick = async e => {
        const btn = e.target
        btn.disabled = true
        for (const b of S.books.filter(b => ['ready', 'converted'].includes(b.status) && !b.indexed)) {
            btn.textContent = 'Индексация: ' + titleOf(b).slice(0, 30) + '…'
            try { await indexBook(b); b.indexed = true } catch (x) { console.warn(x) }
        }
        toast('Индексация завершена')
        renderSettingsExtra(el)
    }
    $('#idx-clear', el).onclick = async () => {
        if (!confirm('Очистить индекс текста всех книг? Распознанный OCR-текст тоже удалится.')) return
        await clearIndex()
        S.books.forEach(b => b.indexed = false)
        toast('Индекс очищен')
        renderSettingsExtra(el)
    }
    $('#exp-csv', el).onclick = () => {
        const rows = [['Название', 'Авторы', 'Формат', 'Статус чтения', 'Прогресс', 'Год', 'Издательство', 'Язык', 'Серия', 'ISBN', 'Файл', 'Размер', 'SHA-256', 'Добавлена', 'Открыта', 'Прочитана']]
        for (const b of S.books) rows.push([titleOf(b), b.authors, b.format_label, b.read_status, pct(b.progress), b.year, b.publisher, b.language, b.series, b.identifier || '',
            b.orig_name, b.orig_size, b.sha256, b.created_at, b.last_opened_at || '', b.finished_at || ''])
        download(`books-${stamp()}.csv`, '﻿' + rows.map(r => r.map(csvCell).join(';')).join('\r\n'), 'text/csv')
    }
    let mode = ''
    const pick = m => { mode = m; $('#bk-file', el).click() }
    $('#bk-verify', el).onclick = () => pick('verify')
    $('#bk-restore', el).onclick = () => pick('restore')
    $('#bk-file', el).onchange = async e => {
        const file = e.target.files[0]
        e.target.value = ''
        if (!file) return
        const rep = $('#bk-report', el)
        const show = t => rep.innerHTML = `<div class="muted" style="font-size:13px">${esc(t)}</div>`
        if (mode === 'restore' && !confirm('Восстановить библиотеку из архива?\n\nКниги, которые уже есть, не дублируются. Заметки, закладки и позиции из архива добавятся к текущим; более свежие правки не перезаписываются.')) return
        try {
            show('Загрузка архива 0%…')
            const up = await uploadFile(file, { purpose: 'backup', as: 'backup', onProgress: f => show(`Загрузка архива ${Math.round(f * 100)}%…`) })
            const x = { books: up.books, ok: 0, added: 0, skipped: 0, annotations: 0, errors: [] }
            for (let offset = 0; ;) {
                show(`${mode === 'verify' ? 'Проверка' : 'Восстановление'}: ${offset} из ${up.books} ${plural(up.books, 'книги', 'книг', 'книг')}…`)
                const r = await api('restore_step', { body: { backup_id: up.backup_id, mode, offset } })
                for (const k of ['ok', 'added', 'skipped', 'annotations']) x[k] += r.report[k]
                x.errors.push(...r.report.errors)
                offset = r.offset
                if (r.done) break
            }
            rep.innerHTML = `<div class="alert ${x.errors.length ? 'err' : ''}" style="display:block">
                <b>${mode === 'verify' ? 'Проверка архива' : 'Восстановление'}: ${x.errors.length ? 'есть проблемы' : 'всё в порядке'}</b><br>
                Книг в архиве: ${x.books} · файлы целы: ${x.ok}${mode === 'restore' ? ` · добавлено: ${x.added} · уже были: ${x.skipped} · заметок: ${x.annotations}` : ''}
                ${x.errors.length ? `<ul class="warns" style="margin-top:8px">${x.errors.slice(0, 30).map(t => `<li>${esc(t)}</li>`).join('')}</ul>` : ''}</div>`
            if (mode === 'restore') await loadBooks()
        } catch (x) { rep.innerHTML = `<div class="alert err">${esc(humanError(x))}</div>` }
    }
    const aiList = async () => {
        const box = $('#ai-list', el)
        try {
            const { tokens } = await api('tokens')
            box.innerHTML = tokens.map(t => `<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px"><span class="grow">🔑 ${esc(t.name)} · создан ${fmtDate(t.created_at)}${t.last_used_at ? ' · использован ' + fmtDate(t.last_used_at, true) : ' · ещё не использовался'}</span><button class="btn sm ghost" data-tokdel="${t.id}">Отозвать</button></div>`).join('') || '<span class="muted">Ключей нет</span>'
            box.querySelectorAll('[data-tokdel]').forEach(b => b.onclick = async () => {
                if (!confirm('Отозвать ключ? Помощник с этим ключом потеряет доступ.')) return
                await api('token_del', { method: 'DELETE', params: { id: b.dataset.tokdel } })
                aiList()
            })
        } catch (x) { box.textContent = humanError(x) }
    }
    aiList()
    $('#ai-add', el).onclick = async () => {
        const name = prompt('Для чего ключ? (например: «Claude»)', 'ИИ-помощник')
        if (name === null) return
        try {
            const { token } = await api('token_add', { body: { name } })
            await navigator.clipboard?.writeText(token).catch(() => {})
            prompt('Ключ создан и скопирован. Сохраните его — повторно он не показывается:', token)
            aiList()
        } catch (x) { toast(humanError(x), { error: true }) }
    }
    $('#wipe', el).onclick = async () => {
        const word = prompt('Это удалит ВСЮ библиотеку с сервера: книги, заметки, историю. Отменить нельзя.\n\nСначала скачайте полный архив. Для подтверждения введите слово УДАЛИТЬ')
        if (word !== 'УДАЛИТЬ') return
        try {
            await api('wipe', { body: { confirm: 'УДАЛИТЬ' } })
            for (const k of await idb.keys('files')) await idb.del('files', k)
            toast('Библиотека удалена')
            location.hash = '#home'
            location.reload()
        } catch (x) { toast(humanError(x), { error: true }) }
    }
}
