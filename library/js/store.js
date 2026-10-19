// Локальное хранилище на IndexedDB:
//   outbox      — очередь изменений, ещё не подтверждённых сервером
//   files       — книги, сохранённые для чтения без интернета
//   books       — карточки книг (для офлайна)
//   annotations — закладки и заметки (для офлайна)
//   kv          — прочее: настройки, время синхронизации, последние позиции
// Если IndexedDB недоступна (приватный режим некоторых браузеров) — работаем в памяти.

const NAME = 'ikhlas-library'
const VERSION = 1
let dbPromise = null
const memory = new Map()   // запасной вариант

function open() {
    if (!dbPromise) dbPromise = new Promise(resolve => {
        let req
        try { req = indexedDB.open(NAME, VERSION) } catch (e) { resolve(null); return }
        req.onupgradeneeded = () => {
            const db = req.result
            for (const s of ['outbox', 'files', 'books', 'kv']) {
                if (!db.objectStoreNames.contains(s)) db.createObjectStore(s, { keyPath: 'key' })
            }
            if (!db.objectStoreNames.contains('annotations')) {
                db.createObjectStore('annotations', { keyPath: 'id' }).createIndex('book', 'book_id')
            }
        }
        req.onsuccess = () => resolve(req.result)
        req.onerror = () => resolve(null)
        req.onblocked = () => resolve(null)
    })
    return dbPromise
}

const mem = store => { if (!memory.has(store)) memory.set(store, new Map()); return memory.get(store) }
const keyOf = (store, v) => store === 'annotations' ? v.id : v.key

function run(store, mode, fn) {
    return open().then(db => {
        if (!db) return fn(null)
        return new Promise((resolve, reject) => {
            const t = db.transaction(store, mode)
            let result
            Promise.resolve(fn(t.objectStore(store))).then(r => { result = r })
            t.oncomplete = () => resolve(result)
            t.onerror = () => reject(t.error)
            t.onabort = () => reject(t.error || new Error('IndexedDB: транзакция прервана'))
        })
    })
}

const reqP = req => new Promise((resolve, reject) => { req.onsuccess = () => resolve(req.result); req.onerror = () => reject(req.error) })

export const idb = {
    get: (store, key) => run(store, 'readonly', s => s ? reqP(s.get(key)) : mem(store).get(key)),
    put: (store, value) => run(store, 'readwrite', s => s ? reqP(s.put(value)) : void mem(store).set(keyOf(store, value), value)),
    del: (store, key) => run(store, 'readwrite', s => s ? reqP(s.delete(key)) : void mem(store).delete(key)),
    all: store => run(store, 'readonly', s => s ? reqP(s.getAll()) : [...mem(store).values()]),
    keys: store => run(store, 'readonly', s => s ? reqP(s.getAllKeys()) : [...mem(store).keys()]),
    byIndex: (store, index, value) => run(store, 'readonly', s => s ? reqP(s.index(index).getAll(value))
        : [...mem(store).values()].filter(v => v.book_id === value)),
    available: async () => !!(await open()),
}

export const kv = {
    get: async (key, def = null) => (await idb.get('kv', key))?.value ?? def,
    set: (key, value) => idb.put('kv', { key, value }),
}

// Сколько места занято и доступно (оценка браузера)
export async function storageEstimate() {
    try {
        const e = await navigator.storage?.estimate?.()
        return e ? { usage: e.usage || 0, quota: e.quota || 0 } : null
    } catch (e) { return null }
}

// Попросить браузер не удалять офлайн-данные при нехватке места
export async function persistStorage() {
    try { return await navigator.storage?.persist?.() } catch (e) { return false }
}
