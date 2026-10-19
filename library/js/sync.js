// Синхронизация: всё, что меняется на устройстве (позиция, заметки, настройки,
// сеансы чтения), сначала ложится в очередь outbox, потом уходит на сервер.
// Из очереди элемент удаляется только после ответа сервера — до этого он
// переживает закрытие вкладки, перезапуск и отсутствие интернета.
//
// Ключи очереди схлопывают повторы: pos:<книга> хранит только последнюю
// позицию, ann:<id> — последнюю версию заметки.

import { api, device, API } from './common.js'
import { idb, kv } from './store.js'

export const sync = new EventTarget()
const state = { pending: 0, lastOk: 0, error: '', online: navigator.onLine }
let flushing = null
let timer = null

const emit = () => sync.dispatchEvent(new CustomEvent('state', { detail: { ...state } }))
export const syncState = () => ({ ...state })

async function countPending() {
    state.pending = (await idb.keys('outbox')).length
}

async function enqueue(key, kind, payload) {
    await idb.put('outbox', { key, kind, payload, ts: Date.now() })
    await countPending()
    emit()
    clearTimeout(timer)
    timer = setTimeout(flush, 400)
}

// Убрать из очереди, только если за время отправки туда не легла новая версия
async function ack(item) {
    const cur = await idb.get('outbox', item.key)
    if (cur && cur.ts === item.ts) await idb.del('outbox', item.key)
}

// Отправить один элемент. Сервер отверг навсегда (книга удалена, неверные данные) —
// снимаем с очереди, чтобы она не застряла; нет связи или сбой сервера — пробуем позже.
async function send(item, fn) {
    try {
        const r = await fn()
        await ack(item)
        return r
    } catch (e) {
        if (e.status >= 400 && e.status < 500 && ![401, 408, 429].includes(e.status)) {
            await ack(item)
            sync.dispatchEvent(new CustomEvent('dropped', { detail: { item, error: e.message } }))
            return null
        }
        throw e
    }
}

export function flush() {
    if (flushing) return flushing
    flushing = (async () => {
        try {
            const items = await idb.all('outbox')
            if (!items.length) { state.error = ''; return }

            const anns = items.filter(i => i.kind === 'ann')
            for (let i = 0; i < anns.length; i += 200) {
                const part = anns.slice(i, i + 200)
                const r = await api('annotations_sync', { body: { items: part.map(a => a.payload) } })
                if (!r) continue
                for (const res of r.results) {
                    const item = part.find(a => a.payload.id === res.id)
                    if (res.status === 'older' && res.server) {
                        // на сервере правка новее — берём её, свою не навязываем
                        await idb.put('annotations', res.server)
                        sync.dispatchEvent(new CustomEvent('annotation', { detail: res.server }))
                    }
                    if (res.status === 'book_missing') await idb.del('annotations', res.id)
                    if (item) await ack(item)
                }
            }
            for (const item of items.filter(i => i.kind === 'pos')) {
                const r = await send(item, () => api('position', { body: item.payload }))
                if (r) sync.dispatchEvent(new CustomEvent('position-saved', { detail: { ...item.payload, saved_at: r.saved_at } }))
            }
            for (const item of items.filter(i => i.kind === 'settings')) {
                const r = await send(item, () => api('settings', { body: item.payload }))
                if (r?.stale) sync.dispatchEvent(new CustomEvent('settings', { detail: { data: r.data, updated_ts: r.updated_ts } }))
            }
            for (const item of items.filter(i => i.kind === 'session')) {
                await send(item, () => api('session', { body: item.payload }))
            }
            state.lastOk = Date.now()
            state.error = ''
            await kv.set('last_sync', state.lastOk)
        } catch (e) {
            state.error = e.message || 'Ошибка синхронизации'
        } finally {
            await countPending()
            state.online = navigator.onLine
            flushing = null
            emit()
        }
    })()
    return flushing
}

// ── ПОЗИЦИЯ ───────────────────────────────────────────────────────────────────
export function savePosition(bookId, { locator, fraction, label }) {
    const d = device()
    const payload = { book_id: bookId, device_id: d.id, device_name: d.name, locator, fraction, label, client_ts: Date.now() }
    kv.set('pos:' + bookId, payload)          // своя последняя позиция — для офлайна
    return enqueue('pos:' + bookId, 'pos', payload)
}

// При закрытии вкладки fetch может не успеть — дублируем позицию через sendBeacon.
// Элемент из очереди не убираем: если beacon не дошёл, позиция уйдёт позже.
export async function beaconPosition(bookId) {
    const item = await idb.get('outbox', 'pos:' + bookId)
    if (!item || !navigator.sendBeacon) return
    navigator.sendBeacon(API + '?action=position', new Blob([JSON.stringify(item.payload)], { type: 'application/json' }))
}

export async function lastLocalPosition(bookId) {
    return kv.get('pos:' + bookId)
}

// ── ЗАКЛАДКИ И ЗАМЕТКИ ────────────────────────────────────────────────────────
export async function saveAnnotation(a) {
    a.updated_ts = Date.now()
    await idb.put('annotations', a)
    return enqueue('ann:' + a.id, 'ann', a)
}

export async function deleteAnnotation(a) {
    return saveAnnotation({ ...a, deleted: true })
}

// Заметки книги: сервер + локальные неотправленные; при расхождении побеждает более свежая правка
export async function loadAnnotations(bookId) {
    let local = []
    try { local = await idb.byIndex('annotations', 'book', bookId) } catch (e) {}
    try {
        const r = await api('annotations', { params: { book_id: bookId, with_deleted: 1 } })
        const pending = new Set((await idb.keys('outbox')).filter(k => k.startsWith('ann:')).map(k => k.slice(4)))
        const byId = new Map(local.map(a => [a.id, a]))
        for (const s of r.items) {
            const l = byId.get(s.id)
            if (!l || (!pending.has(s.id) && s.updated_ts >= l.updated_ts) || s.updated_ts > l.updated_ts) {
                byId.set(s.id, s)
                await idb.put('annotations', s)
            }
        }
        local = [...byId.values()]
    } catch (e) { /* офлайн — работаем с локальной копией */ }
    return local.filter(a => !a.deleted)
}

// ── НАСТРОЙКИ ЧТЕНИЯ ──────────────────────────────────────────────────────────
export async function loadSettings() {
    const local = await kv.get('settings')
    try {
        const r = await api('settings')
        if (!local || r.updated_ts > local.updated_ts) {
            await kv.set('settings', { data: r.data, updated_ts: r.updated_ts })
            return r.data
        }
    } catch (e) {}
    return local?.data || {}
}

export async function saveSettings(data) {
    const v = { data, updated_ts: Date.now() }
    await kv.set('settings', v)
    return enqueue('settings', 'settings', v)
}

// ── СЕАНСЫ ЧТЕНИЯ ─────────────────────────────────────────────────────────────
export function saveSession(s) {
    return enqueue('ses:' + s.id, 'session', s)
}

// ── ФОН ───────────────────────────────────────────────────────────────────────
window.addEventListener('online', () => { state.online = true; emit(); flush() })
window.addEventListener('offline', () => { state.online = false; emit() })
document.addEventListener('visibilitychange', () => { if (!document.hidden) flush() })
setInterval(() => { if (state.pending) flush() }, 30000)
countPending().then(async () => { state.lastOk = await kv.get('last_sync', 0); emit(); if (state.pending) flush() })
