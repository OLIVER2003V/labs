/**
 * Validación en el CLIENTE (antes de enviar al servidor).
 * El servidor vuelve a validar todo: esto solo da respuesta inmediata al usuario.
 *
 * Uso: validar(valores, campos) → { campo: 'mensaje de error' }
 * Cada campo puede tener: requerido, min, max (longitud o valor), patron + mensajePatron,
 * tipo ('email' | 'number' | 'date' | 'time'), despuesDe (otro campo), noPasado (fechas)
 */
export function validar(valores, campos) {
    const errores = {};
    const hoy = new Date().toISOString().slice(0, 10);

    for (const c of campos) {
        if (c.oculto?.(valores)) continue;
        const v = valores[c.name];
        const vacio = v === undefined || v === null || String(v).trim() === '';
        const requerido = typeof c.requerido === 'function' ? c.requerido(valores) : c.requerido;
        const nombre = c.label.toLowerCase();

        if (vacio) {
            if (requerido) errores[c.name] = `El campo ${nombre} es obligatorio.`;
            continue;
        }
        if (c.tipo === 'imagen') { // foto elegida: tipo y tamaño (igual que el servidor)
            if (v instanceof File && !['image/jpeg', 'image/png', 'image/webp'].includes(v.type)) errores[c.name] = 'La foto debe ser JPG, PNG o WEBP.';
            else if (v instanceof File && v.size > 4 * 1024 * 1024) errores[c.name] = 'La foto no puede pasar de 4 MB.';
            continue;
        }
        const texto = String(v).trim();

        if (c.tipo === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(texto)) {
            errores[c.name] = 'Ingrese un correo electrónico válido.';
        } else if (c.tipo === 'number') {
            const n = Number(texto);
            if (Number.isNaN(n)) errores[c.name] = `El campo ${nombre} debe ser un número.`;
            else if (c.min !== undefined && n < c.min) errores[c.name] = `El campo ${nombre} debe ser al menos ${c.min}.`;
            else if (c.max !== undefined && n > c.max) errores[c.name] = `El campo ${nombre} no debe ser mayor que ${c.max}.`;
            else if (c.entero && !Number.isInteger(n)) errores[c.name] = `El campo ${nombre} debe ser un número entero.`;
        } else if (c.tipo !== 'select' && c.tipo !== 'checkbox') {
            if (c.min !== undefined && texto.length < c.min) errores[c.name] = `El campo ${nombre} debe tener al menos ${c.min} caracteres.`;
            else if (c.max !== undefined && texto.length > c.max) errores[c.name] = `El campo ${nombre} no debe tener más de ${c.max} caracteres.`;
        }
        if (!errores[c.name] && c.patron && !c.patron.test(texto)) {
            errores[c.name] = c.mensajePatron || `El formato del campo ${nombre} no es válido.`;
        }
        if (!errores[c.name] && c.noPasado && texto < hoy) {
            errores[c.name] = `La ${nombre} no puede ser una fecha pasada.`;
        }
        if (!errores[c.name] && c.noFuturo && texto > hoy) {
            errores[c.name] = `La ${nombre} no puede ser una fecha futura.`;
        }
        if (!errores[c.name] && c.despuesDe && valores[c.despuesDe] && texto <= String(valores[c.despuesDe])) {
            const otro = campos.find((x) => x.name === c.despuesDe)?.label.toLowerCase() ?? c.despuesDe;
            errores[c.name] = `El campo ${nombre} debe ser posterior a ${otro}.`;
        }
        if (!errores[c.name] && c.igualA && texto !== String(valores[c.igualA] ?? '')) {
            errores[c.name] = c.mensajeIgual || 'Los valores no coinciden.';
        }
        if (!errores[c.name] && c.validar) {
            const m = c.validar(texto, valores);
            if (m) errores[c.name] = m;
        }
    }
    return errores;
}

/** Patrones reutilizables */
export const P = {
    soloLetras: /^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ\s]+$/,
    telefono: /^[67]\d{7}$/,
    password: /^(?=.*[A-Za-z])(?=.*\d).{8,}$/,
    codigo: /^[A-Za-z0-9-]{3,20}$/,
};

/** Algoritmo de Luhn (número de tarjeta válido) */
export function luhn(numero) {
    let suma = 0;
    let alternar = false;
    for (let i = numero.length - 1; i >= 0; i--) {
        let d = Number(numero[i]);
        if (alternar) {
            d *= 2;
            if (d > 9) d -= 9;
        }
        suma += d;
        alternar = !alternar;
    }
    return suma % 10 === 0;
}
