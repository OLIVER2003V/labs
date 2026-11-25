<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Menu;
use App\Models\Mensaje;
use App\Models\Permuta;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Inicio/cierre de sesión con tokens (Laravel Sanctum), perfil y preferencias de tema.
 * Cada intento de login (exitoso o fallido) queda en la bitácora con la IP.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:100'],
            'password' => ['required', 'string', 'max:100'],
        ]);

        $usuario = User::where('email', strtolower($datos['email']))->first();

        $motivo = match (true) {
            ! $usuario => 'Usuario no registrado',
            ! Hash::check($datos['password'], $usuario->password) => 'Contraseña incorrecta',
            $usuario->estado === 'PENDIENTE' => 'Cuenta pendiente de aprobación',
            $usuario->estado !== 'ACTIVO' => 'Cuenta inactiva',
            default => null,
        };

        $this->registrar($request, $motivo ? 'LOGIN_FALLIDO' : 'LOGIN_OK', $usuario, $datos['email'], $motivo);

        if ($motivo === 'Cuenta pendiente de aprobación') {
            return response()->json(['message' => 'Su solicitud de cuenta aún no fue aprobada. El Encargado de Laboratorios la revisará pronto.'], 403);
        }
        if ($motivo === 'Cuenta inactiva') {
            return response()->json(['message' => 'Su cuenta está inactiva. Contacte al administrador.'], 403);
        }
        if ($motivo) {
            // Mensaje genérico: no revelamos si el correo existe
            return response()->json(['message' => 'Correo o contraseña incorrectos.'], 422);
        }

        $usuario->forceFill(['ultimo_acceso' => now()])->save();
        $token = $usuario->createToken('spa', ['*'], now()->addHours(8))->plainTextToken;

        return response()->json(['token' => $token] + $this->perfilCompleto($usuario));
    }

    /** Carreras de la FICCT para el formulario de registro */
    const CARRERAS = ['Ingeniería Informática', 'Ingeniería de Sistemas', 'Ingeniería en Redes y Telecomunicaciones', 'Ingeniería en Robótica'];

    /**
     * Registro público de estudiantes de la FICCT (ej: auxiliares de cátedra).
     * La cuenta queda PENDIENTE hasta que el Encargado la apruebe (no puede iniciar sesión antes).
     */
    public function registro(Request $request): JsonResponse
    {
        $d = $request->validate([
            'codigo' => ['required', 'regex:/^\d{6,12}$/', Rule::unique('usuarios', 'codigo')],
            'nombre' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'email' => ['required', 'email', 'max:100', Rule::unique('usuarios', 'email')],
            'telefono' => ['required', 'regex:/^[67]\d{7}$/'],
            'carrera' => ['required', Rule::in(self::CARRERAS)],
            'detalle_registro' => ['required', 'string', 'min:10', 'max:300'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'codigo.regex' => 'El registro universitario debe tener solo números (entre 6 y 12 dígitos).',
            'codigo.unique' => 'Ese registro universitario ya tiene una cuenta o una solicitud pendiente.',
            'email.unique' => 'Ese correo ya tiene una cuenta o una solicitud pendiente.',
            'telefono.regex' => 'El teléfono debe tener 8 dígitos y empezar con 6 o 7.',
            'detalle_registro.min' => 'Cuéntenos en al menos 10 caracteres para qué necesita los laboratorios.',
        ]);

        $usuario = User::create([
            'codigo' => $d['codigo'], 'nombre' => $d['nombre'], 'apellido' => $d['apellido'],
            'email' => strtolower($d['email']), 'telefono' => $d['telefono'], 'carrera' => $d['carrera'],
            'detalle_registro' => $d['detalle_registro'], 'password' => $d['password'],
            'rol_id' => Rol::where('codigo', 'EST')->value('id'), 'estado' => 'PENDIENTE',
        ]);
        $this->registrar($request, 'REGISTRO', $usuario, $usuario->email, 'Solicitud de cuenta de estudiante');

        return response()->json([
            'message' => 'Solicitud enviada. Podrá iniciar sesión cuando el Encargado de Laboratorios apruebe su cuenta.',
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $this->registrar($request, 'LOGOUT', $usuario, $usuario->email, null);
        $usuario->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /** Usuario autenticado + permisos + menú dinámico */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->perfilCompleto($request->user()));
    }

    public function actualizarPerfil(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'telefono' => ['nullable', 'regex:/^[67]\d{7}$/'],
            'email' => ['required', 'email', 'max:100', Rule::unique('usuarios', 'email')->ignore($usuario->id)],
        ], ['telefono.regex' => 'El teléfono debe tener 8 dígitos y empezar con 6 o 7.']);
        $datos['email'] = strtolower($datos['email']);
        $usuario->update($datos);

        return response()->json(['message' => 'Perfil actualizado.'] + $this->perfilCompleto($usuario));
    }

    public function cambiarPassword(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', 'different:password_actual', Password::min(8)->letters()->numbers()],
        ]);
        $request->user()->update(['password' => $datos['password']]);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    /** Guarda tema, modo día/noche, tamaño de fuente y contraste del usuario */
    public function guardarPreferencias(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tema' => ['required', Rule::in(['ninos', 'jovenes', 'adultos'])],
            'modo' => ['required', Rule::in(['claro', 'oscuro', 'auto'])],
            'fuente' => ['required', 'integer', 'between:80,160'],
            'contraste' => ['required', 'boolean'],
        ]);
        $request->user()->update(['preferencias' => $datos]);

        return response()->json(['message' => 'Preferencias guardadas.', 'preferencias' => $datos]);
    }

    private function perfilCompleto(User $usuario): array
    {
        $usuario->load('rol');
        $permisos = $usuario->permisos();

        $menu = Menu::where('activo', true)
            ->where(fn ($q) => $q->whereNull('permiso_codigo')->orWhereIn('permiso_codigo', $permisos))
            ->orderBy('orden')
            ->get(['grupo', 'titulo', 'ruta', 'icono', 'permiso_codigo']);

        return ['usuario' => $usuario, 'permisos' => $permisos, 'menu' => $menu, 'avisos' => self::avisos($usuario, $permisos)];
    }

    /**
     * Números que aparecen junto a una opción del menú ("cosas que esperan algo de mí").
     * También los devuelve GET /api/notificaciones, así se actualizan solos cada 60 s.
     */
    public static function avisos(User $usuario, ?array $permisos = null): array
    {
        $permisos ??= $usuario->permisos();
        $avisos = [];
        if (in_array('usuarios.editar', $permisos, true)) {      // solicitudes de cuenta por aprobar
            $avisos['/usuarios'] = User::where('estado', 'PENDIENTE')->count();
        }
        if (in_array('mensajes.usar', $permisos, true)) {        // mensajes sin leer
            $avisos['/mensajes'] = Mensaje::where('destinatario_id', $usuario->id)->whereNull('leido_en')->count();
        }
        if (in_array('permutas.ver', $permisos, true)) {         // permutas que esperan mi respuesta o mi aprobación
            $avisos['/permutas'] = Permuta::whereDate('fecha', '>=', now()->toDateString())
                ->where(fn ($q) => $q->where(fn ($x) => $x->where('reemplazante_id', $usuario->id)->where('estado', 'PENDIENTE'))
                    ->when(in_array('permutas.aprobar', $permisos, true), fn ($x) => $x->orWhere('estado', 'ACEPTADA')))
                ->count();
        }

        return $avisos;
    }

    private function registrar(Request $request, string $evento, ?User $usuario, string $email, ?string $detalle): void
    {
        Bitacora::create([
            'usuario_id' => $usuario?->id,
            'email' => substr($email, 0, 100),
            'evento' => $evento,
            'metodo' => $request->method(),
            'recurso' => 'auth',
            'ruta' => substr($request->path(), 0, 200),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'codigo_http' => $evento === 'LOGIN_FALLIDO' ? 422 : 200,
            'detalle' => $detalle,
        ]);
    }
}
