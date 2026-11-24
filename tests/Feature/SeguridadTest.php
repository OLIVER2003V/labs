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
        $this->como('admin@ficct.edu.bo');
        $resto = DB::table('rol_permiso')->where('rol_id', $aux->id)->where('permiso_id', '<>', $permiso)->pluck('permiso_id');
        $this->putJson("/api/roles/{$aux->id}/permisos", ['permisos' => $resto])->assertOk();

        $this->como('auxiliar@ficct.edu.bo');
        $this->getJson('/api/objetos')->assertStatus(403);
    }

    public function test_encargado_no_puede_crear_administradores_ni_modificar_al_admin(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $adm = Rol::where('codigo', 'ADM')->value('id');

        $this->postJson('/api/usuarios', [
            'codigo' => 'HACK-1', 'nombre' => 'Intruso', 'apellido' => 'Prueba', 'email' => 'intruso@x.com',
            'rol_id' => $adm, 'estado' => 'ACTIVO', 'password' => 'Clave12345',
        ])->assertStatus(403);

        $admin = User::where('email', 'admin@ficct.edu.bo')->first();
        $this->putJson("/api/usuarios/{$admin->id}", [
            'codigo' => 'ADM-001', 'nombre' => 'Admin', 'apellido' => 'Sistema', 'email' => 'admin@ficct.edu.bo',
            'rol_id' => $adm, 'estado' => 'INACTIVO',
        ])->assertStatus(403);
        $this->deleteJson("/api/usuarios/{$admin->id}")->assertStatus(403);
        $this->assertSame('ACTIVO', $admin->fresh()->estado);
    }

    public function test_listas_con_datos_personales_requieren_permiso(): void
    {
        $this->como('auxiliar@ficct.edu.bo');
        $this->getJson('/api/opciones/usuarios')->assertStatus(403);
        $this->getJson('/api/opciones/laboratorios')->assertOk();
    }

    public function test_validacion_del_servidor_en_espanol(): void
    {
        $this->como('admin@ficct.edu.bo');
        $r = $this->postJson('/api/usuarios', ['email' => 'docente@ficct.edu.bo', 'telefono' => '123', 'password' => 'corta']);

        $r->assertStatus(422)->assertJsonValidationErrors(['codigo', 'nombre', 'email', 'telefono', 'password', 'rol_id']);
        $this->assertSame('El campo código es obligatorio.', $r->json('errors.codigo.0'));
        $this->assertSame('El valor de correo electrónico ya está registrado.', $r->json('errors.email.0'));
    }

    public function test_usuario_eliminado_se_puede_restaurar(): void
    {
        $this->como('admin@ficct.edu.bo');
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
