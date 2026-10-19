// Распознавание текста сканов (PDF, DjVu) — tesseract.js прямо в браузере.
// Страницы никуда не отправляются: на сервер уходит только распознанный текст,
// он хранится отдельно от оригинала (оригинальный файл не меняется).
// Можно остановить и продолжить позже: уже распознанные страницы пропускаются.
import { api, esc, loadScript, VENDOR, toast, humanError } from '../common.js'

const LANGS = { rus: 'Русский', tgk: 'Тоҷикӣ', eng: 'English' }
const MAX_SIDE = 3000       // больше — только память, точность не растёт

function fit(canvas) {
    const k = Math.min(1, MAX_SIDE / Math.max(canvas.width, canvas.height))
    if (k === 1) return canvas
    const c = document.createElement('canvas')
    c.width = Math.round(canvas.width * k); c.height = Math.round(canvas.height * k)
    c.getContext('2d').drawImage(canvas, 0, 0, c.width, c.height)
    return c
}

const fmtTime = s => s < 90 ? `${Math.round(s)} с` : s < 5400 ? `${Math.round(s / 60)} мин` : `${(s / 3600).toFixed(1)} ч`

export async function openOcrDialog({ book, engine, modal }) {
    const total = engine.pageCount?.() || book.pages || 0
    let state = { pages_done: [], lang: book.ocr_lang || '' }
    try { state = await api('ocr_state', { params: { id: book.id } }) } catch (e) {}
    const done = new Set(state.pages_done)
    const langs = new Set((state.lang || 'rus').split('+').filter(l => LANGS[l]))
    let running = false, stop = false, failed = []

    modal.innerHTML = `<div class="ocr">
        <h3>Распознавание текста (OCR)</h3>
        <p class="pp-note">Страницы распознаются на этом устройстве, в интернет ничего не отправляется. Распознанный текст хранится на вашем сервере отдельно от оригинала —
        сам файл не меняется. После распознавания работают поиск по книге и общий поиск. Большие книги удобнее распознавать на компьютере; вкладку не закрывайте.</p>
        <div class="ocr-row"><b>Языки</b>${Object.entries(LANGS).map(([k, l]) => `<label><input type="checkbox" value="${k}" ${langs.has(k) ? 'checked' : ''}> ${l}</label>`).join('')}</div>
        <div class="ocr-row"><b>Страницы</b> с <input type="number" id="ocr-from" min="1" max="${total}" value="1"> по <input type="number" id="ocr-to" min="1" max="${total}" value="${total}"> из ${total}</div>
        <div class="ocr-row"><label><input type="checkbox" id="ocr-skip" checked> пропускать уже распознанные (${done.size})</label></div>
        <div class="meter"><i id="ocr-bar" style="width:${total ? done.size / total * 100 : 0}%"></i></div>
        <div class="pp-note" id="ocr-st">${done.size ? `Уже распознано ${done.size} из ${total} страниц.` : 'Ещё не распознавалось.'}</div>
        <div class="acts">
            <button class="btn" id="ocr-go">${done.size ? 'Продолжить' : 'Начать'}</button>
            <button class="btn ghost" id="ocr-stop" hidden>Остановить</button>
            <button class="btn ghost" id="ocr-retry" hidden>Повторить ошибки</button>
            <span class="grow"></span><button class="btn ghost" id="ocr-x">Закрыть</button>
        </div></div>`
    modal.hidden = false
    const $ = s => modal.querySelector(s)
    modal.querySelectorAll('input').forEach(i => i.addEventListener('keydown', e => e.stopPropagation()))
    const status = t => { $('#ocr-st').innerHTML = t }
    const close = () => {
        if (running && !confirm('Остановить распознавание? Уже распознанные страницы сохранены.')) return
        stop = true
        modal.hidden = true
        modal.innerHTML = ''
    }
    $('#ocr-x').onclick = close
    $('#ocr-stop').onclick = () => { stop = true; status('Останавливаем после текущей страницы…') }

    async function run(pages) {
        const lang = [...modal.querySelectorAll('.ocr-row input[type=checkbox][value]:checked')].map(i => i.value).join('+')
        if (!lang) { toast('Выберите хотя бы один язык', { error: true }); return }
        running = true; stop = false; failed = []
        $('#ocr-go').hidden = true; $('#ocr-retry').hidden = true; $('#ocr-stop').hidden = false
        let worker
        try {
            status('Загрузка распознавателя и языков…')
            await loadScript(VENDOR + '/tesseract/tesseract.min.js')
            worker = await window.Tesseract.createWorker(lang, 1, {
                workerPath: VENDOR + '/tesseract/worker.min.js',
                corePath: VENDOR + '/tesseract/core',
                langPath: VENDOR + '/tesseract/lang',
                workerBlobURL: false, gzip: true,
            })
            const t0 = Date.now()
            let n = 0, batch = []
            const flushBatch = async () => {
                if (!batch.length) return
                const r = await api('ocr_put', { body: { book_id: book.id, lang, total, pages: batch } })
                batch.forEach(b => done.add(b.page))
                batch = []
                book.ocr_status = r.ocr_status; book.ocr_pages = r.ocr_pages
            }
            for (const p of pages) {
                if (stop) break
                try {
                    const canvas = fit(await engine.renderPageImage(p, 3))
                    const { data } = await worker.recognize(canvas)
                    batch.push({ page: p, text: data.text || '' })
                    canvas.width = canvas.height = 0
                } catch (e) {
                    console.warn('OCR страница', p, e)
                    failed.push(p)
                }
                n++
                if (batch.length >= 3) await flushBatch()
                const left = (Date.now() - t0) / 1000 / n * (pages.length - n)
                $('#ocr-bar').style.width = (total ? (done.size + batch.length) / total * 100 : 0) + '%'
                status(`Страница ${p} · ${n} из ${pages.length}${pages.length - n ? ` · осталось около ${fmtTime(left)}` : ''}${failed.length ? ` · ошибок: ${failed.length}` : ''}`)
            }
            await flushBatch()
            status(stop ? `Остановлено. Распознано ${done.size} из ${total} страниц — можно продолжить позже.`
                 : failed.length ? `Готово с ошибками: не распознаны страницы ${esc(failed.slice(0, 30).join(', '))}${failed.length > 30 ? '…' : ''}.`
                 : `Готово: распознано ${done.size} из ${total} страниц. Поиск по тексту работает.`)
            if (!stop && !failed.length) toast('Распознавание завершено')
        } catch (e) {
            console.error(e)
            status(`<span style="color:var(--red)">Ошибка: ${esc(humanError(e))}</span>. Уже распознанные страницы сохранены — можно повторить.`)
        } finally {
            await worker?.terminate().catch(() => {})
            running = false
            $('#ocr-stop').hidden = true
            $('#ocr-go').hidden = false
            $('#ocr-go').textContent = 'Продолжить'
            $('#ocr-retry').hidden = !failed.length
        }
    }

    $('#ocr-go').onclick = () => {
        const from = Math.max(1, +$('#ocr-from').value || 1), to = Math.min(total, +$('#ocr-to').value || total)
        const skip = $('#ocr-skip').checked
        const pages = []
        for (let p = from; p <= to; p++) if (!skip || !done.has(p)) pages.push(p)
        if (!pages.length) { status('Все выбранные страницы уже распознаны. Снимите «пропускать», чтобы распознать заново.'); return }
        run(pages)
    }
    $('#ocr-retry').onclick = () => run([...failed])
}
