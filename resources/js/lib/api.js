import axios from 'axios';

/**
 * Cliente HTTP para consumir la API RESTful de Laravel.
 * - baseURL viene de window.APP.api (lo calcula Laravel), así funciona en /inf513/grupo08sa/proyecto2.
 * - Agrega el token Bearer (Sanctum) en cada petición.
 * - Si el servidor responde 401 (token vencido), cierra la sesión.
 */
const api = axios.create({
    baseURL: window.APP?.api ?? '/api',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    timeout: 20000,
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('token');
    if (token) config.headers.Authorization = `Bearer ${token}`;
    return config;
});

api.interceptors.response.use(
    (r) => r,
    (error) => {
        if (error.response?.status === 401 && localStorage.getItem('token')) {
            localStorage.removeItem('token');
            window.dispatchEvent(new Event('sesion-expirada'));
        }
        return Promise.reject(error);
    },
);

/** Mensaje legible de un error de la API (siempre en español) */
export function mensajeError(error) {
    if (!error.response) return 'No se pudo conectar con el servidor. Verifique su conexión.';
    const d = error.response.data;
    if (d?.errors) return Object.values(d.errors)[0][0];
    // Ante un choque de horario el servidor propone laboratorios libres en la misma franja
    if (d?.sugerencias?.length) return `${d.message} Laboratorios libres en esa franja: ${d.sugerencias.join(', ')}.`;
    if (d?.sugerencias) return `${d.message} No hay otros laboratorios libres en esa franja.`;
    return d?.message || 'Ocurrió un error inesperado.';
}

/** Errores de validación por campo: { campo: 'mensaje' } */
export function erroresCampos(error) {
    const e = error.response?.data?.errors;
    if (!e) return {};
    return Object.fromEntries(Object.entries(e).map(([k, v]) => [k, v[0]]));
}

export default api;
