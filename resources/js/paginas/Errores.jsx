import { Link } from 'react-router-dom';
import { FileQuestion, ShieldX } from 'lucide-react';

function Pantalla({ icono: I, titulo, texto }) {
    return (
        <div className="flex flex-col items-center gap-3 py-20 text-center">
            <I className="size-16 text-suave" aria-hidden />
            <h1 className="text-2xl font-bold">{titulo}</h1>
            <p className="max-w-md text-suave">{texto}</p>
            <Link to="/" className="btn btn-primario mt-2">Volver al inicio</Link>
        </div>
    );
}

export const NoEncontrado = () => <Pantalla icono={FileQuestion} titulo="Página no encontrada" texto="La dirección que buscó no existe o fue movida." />;
export const SinPermiso = () => (
    <Pantalla icono={ShieldX} titulo="Acceso denegado" texto="Su rol no tiene permiso para ver esta sección. Si cree que es un error, contacte al administrador." />
);
