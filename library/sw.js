// Service worker библиотеки: оболочка приложения и движки чтения доступны без сети.
// Книги здесь НЕ кэшируются — их хранит IndexedDB (js/offline.js), только выбранные.
// Данные API (позиции, заметки) тоже не кэшируются — для них очередь в js/sync.js.
const VERSION = 'lib-v1'
const SHELL = `${VERSION}-shell`
const STATIC = `${VERSION}-static`
const COVERS = `${VERSION}-covers`

const PRECACHE = [
    '/library/css/library.css', '/library/css/reader.css',
    '/library/js/catalog.js', '/library/js/common.js', '/library/js/engines.js', '/library/js/extra.js', '/library/js/formats.js',
    '/library/js/indexer.js', '/library/js/offline.js', '/library/js/process.js', '/library/js/store.js', '/library/js/sync.js', '/library/js/upload.js',
    '/library/js/convert/epub-writer.js', '/library/js/convert/txt.js', '/library/js/convert/docx.js', '/library/js/convert/rtf.js',
    '/library/js/reader/main.js', '/library/js/reader/reflow.js', '/library/js/reader/pdf.js', '/library/js/reader/djvu.js',
    '/library/js/reader/docxview.js', '/library/js/reader/ocr.js',
    '/library/vendor/foliate-js/view.js', '/library/vendor/foliate-js/epub.js', '/library/vendor/foliate-js/epubcfi.js',
    '/library/vendor/foliate-js/fb2.js', '/library/vendor/foliate-js/mobi.js', '/library/vendor/foliate-js/paginator.js',
    '/library/vendor/foliate-js/fixed-layout.js', '/library/vendor/foliate-js/overlayer.js', '/library/vendor/foliate-js/progress.js',
    '/library/vendor/foliate-js/search.js', '/library/vendor/foliate-js/text-walker.js', '/library/vendor/foliate-js/footnotes.js',
    '/library/vendor/foliate-js/vendor/zip.js', '/library/vendor/foliate-js/vendor/fflate.js', '/library/vendor/fflate/fflate.js',
    '/library/vendor/pdfjs/pdf.min.mjs', '/library/vendor/pdfjs/pdf.worker.min.mjs',
    '/library/vendor/pdfjs/web/pdf_viewer.mjs', '/library/vendor/pdfjs/web/pdf_viewer.css',
    '/library/vendor/djvu/djvu.js', '/library/vendor/djvu/djvu_viewer.js',
    '/library/vendor/docx-preview/jszip.min.js', '/library/vendor/docx-preview/docx-preview.min.js',
    '/library/icon.svg', '/library/manifest.webmanifest',
]
const PAGES = ['/library/', '/library/read.php']

self.addEventListener('install', e => {
    e.waitUntil((async () => {
        const c = await caches.open(STATIC)
        // по одному: отсутствие одного файла не должно ломать установку
        await Promise.all(PRECACHE.map(u => c.add(u).catch(() => {})))
        const s = await caches.open(SHELL)
        await Promise.all(PAGES.map(async u => {
            try {
                const r = await fetch(u, { credentials: 'same-origin' })
                if (r.ok && !r.redirected) await s.put(u, r)
            } catch (err) {}
        }))
        self.skipWaiting()
    })())
})

self.addEventListener('activate', e => {
    e.waitUntil((async () => {
        for (const k of await caches.keys()) if (!k.startsWith(VERSION)) await caches.delete(k)
        await self.clients.claim()
    })())
})

self.addEventListener('fetch', e => {
    const req = e.request
    if (req.method !== 'GET') return
    const url = new URL(req.url)
    if (url.origin !== location.origin) return

    // страницы: сначала сеть (свежая версия, проверка входа), без сети — сохранённая копия
    if (req.mode === 'navigate' && url.pathname.startsWith('/library/')) {
        const key = url.pathname.endsWith('read.php') ? '/library/read.php' : '/library/'
        e.respondWith((async () => {
            try {
                const r = await fetch(req)
                if (r.ok && !r.redirected) (await caches.open(SHELL)).put(key, r.clone())
                return r
            } catch (err) {
                return (await caches.match(key)) || new Response('<meta charset="utf-8"><p style="font-family:sans-serif;padding:24px">Нет связи, а страница ещё не сохранена на этом устройстве. Откройте библиотеку один раз с интернетом.</p>',
                    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } })
            }
        })())
        return
    }

    // обложки — из кэша (для каталога без сети)
    if (url.pathname === '/api/library_file.php' && url.searchParams.get('kind') === 'cover') {
        e.respondWith((async () => {
            const c = await caches.open(COVERS)
            const hit = await c.match(req)
            if (hit) return hit
            const r = await fetch(req)
            if (r.ok) c.put(req, r.clone())
            return r
        })())
        return
    }
    if (url.pathname.startsWith('/api/')) return

    // наш код — сеть с откатом на кэш; библиотеки vendor — кэш, потом сеть
    if (url.pathname.startsWith('/library/')) {
        const vendor = url.pathname.startsWith('/library/vendor/')
        e.respondWith((async () => {
            const c = await caches.open(STATIC)
            if (vendor) {
                const hit = await c.match(req, { ignoreSearch: true })
                if (hit) return hit
            }
            try {
                const r = await fetch(req)
                if (r.ok && r.type === 'basic') c.put(req, r.clone())
                return r
            } catch (err) {
                const hit = await c.match(req, { ignoreSearch: true })
                if (hit) return hit
                throw err
            }
        })())
    }
})
