// Загрузка файла кусками. Сервер помнит, сколько уже получено, поэтому после
// обрыва связи загрузка того же файла продолжается с места остановки.
import { api, sleep } from './common.js'

// opts: { onProgress(fraction), signal, as: 'derived', extra: {book_id, converter, converter_version} }
export async function uploadFile(file, { onProgress, signal, as, extra, purpose } = {}) {
    const init = await api('upload_init', { body: { name: file.name, size: file.size, last_modified: file.lastModified || 0, purpose }, signal })
    let offset = init.received
    const chunk = init.chunk
    onProgress?.(offset / file.size)

    while (offset < file.size) {
        const part = file.slice(offset, Math.min(file.size, offset + chunk))
        for (let attempt = 0; ; attempt++) {
            try {
                const r = await api('upload_chunk', { params: { id: init.id, offset }, raw: part, signal })
                offset = r.received
                break
            } catch (e) {
                // сервер уже получил другой объём (например, прошлый кусок дошёл, а ответ — нет)
                if (e.status === 409 && e.data?.received != null) { offset = e.data.received; break }
                if (e.name === 'AbortError') throw e
                const retriable = e.status === 0 || e.status >= 500 || e.status === 408 || e.status === 429
                if (!retriable || attempt >= 5) throw e
                await sleep(Math.min(15000, 1000 * 2 ** attempt))
            }
        }
        onProgress?.(offset / file.size)
    }
    return api('upload_finish', { body: { id: init.id, ...(as ? { as, ...extra } : {}) }, signal })
}
