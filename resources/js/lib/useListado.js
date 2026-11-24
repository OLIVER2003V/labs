import { useCallback, useEffect, useState } from 'react';
import api, { mensajeError } from './api';
import { useToast } from '../componentes/ui';

/** Hook de listado paginado con filtros */
export default function useListado(endpoint, filtros) {
    const notificar = useToast();
    const [datos, setDatos] = useState(null);
    const [pagina, setPagina] = useState(1);
    const clave = JSON.stringify(filtros); // comparar por contenido, no por referencia
    useEffect(() => setPagina(1), [clave]);
    const cargar = useCallback(() => {
        api.get(endpoint, { params: { ...JSON.parse(clave), page: pagina } }).then((r) => setDatos(r.data)).catch((e) => notificar(mensajeError(e), 'error'));
    }, [endpoint, clave, pagina, notificar]);
    useEffect(() => {
        const t = setTimeout(cargar, 300);
        return () => clearTimeout(t);
    }, [cargar]);
    return { datos, setPagina, cargar };
}
