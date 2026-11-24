<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seguridad (RBAC): roles, permisos, matriz rol_permiso, menú dinámico y usuarios.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10)->unique();
            $table->string('nombre', 60);
            $table->string('descripcion', 200)->nullable();
            $table->boolean('es_sistema')->default(false); // el rol Administrador no se puede borrar
            $table->timestamps();
        });

        // Un permiso = recurso + acción. Ej: reservas.crear
        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('recurso', 40);
            $table->string('accion', 30);
            $table->string('descripcion', 150);
        });

        // Matriz de acceso
        Schema::create('rol_permiso', function (Blueprint $table) {
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permiso_id')->constrained('permisos')->cascadeOnDelete();
            $table->primary(['rol_id', 'permiso_id']);
        });

        // Menú renderizado desde la BD según los permisos del rol
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('grupo', 40);          // sección del menú
            $table->string('titulo', 60);
            $table->string('ruta', 100);          // ruta del frontend React
            $table->string('icono', 40)->nullable();
            $table->string('permiso_codigo', 60)->nullable(); // null = visible para todos
            $table->integer('orden')->default(0);
            $table->boolean('activo')->default(true);
        });

        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 60);
            $table->string('apellido', 60);
            $table->string('email', 100)->unique();
            $table->string('telefono', 20)->nullable();
            $table->string('password');
            $table->foreignId('rol_id')->constrained('roles');
            $table->string('estado', 10)->default('ACTIVO');
            $table->jsonb('preferencias')->nullable(); // tema, modo, tamaño de fuente, contraste
            $table->timestamp('ultimo_acceso')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('rol_permiso');
        Schema::dropIfExists('permisos');
        Schema::dropIfExists('roles');
    }
};
