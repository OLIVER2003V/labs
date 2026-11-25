import api from './api';

/*
 * Exportación de reportes y listas, SIEMPRE en cuadros:
 *   - Excel: lo arma el servidor (App\Support\Excel) con título, encabezado azul, bordes y filas alternadas.
 *   - PDF:   se abre el diálogo de impresión del navegador con una hoja de reporte (logo, tablas con bordes);
 *            ahí se elige "Guardar como PDF". El encabezado de cada tabla se repite en cada página.
 */

/** Guarda un archivo que llegó del servidor, con el nombre que manda el servidor */
function guardar(respuesta, porDefecto) {
    const cd = respuesta.headers?.['content-disposition'] ?? '';
    const nombre = decodeURIComponent((cd.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i)?.[1]) ?? porDefecto);
    const a = document.createElement('a');
    a.href = URL.createObjectURL(respuesta.data);
    a.download = nombre;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/** Un error dentro de una respuesta "blob" viene como Blob: se convierte a JSON para poder leer el mensaje */
async function errorDeBlob(e) {
    if (e.response?.data instanceof Blob) {
        try {
            e.response.data = JSON.parse(await e.response.data.text());
        } catch { /* no era JSON */ }
    }
    return e;
}

/** Reporte del CU8 en Excel (una hoja de resumen + una hoja por tabla) */
export async function excelDeReporte(tipo, params) {
    try {
        guardar(await api.get(`/reportes/${tipo}/excel`, { params, responseType: 'blob' }), `reporte_${tipo}.xlsx`);
    } catch (e) {
        throw await errorDeBlob(e);
    }
}

/** Cualquier tabla de la pantalla → Excel con formato */
export async function excelDeTabla({ titulo, subtitulo, columnas, filas }) {
    try {
        guardar(await api.post('/exportar/excel', { titulo, subtitulo, columnas, filas }, { responseType: 'blob' }), 'exportacion.xlsx');
    } catch (e) {
        throw await errorDeBlob(e);
    }
}

const esc = (v) => String(v ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
const esNumero = (v) => typeof v === 'number';

/** Isotipo de la marca (versión fija para imprimir) */
const LOGO = `<svg viewBox="0 0 110 104" width="46" height="44" xmlns="http://www.w3.org/2000/svg"><rect x="64" y="38" width="6" height="60" fill="#7FC3E0"/><g fill="none" stroke="#0F2A4A" stroke-width="4.2"><path d="M6 64 L41 16 L50 11 L50 98"/><path d="M6 64 L6 71 L41 71 L41 98"/><path d="M17 64 L41 31 L41 64 Z"/><path d="M56 9 L56 98"/><path d="M56 9 L86 25 L86 98"/><path d="M61 24 L81 35 L81 44 L61 33 Z"/><path d="M61 42 L81 53 L81 62 L61 51 Z"/><path d="M61 60 L81 71 L81 80 L61 69 Z"/><path d="M30 98 L98 98"/></g><g stroke-width="3" fill="none"><path d="M86 51 L93 51 L97 42" stroke="#E5432F"/><path d="M86 61 L101 61" stroke="#7FC3E0"/><path d="M86 72 L93 72 L99 78" stroke="#E5432F"/></g><circle cx="98" cy="38" r="4.2" fill="#E5432F"/><circle cx="104" cy="61" r="4.2" fill="#7FC3E0"/><circle cx="101" cy="81" r="4.2" fill="#7FC3E0"/></svg>`;

const ESTILOS = `
  @page { size: A4; margin: 14mm 12mm; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { font-family: "Segoe UI", Calibri, Arial, sans-serif; color: #1E293B; font-size: 10.5px; margin: 0; }
  header { display: flex; align-items: center; gap: 12px; border-bottom: 3px solid #0F2A4A; padding-bottom: 8px; margin-bottom: 4px; }
  header .marca { font-size: 10px; color: #5B6B7F; line-height: 1.3; }
  header .marca b { color: #0F2A4A; font-size: 12px; }
  h1 { font-size: 17px; color: #0F2A4A; margin: 10px 0 2px; }
  .sub { color: #5B6B7F; font-size: 10px; margin: 0 0 12px; }
  .resumen { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 6px; margin-bottom: 14px; }
  .resumen div { border: 1px solid #B8C4D2; border-left: 4px solid #E5432F; border-radius: 4px; padding: 6px 8px; }
  .resumen span { display: block; color: #5B6B7F; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; }
  .resumen b { font-size: 14px; color: #0F2A4A; }
  h2 { font-size: 12px; color: #E5432F; margin: 16px 0 6px; break-after: avoid; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  thead { display: table-header-group; }
  th { background: #0F2A4A; color: #fff; font-weight: 600; text-align: left; padding: 5px 6px; border: 1px solid #0F2A4A; font-size: 9.5px; }
  td { padding: 4px 6px; border: 1px solid #B8C4D2; vertical-align: top; }
  tbody tr:nth-child(even) td { background: #F1F5F9; }
  tr { break-inside: avoid; }
  td.n, th.n { text-align: right; white-space: nowrap; }
  tfoot td { background: #DCEFF8; font-weight: 700; }
  .vacio { color: #5B6B7F; font-style: italic; }
  footer { margin-top: 14px; border-top: 1px solid #B8C4D2; padding-top: 4px; color: #5B6B7F; font-size: 9px; display: flex; justify-content: space-between; }
`;

/**
 * Abre la impresión con formato de reporte.
 * @param {{titulo:string, subtitulo?:string, resumen?:{etiqueta:string, valor:any}[], secciones:{titulo?:string, columnas:string[], filas:any[][], total?:any[]}[]}} r
 */
export function imprimir({ titulo, subtitulo, resumen = [], secciones }) {
    const emitido = new Date().toLocaleString('es-BO', { dateStyle: 'short', timeStyle: 'short' });
    const tabla = (s) => {
        const numCol = s.columnas.map((_, i) => s.filas.length > 0 && s.filas.every((f) => esNumero(f[i]) || f[i] === '—' || f[i] === ''));
        const celda = (v, i) => `<td class="${numCol[i] ? 'n' : ''}">${esc(v)}</td>`;
        return `${s.titulo ? `<h2>${esc(s.titulo)}</h2>` : ''}
            <table>
              <thead><tr>${s.columnas.map((c, i) => `<th class="${numCol[i] ? 'n' : ''}">${esc(c)}</th>`).join('')}</tr></thead>
              <tbody>${s.filas.length ? s.filas.map((f) => `<tr>${s.columnas.map((_, i) => celda(f[i], i)).join('')}</tr>`).join('')
                : `<tr><td colspan="${s.columnas.length}" class="vacio">Sin datos para el filtro elegido.</td></tr>`}</tbody>
              ${s.total ? `<tfoot><tr>${s.columnas.map((_, i) => celda(s.total[i], i)).join('')}</tr></tfoot>` : ''}
            </table>`;
    };
    const html = `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>${esc(titulo)}</title><style>${ESTILOS}</style></head><body>
        <header>${LOGO}<div class="marca"><b>Laboratorios 4to Piso</b><br>Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones · UAGRM</div></header>
        <h1>${esc(titulo)}</h1>
        <p class="sub">${esc(subtitulo ?? '')}${subtitulo ? ' · ' : ''}Emitido el ${esc(emitido)}</p>
        ${resumen.length ? `<div class="resumen">${resumen.map((x) => `<div><span>${esc(x.etiqueta)}</span><b>${esc(x.valor)}</b></div>`).join('')}</div>` : ''}
        ${secciones.map(tabla).join('')}
        <footer><span>Sistema de Gestión de Laboratorios · FICCT - UAGRM</span><span>${esc(emitido)}</span></footer>
      </body></html>`;

    // Se imprime desde un iframe oculto: no abre ventanas nuevas (que el navegador podría bloquear)
    const iframe = document.createElement('iframe');
    iframe.setAttribute('aria-hidden', 'true');
    iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(iframe);
    const doc = iframe.contentDocument;
    doc.open();
    doc.write(html);
    doc.close();
    const quitar = () => iframe.isConnected && iframe.remove();
    iframe.contentWindow.addEventListener('afterprint', () => setTimeout(quitar, 100));
    setTimeout(() => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
        setTimeout(quitar, 120000); // por si el navegador no avisa al terminar
    }, 300);
}
