<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\EquipoRed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU6 - Inventario de equipos y software, con su configuración de red (EQUIPO_RED del proyecto 1).
 * La red viaja en el mismo formulario como campos red_* (red_ip, red_mascara…); si red_ip queda vacío, el equipo no tiene red.
 *
 * Reglas: código único · IP única en toda la facultad · máscara válida · gateway en la misma subred que la IP ·
 * un equipo con incidencias abiertas no puede volver a OPERATIVO · un equipo con historial no se borra (se da de BAJA).
 */
class EquipoController extends CrudController
{
    protected string $modelo = Equipo::class;
    protected array $buscarEn = ['codigo', 'activo_fijo', 'descripcion', 'tipo', 'estado', 'laboratorio.codigo', 'red.ip'];
    protected array $con = ['laboratorio', 'red'];
    protected array $filtros = ['laboratorio_id', 'tipo', 'estado'];
    protected string $ordenarPor = 'codigo';
    protected string $direccion = 'asc';

    /** Tipos de equipo que se conectan a la red */
    const CON_RED = ['COMPUTADORA', 'PROYECTOR', 'OTRO'];

    /** Configuración de red ya validada, para guardarla después del equipo (null = sin red) */
    private ?array $red = null;

    protected function reglas(Request $request, ?Model $registro): array
    {
        $ip = ['nullable', 'ipv4'];

        return [
            'codigo' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ,.\/-]{1,19}$/', Rule::unique('equipos', 'codigo')->ignore($registro?->id)],
            'activo_fijo' => ['nullable', 'regex:/^[A-Za-z0-9-]{3,30}$/'],
            'laboratorio_id' => ['required', 'integer', 'exists:laboratorios,id'],
            'tipo' => ['required', Rule::in(['COMPUTADORA', 'PROYECTOR', 'COMPONENTE', 'SOFTWARE', 'OTRO'])],
            'descripcion' => ['required', 'string', 'min:3', 'max:200'],
            'estado' => ['required', Rule::in(['OPERATIVO', 'EN_REPARACION', 'BAJA'])],
            // Red (opcional): si se escribe la IP, el resto de lo básico es obligatorio
            'red_ip' => $ip, // que no se repita se verifica en antesDeGuardar (para decir qué equipo la tiene)
            'red_mascara' => ['required_with:red_ip', 'nullable', 'ipv4'],
            'red_gateway' => ['required_with:red_ip', 'nullable', 'ipv4', 'different:red_ip'],
            'red_dns1' => ['required_with:red_ip', 'nullable', 'ipv4'],
            'red_dns2' => $ip,
            'red_proxy' => ['nullable', 'regex:/^[A-Za-z0-9.-]{3,50}:\d{2,5}$/'],
            'red_grupo' => ['nullable', 'regex:/^[A-Za-z0-9-]{2,20}$/'],
        ];
    }

    /** Nombres de los campos en los mensajes de error ("El campo IP ya está en uso") */
    protected function atributos(): array
    {
        return ['red_ip' => 'IP', 'red_mascara' => 'máscara', 'red_gateway' => 'gateway', 'red_dns1' => 'DNS primario',
            'red_dns2' => 'DNS secundario', 'red_proxy' => 'proxy', 'red_grupo' => 'grupo de red'];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $datos['codigo'] = strtoupper($datos['codigo']);

        // Software y componentes no se conectan a la red: si traían datos de red, se ignoran (y se quita la que tuvieran)
        $this->red = null;
        if (! empty($datos['red_ip']) && in_array($datos['tipo'], self::CON_RED, true)) {
            // Regla 2 del CU6: IP única en la red de la facultad (además del índice único en la base de datos)
            $otro = EquipoRed::with('equipo.laboratorio')->where('ip', $datos['red_ip'])->where('equipo_id', '<>', $registro?->id ?? 0)->first();
            abort_if($otro, 422, "La IP {$datos['red_ip']} ya está asignada a {$otro?->equipo->codigo} ({$otro?->equipo->laboratorio?->codigo}).");
            abort_unless(EquipoRed::mascaraValida($datos['red_mascara']), 422, "La máscara {$datos['red_mascara']} no es válida (ej: 255.255.255.0).");
            abort_unless(EquipoRed::mismaSubred($datos['red_ip'], $datos['red_gateway'], $datos['red_mascara']), 422,
                "El gateway {$datos['red_gateway']} no está en la misma subred que la IP {$datos['red_ip']} (máscara {$datos['red_mascara']}).");
            $this->red = [
                'ip' => $datos['red_ip'], 'mascara' => $datos['red_mascara'], 'gateway' => $datos['red_gateway'], 'dns1' => $datos['red_dns1'],
                'dns2' => ($datos['red_dns2'] ?? null) ?: null, 'proxy' => ($datos['red_proxy'] ?? null) ?: null,
                'grupo_red' => strtoupper((string) ($datos['red_grupo'] ?? '')) ?: null,
            ];
        }

        // No puede volver a OPERATIVO mientras tenga fallas sin resolver
        if ($registro && $datos['estado'] === 'OPERATIVO' && $registro->estado !== 'OPERATIVO') {
            $abiertas = $registro->incidencias()->where('estado', '<>', 'RESUELTA')->count();
            abort_if($abiertas, 422, "El equipo tiene $abiertas incidencia(s) sin resolver. Resuélvalas en Incidencias y volverá a OPERATIVO solo.");
        }

        return $datos;
    }

    /** Guarda, cambia o quita la configuración de red */
    protected function despuesDeGuardar(Model $registro, Request $request, bool $nuevo): void
    {
        if (! $this->red) {
            $registro->red()->delete();

            return;
        }
        EquipoRed::updateOrCreate(['equipo_id' => $registro->id], $this->red);
    }

    protected function antesDeEliminar(Model $registro, Request $request): void
    {
        $n = $registro->incidencias()->count();
        abort_if($n, 422, "El equipo tiene $n incidencia(s) en su historial: no se puede eliminar. Cámbielo a estado BAJA.");
    }
}
