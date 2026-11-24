/**
 * Identidad visual "Laboratorios 4to Piso" (FICCT - UAGRM).
 * Isotipo: un "4" que se convierte en el edificio del 4to piso, con nodos de circuito (tecnología).
 * Colores de marca: azul marino #0F2A4A · rojo #E5432F · celeste #7FC3E0
 */

/** Solo el símbolo. variante="color" (sobre fondo claro) | "claro" (sobre fondo oscuro) */
export function Isotipo({ className = 'size-10', variante = 'color', titulo = 'Laboratorios 4to Piso' }) {
    const trazo = variante === 'claro' ? '#FFFFFF' : 'var(--marca-azul, #0F2A4A)';
    return (
        <svg viewBox="0 0 110 104" className={className} role="img" aria-label={titulo} xmlns="http://www.w3.org/2000/svg">
            {/* franja celeste del edificio (detrás) */}
            <rect x="64" y="38" width="6" height="60" fill="#7FC3E0" />
            <g fill="none" stroke={trazo} strokeWidth="4.2" strokeLinejoin="miter" strokeLinecap="square">
                {/* el "4" */}
                <path d="M6 64 L41 16 L50 11 L50 98" />
                <path d="M6 64 L6 71 L41 71 L41 98" />
                <path d="M17 64 L41 31 L41 64 Z" />
                {/* edificio */}
                <path d="M56 9 L56 98" />
                <path d="M56 9 L86 25 L86 98" />
                <path d="M61 24 L81 35 L81 44 L61 33 Z" />
                <path d="M61 42 L81 53 L81 62 L61 51 Z" />
                <path d="M61 60 L81 71 L81 80 L61 69 Z" />
                <path d="M30 98 L98 98" />
            </g>
            {/* nodos de circuito */}
            <g strokeWidth="3" fill="none">
                <path d="M86 51 L93 51 L97 42" stroke="#E5432F" />
                <path d="M86 61 L101 61" stroke="#7FC3E0" />
                <path d="M86 72 L93 72 L99 78" stroke="#E5432F" />
            </g>
            <circle cx="98" cy="38" r="4.2" fill="#E5432F" />
            <circle cx="104" cy="61" r="4.2" fill="#7FC3E0" />
            <circle cx="101" cy="81" r="4.2" fill="#7FC3E0" />
        </svg>
    );
}

/** Logotipo completo (símbolo + texto), como el logo oficial */
export function LogoCompleto({ variante = 'color', className = '' }) {
    const claro = variante === 'claro';
    return (
        <div className={`flex flex-col items-center text-center ${className}`}>
            <Isotipo variante={variante} className="h-36 w-auto" />
            <p className={`mt-4 font-[Montserrat] text-sm tracking-[0.25em] uppercase ${claro ? 'text-white/85' : 'text-[#0F2A4A]'}`}>Sistema de gestión</p>
            <p className={`font-titulo text-5xl leading-none tracking-wide uppercase ${claro ? 'text-white' : 'text-[#0F2A4A]'}`}>
                Laboratorios <span className="text-[#E5432F]">4<sup className="text-[0.5em]">TO</sup></span> Piso
            </p>
            <p className={`mt-2 font-[Montserrat] font-bold tracking-wider ${claro ? 'text-white/85' : 'text-[#0F2A4A]'}`}>FICCT - UAGRM</p>
        </div>
    );
}

/** Marca horizontal compacta para la barra lateral */
export function LogoHorizontal({ className = '' }) {
    return (
        <span className={`flex items-center gap-2.5 ${className}`}>
            <span className="grid size-11 shrink-0 place-items-center rounded-tema bg-white p-1 shadow-sm ring-1 ring-borde">
                <Isotipo className="size-full" />
            </span>
            <span className="leading-none">
                <span className="block font-titulo text-[1.35rem] tracking-wide text-texto uppercase">
                    Laboratorios <span className="text-[#E5432F]">4<sup className="text-[0.55em]">TO</sup></span>
                </span>
                <span className="mt-0.5 block font-[Montserrat] text-[0.65rem] font-semibold tracking-[0.18em] text-suave uppercase">Piso · FICCT · UAGRM</span>
            </span>
        </span>
    );
}
