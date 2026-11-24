<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Menu;
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
            $usuario->estado !== 'ACTIVO' => 'Cuenta inactiva',
            default => null,
        };

        $this->registrar($request, $motivo ? 'LOGIN_FALLIDO' : 'LOGIN_OK', $usuario, $datos['email'], $motivo);

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

        return ['usuario' => $usuario, 'permisos' => $permisos, 'menu' => $menu];
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
