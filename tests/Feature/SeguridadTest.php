<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Autenticación, matriz de acceso (RBAC), bitácora y validación en español */
class SeguridadTest extends SistemaTestCase
{
    public function test_login_correcto_devuelve_token_permisos_y_menu(): void
    {
        $r = $this->postJson('/api/login', ['email' => 'docente@ficct.edu.bo', 'password' => 'Ficct2026']);

        $r->assertOk()->assertJsonStructure(['token', 'usuario', 'permisos', 'menu']);
        $this->assertDatabaseHas('bitacora', ['email' => 'docente@ficct.edu.bo', 'evento' => 'LOGIN_OK']);
    }

    public function test_login_fallido_queda_en_bitacora_con_mensaje_generico(): void
    {
        $r = $this->postJson('/api/login', ['email' => 'docente@ficct.edu.bo', 'password' => 'incorrecta']);

        $r->assertStatus(422)->assertJson(['message' => 'Correo o contraseña incorrectos.']);
        $this->assertDatabaseHas('bitacora', ['evento' => 'LOGIN_FALLIDO', 'detalle' => 'Contraseña incorrecta']);
    }

    public function test_usuario_inactivo_no_puede_ingresar(): void
    {
        $this->postJson('/api/login', ['email' => 'eclaure@ficct.edu.bo', 'password' => 'Ficct2026'])->assertStatus(403);
    }

    public function test_sin_token_la_api_responde_401_en_espanol(): void
    {
        $this->getJson('/api/reservas')->assertStatus(401)->assertJsonFragment(['message' => 'Sesión no válida o expirada. Inicie sesión nuevamente.']);
    }

    public function test_el_menu_solo_trae_opciones_permitidas_por_el_rol(): void
    {
        $this->como('docente@ficct.edu.bo');
        $rutas = collect($this->getJson('/api/me')->json('menu'))->pluck('ruta');

        $this->assertContains('/reservas', $rutas);
        $this->assertNotContains('/usuarios', $rutas);
        $this->assertNotContains('/roles', $rutas);
    }

    public function test_ruta_sin_permiso_responde_403_y_se_registra_como_denegado(): void
    {
        $this->como('docente@ficct.edu.bo');

        $this->getJson('/api/usuarios')->assertStatus(403);
        $this->assertDatabaseHas('bitacora', ['email' => 'docente@ficct.edu.bo', 'evento' => 'DENEGADO', 'recurso' => 'usuarios']);
    }

    public function test_quitar_un_permiso_en_la_matriz_bloquea_la_ruta(): void
    {
        $aux = Rol::where('codigo', 'AUX')->first();
        $permiso = DB::table('permisos')->where('codigo', 'objetos.ver')->value('id');
        $this->como('encargado@ficct.edu.bo');
        $resto = DB::table('rol_permiso')->where('rol_id', $aux->id)->where('permiso_id', '<>', $permiso)->pluck('permiso_id');
        $this->putJson("/api/roles/{$aux->id}/permisos", ['permisos' => $resto])->assertOk();

        $this->como('auxiliar@ficct.edu.bo');
        $this->getJson('/api/objetos')->assertStatus(403);
    }

    public function test_el_encargado_es_el_administrador_con_rol_protegido_y_no_puede_dejarse_sin_acceso(): void
    {
        $enc = Rol::where('codigo', 'ENC')->first();
        $this->assertTrue((bool) $enc->es_sistema);
        $this->assertFalse(Rol::where('codigo', 'ADM')->exists());               // no hay rol Administrador aparte
        // todos los permisos, menos entregar objetos y asignar turnos (eso no lo hace el Encargado)
        $this->assertSame(\App\Support\Encargado::permisosEsperados(), DB::table('rol_permiso')->where('rol_id', $enc->id)->count());
        $this->assertFalse(User::where('email', 'encargado@ficct.edu.bo')->first()->tienePermiso('objetos.entregar'));

        $yo = $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/roles/{$enc->id}/permisos", ['permisos' => []])->assertStatus(422); // nadie le recorta permisos
        $this->deleteJson("/api/roles/{$enc->id}")->assertStatus(422);
        $this->deleteJson("/api/usuarios/{$yo->id}")->assertStatus(422)->assertJson(['message' => 'No puede eliminar su propia cuenta.']);
        $this->putJson("/api/usuarios/{$yo->id}", ['codigo' => $yo->codigo, 'nombre' => $yo->nombre, 'apellido' => $yo->apellido,
            'email' => $yo->email, 'rol_id' => $enc->id, 'estado' => 'INACTIVO'])->assertStatus(422);
        $this->assertSame('ACTIVO', $yo->fresh()->estado);

        // Otros roles no administran cuentas
        $this->como('director@ficct.edu.bo');
        $this->getJson('/api/usuarios')->assertForbidden();
    }

    public function test_listas_con_datos_personales_requieren_permiso(): void
    {
        $this->como('auxiliar@ficct.edu.bo');
        $this->getJson('/api/opciones/usuarios')->assertStatus(403);
        $this->getJson('/api/opciones/laboratorios')->assertOk();
    }

    public function test_validacion_del_servidor_en_espanol(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $r = $this->postJson('/api/usuarios', ['email' => 'docente@ficct.edu.bo', 'telefono' => '123', 'password' => 'corta']);

        $r->assertStatus(422)->assertJsonValidationErrors(['codigo', 'nombre', 'email', 'telefono', 'password', 'rol_id']);
        $this->assertSame('El campo código es obligatorio.', $r->json('errors.codigo.0'));
        $this->assertSame('El valor de correo electrónico ya está registrado.', $r->json('errors.email.0'));
    }

    public function test_usuario_eliminado_se_puede_restaurar(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $u = User::where('email', 'jsuarez@ficct.edu.bo')->first();

        $this->deleteJson("/api/usuarios/{$u->id}")->assertOk();
        $this->assertSoftDeleted('usuarios', ['id' => $u->id]);
        $this->getJson('/api/usuarios?eliminados=1')->assertJsonFragment(['email' => 'jsuarez@ficct.edu.bo']);
        $this->putJson("/api/usuarios/{$u->id}/restaurar")->assertOk();
        $this->assertNotSoftDeleted('usuarios', ['id' => $u->id]);
    }

    public function test_contador_de_visitas_solo_cuenta_paginas_reales(): void
    {
        $antes = (int) DB::table('visitas_pagina')->where('ruta', '/reservas')->value('contador');
        $this->postJson('/api/visitas', ['ruta' => '/reservas'])->assertOk()->assertJson(['visitas' => $antes + 1]);
        $this->postJson('/api/visitas', ['ruta' => '/inventada'])->assertStatus(422);
    }
}
