// Загрузка движков по требованию: pdf.js, djvu.js, foliate-js.
// Всё лежит у нас (library/vendor) — без внешних CDN, работает и офлайн.
import { VENDOR, loadScript } from './common.js'

let pdfLib = null
export async function pdfjs() {
    if (!pdfLib) {
        pdfLib = await import(VENDOR + '/pdfjs/pdf.min.mjs')
        pdfLib.GlobalWorkerOptions.workerSrc = VENDOR + '/pdfjs/pdf.worker.min.mjs'
    }
    return pdfLib
}

export async function pdfViewer() {
    await pdfjs()          // pdf_viewer.mjs берёт библиотеку из globalThis.pdfjsLib
    return import(VENDOR + '/pdfjs/web/pdf_viewer.mjs')
}

// Общие параметры открытия PDF: шрифты, кодировки CJK, wasm-декодеры; скрипты из PDF не выполняются
export const PDF_OPTS = {
    cMapUrl: VENDOR + '/pdfjs/cmaps/',
    cMapPacked: true,
    standardFontDataUrl: VENDOR + '/pdfjs/standard_fonts/',
    wasmUrl: VENDOR + '/pdfjs/wasm/',
    iccUrl: VENDOR + '/pdfjs/iccs/',
    isEvalSupported: false,
    enableXfa: false,
}

export async function djvuLib() {
    await loadScript(VENDOR + '/djvu/djvu.js')
    return window.DjVu
}

export async function djvuViewerLib() {
    await djvuLib()
    await loadScript(VENDOR + '/djvu/djvu_viewer.js')
    return window.DjVu
}

export async function foliate() {
    return import(VENDOR + '/foliate-js/view.js')
}
