// Общие помощники раздела «Библиотека»
export const API  = '/api/library.php'
export const FILE = '/api/library_file.php'
export const VENDOR = '/library/vendor'

export class ApiError extends Error {
    constructor(message, status, data) { super(message); this.status = status; this.data = data }
}

// api('books') · api('book_save', {body}) · api('upload_chunk', {params, raw}) · api('book_del', {method:'DELETE', params})
export async function api(action, { method, body, params, raw, signal, keepalive } = {}) {
    const qs = new URLSearchParams({ action, ...(params || {}) })
    const hasBody = body !== undefined || raw !== undefined
    let r
    try {
        r = await fetch(API + '?' + qs, {
            method: method || (hasBody ? 'POST' : 'GET'),
            headers: raw !== undefined ? { 'Content-Type': 'application/octet-stream' }
                   : body !== undefined ? { 'Content-Type': 'application/json' } : {},
            body: raw !== undefined ? raw : body !== undefined ? JSON.stringify(body) : undefined,
            credentials: 'same-origin', signal, keepalive,
        })
    } catch (e) {
        if (e.name === 'AbortError') throw e
        throw new ApiError('Нет связи с сервером', 0)
    }
    if (r.status === 401) { location.href = '/login.php'; throw new ApiError('Нужно войти', 401) }
    const j = await r.json().catch(() => ({ ok: false, error: 'Ошибка ответа сервера (' + r.status + ')' }))
    if (!j.ok) throw new ApiError(j.error || 'Ошибка', r.status, j)
    return j
}

export function fileUrl(book, kind = 'original', dl = false) {
    return `${FILE}?id=${book.id}&kind=${kind}&v=${book.v || ''}${dl ? '&dl=1' : ''}`
}

export const $ = (sel, root = document) => root.querySelector(sel)
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)]

export function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
}

export function fmtSize(n) {
    if (!n) return '0 Б'
    const u = ['Б', 'КБ', 'МБ', 'ГБ']
    let i = 0
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++ }
    return (i ? n.toFixed(n < 10 ? 1 : 0) : n) + ' ' + u[i]
}

const MO_G = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря']
export const parseDate = s => s ? new Date(typeof s === 'number' ? s : s.replace(' ', 'T')) : null

// «сегодня в 14:32», «вчера», «12 октября», «12 октября 2025»
export function fmtDate(s, withTime = false) {
    const d = parseDate(s)
    if (!d || isNaN(d)) return ''
    const now = new Date()
    const day = x => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
    const diff = Math.round((day(now) - day(d)) / 86400000)
    const time = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
    if (diff === 0) return 'сегодня' + (withTime ? ' в ' + time : '')
    if (diff === 1) return 'вчера' + (withTime ? ' в ' + time : '')
    return d.getDate() + ' ' + MO_G[d.getMonth()] + (d.getFullYear() !== now.getFullYear() ? ' ' + d.getFullYear() : '') + (withTime ? ' в ' + time : '')
}

export function fmtAgo(ts) {
    const s = Math.round((Date.now() - ts) / 1000)
    if (s < 60) return 'только что'
    if (s < 3600) return Math.round(s / 60) + ' мин назад'
    if (s < 86400) return Math.round(s / 3600) + ' ч назад'
    return fmtDate(ts)
}

export const pct = f => Math.round((f || 0) * 100) + '%'

export const plural = (n, one, few, many) => {
    const a = Math.abs(n) % 100, b = a % 10
    return a > 10 && a < 20 ? many : b > 1 && b < 5 ? few : b === 1 ? one : many
}

export function uuid() {
    if (crypto.randomUUID) return crypto.randomUUID()
    const b = crypto.getRandomValues(new Uint8Array(16))
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80
    const h = [...b].map(x => x.toString(16).padStart(2, '0')).join('')
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

// Устройство: постоянный id и понятное имя («iPhone · Safari»)
export function device() {
    let id = null
    try { id = localStorage.getItem('ikhlas_lib_device') } catch (e) {}
    if (!id) {
        id = uuid()
        try { localStorage.setItem('ikhlas_lib_device', id) } catch (e) {}
    }
    const ua = navigator.userAgent
    const touchMac = /Macintosh/.test(ua) && navigator.maxTouchPoints > 1
    const os = /iPad/.test(ua) || touchMac ? 'iPad' : /iPhone/.test(ua) ? 'iPhone' : /Android/.test(ua) ? 'Android'
             : /Macintosh/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : /Linux/.test(ua) ? 'Linux' : 'Устройство'
    const br = /YaBrowser/.test(ua) ? 'Яндекс' : /Edg\//.test(ua) ? 'Edge' : /Firefox|FxiOS/.test(ua) ? 'Firefox'
             : /Chrome|CriOS/.test(ua) ? 'Chrome' : /Safari/.test(ua) ? 'Safari' : ''
    return { id, name: br ? `${os} · ${br}` : os }
}

// Подключить обычный (не модульный) скрипт из vendor один раз
const scripts = new Map()
export function loadScript(src) {
    if (!scripts.has(src)) scripts.set(src, new Promise((resolve, reject) => {
        const s = document.createElement('script')
        s.src = src
        s.onload = resolve
        s.onerror = () => reject(new Error('Не загрузился ' + src))
        document.head.append(s)
    }))
    return scripts.get(src)
}

// Всплывающее сообщение; action = {label, run}
export function toast(msg, { error = false, action, ms } = {}) {
    let t = document.getElementById('lib-toast')
    if (!t) {
        t = document.createElement('div')
        t.id = 'lib-toast'
        t.setAttribute('role', 'status')
        document.body.append(t)
    }
    t.className = 'on' + (error ? ' err' : '')
    t.innerHTML = `<span>${esc(msg)}</span>` + (action ? `<button type="button">${esc(action.label)}</button>` : '')
    if (action) t.querySelector('button').onclick = () => { t.className = ''; action.run() }
    clearTimeout(t._t)
    t._t = setTimeout(() => t.className = '', ms || (action ? 7000 : error ? 5000 : 2800))
}

// Текст ошибки для человека
export function humanError(e) {
    const m = e?.message || String(e)
    if (/Failed to fetch|NetworkError|Нет связи/i.test(m)) return 'Нет связи с сервером'
    if (/QuotaExceeded/i.test(m)) return 'На устройстве не хватает места'
    return m.length > 400 ? m.slice(0, 400) + '…' : m
}

// Строки метаданных foliate-js: строка, {ru: …} или массив
export function langMap(x) {
    if (!x) return ''
    if (typeof x === 'string') return x
    if (Array.isArray(x)) return x.map(langMap).filter(Boolean).join('; ')
    if (x.name) return langMap(x.name)
    const k = Object.keys(x)
    return k.length ? langMap(x[k[0]]) : ''
}

export const sleep = ms => new Promise(r => setTimeout(r, ms))

// Простой хэш строки (FNV-1a) — отпечаток структуры книги
export function hashStr(s) {
    let h = 0x811c9dc5
    for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 0x01000193) >>> 0 }
    return h.toString(16).padStart(8, '0')
}
