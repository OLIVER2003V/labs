import { Component } from 'react';
import { AlertOctagon } from 'lucide-react';

/** Si una pantalla falla, muestra un aviso en lugar de dejar toda la aplicación en blanco */
export default class ErrorBoundary extends Component {
    state = { error: null };

    static getDerivedStateFromError(error) {
        return { error };
    }

    componentDidUpdate(prev) {
        if (prev.ruta !== this.props.ruta && this.state.error) this.setState({ error: null });
    }

    render() {
        if (!this.state.error) return this.props.children;
        return (
            <div className="flex flex-col items-center gap-3 py-20 text-center" role="alert">
                <AlertOctagon className="size-14 text-peligro" aria-hidden />
                <h1 className="text-2xl font-bold">Ocurrió un error al mostrar esta pantalla</h1>
                <p className="max-w-md text-suave">Intente recargar la página. Si el problema continúa, avise al administrador.</p>
                <code className="max-w-xl rounded-tema bg-superficie-2 p-2 text-xs">{String(this.state.error.message)}</code>
                <button className="btn btn-primario" onClick={() => window.location.reload()}>Recargar</button>
            </div>
        );
    }
}
