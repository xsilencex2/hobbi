// Чтение без интернета: выбранные книги хранятся на устройстве (IndexedDB),
// вместе с карточкой каталога. Удаление локальной копии не трогает книгу на
// сервере и не трогает неотправленные заметки/позицию — они в очереди sync.js.
import { esc, fmtSize, fmtDate, toast, humanError, fileUrl } from './common.js'
import { readSource } from './formats.js'
import { idb, kv, storageEstimate, persistStorage } from './store.js'
import { fetchBook } from './process.js'
import { flush, loadAnnotations, loadSettings } from './sync.js'

let ids = new Set()

export async function offlineIds() {
    const keys = await idb.keys('files')
    ids = new Set(keys.map(k => +String(k).split(':')[0]))
    return [...ids]
}
export const idsSync = () => [...ids]

export function registerSW() {
    if (!('serviceWorker' in navigator)) return
    navigator.serviceWorker.register('/library/sw.js', { scope: '/library/' }).catch(e => console.warn('SW:', e))
}

// Копия каталога — чтобы открыть библиотеку без связи
export async function cacheCatalog(r) {
    await kv.set('catalog', { books: r.books, collections: r.collections, links: r.links, used: r.used, quota: r.quota, at: Date.now() })
}
export async function cachedCatalog() {
    return kv.get('catalog')
}

// Файл для читалки: локальная копия, если она той же версии
export async function getOfflineFile(book) {
    const src = readSource(book)
    if (!src) return null
    const rec = await idb.get('files', `${book.id}:${src.kind}`).catch(() => null)
    if (!rec) return null
    if (src.kind === 'derived' && rec.v !== book.v && navigator.onLine) return null   // производная обновилась — скачаем заново
    return rec.blob
}

export async function save(book, onProgress) {
    const src = readSource(book)
    if (!src) throw new Error('Эту книгу пока нельзя открыть')
    const size = src.kind === 'derived' ? book.derived_size : book.orig_size
    const est = await storageEstimate()
    if (est && est.quota && est.quota - est.usage < size * 1.3 + 20e6) {
        throw new Error(`На устройстве не хватает места: нужно около ${fmtSize(size)}, свободно ${fmtSize(Math.max(0, est.quota - est.usage))}`)
    }
    await persistStorage()
    const blob = await fetchBook(book, src.kind, onProgress)
    try {
        await idb.put('files', { key: `${book.id}:${src.kind}`, book_id: book.id, kind: src.kind, blob, size: blob.size, v: book.v, saved_at: Date.now() })
    } catch (e) {
        throw new Error(/quota/i.test(e?.name + e?.message) ? 'На устройстве не хватает места для этой книги' : humanError(e))
    }
    await idb.put('books', { key: book.id, ...book })
    await loadAnnotations(book.id).catch(() => {})      // закладки и заметки — в локальную копию
    await loadSettings().catch(() => {})
    ids.add(book.id)
}

export async function remove(bookId) {
    for (const kind of ['original', 'derived']) await idb.del('files', `${bookId}:${kind}`).catch(() => {})
    ids.delete(bookId)
}

export async function toggle(book, btn) {
    try {
        if (ids.has(book.id)) {
            await remove(book.id)
            if (btn) btn.textContent = '⬇ Сохранить на устройстве'
            toast('Копия на устройстве удалена. Книга осталась в библиотеке на сервере.')
        } else {
            if (btn) btn.disabled = true
            await save(book, f => { if (btn) btn.textContent = `Сохранение ${Math.round(f * 100)}%` })
            if (btn) { btn.disabled = false; btn.textContent = '✓ На устройстве' }
            toast('Книга сохранена: её можно читать без интернета')
        }
    } catch (e) {
        if (btn) { btn.disabled = false; btn.textContent = '⬇ Сохранить на устройстве' }
        toast(humanError(e), { error: true })
    }
}

export async function renderSettings(el, onChange) {
    const files = await idb.all('files').catch(() => [])
    const books = new Map((await idb.all('books').catch(() => [])).map(b => [b.key, b]))
    const est = await storageEstimate()
    const persisted = await navigator.storage?.persisted?.().catch(() => false)
    const pending = (await idb.keys('outbox').catch(() => [])).length
    const total = files.reduce((s, f) => s + f.size, 0)
    el.innerHTML = `<div class="box-h"><b>На этом устройстве</b><span>для чтения без интернета</span></div>
        <div class="box-b">
            <div class="muted" style="font-size:13px;margin-bottom:8px">Книг: ${files.length} · ${fmtSize(total)}${est?.quota ? ` · браузер разрешает до ${fmtSize(est.quota)}, занято ${fmtSize(est.usage)}` : ''}</div>
            <div class="muted" style="font-size:12px;margin-bottom:10px">${persisted ? '✓ Браузер не будет удалять эти данные сам' : 'Браузер может очистить офлайн-копии при нехватке места (на iPhone — если сайтом долго не пользоваться). Книги на сервере при этом не пропадают.'}</div>
            ${pending ? `<div class="alert"><span>${pending} изменений ещё не отправлены на сервер — они хранятся на этом устройстве и уйдут при появлении связи.</span></div>` : ''}
        </div>
        ${files.map(f => {
            const b = books.get(f.book_id)
            return `<div class="item"><div class="grow"><b>${esc(b?.title || b?.orig_name || 'Книга ' + f.book_id)}</b>
                <div class="muted" style="font-size:12px">${fmtSize(f.size)} · сохранена ${fmtDate(f.saved_at)}${f.kind === 'derived' ? ' · версия для чтения' : ''}</div></div>
                <button class="btn sm ghost" data-offdel="${f.book_id}">Убрать с устройства</button></div>`
        }).join('') || '<div class="empty sm">Сохранённых книг нет. В карточке книги нажмите «Сохранить на устройстве».</div>'}`
    el.querySelectorAll('[data-offdel]').forEach(b => b.onclick = async () => {
        await remove(+b.dataset.offdel)
        onChange?.()
        renderSettings(el, onChange)
        flush()
    })
}
