<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de accesos (app), contador de visitas y auditoría por TRIGGERS (base de datos).
 *
 * - bitacora:       la llena Laravel (logins OK/fallidos, IP, recurso accedido, método HTTP).
 * - visitas_pagina: contador persistente por página; se incrementa con la función registrar_visita().
 * - auditoria:      la llenan los triggers. Registra INSERT/UPDATE/DELETE aunque se hagan
 *                   directamente por SQL (psql/pgAdmin), guardando el registro antes y después.
 *                   Sirve para saber qué se borró/modificó y restaurarlo.
 */
return new class extends Migration
{
    /** Tablas auditadas por trigger */
    private array $auditadas = [
        'usuarios', 'roles', 'rol_permiso', 'laboratorios', 'semestres', 'materias', 'horarios',
        'reservas', 'equipos', 'incidencias', 'objetos_encontrados', 'turnos', 'asistencias',
        'conceptos_pago', 'planes_pago', 'cuotas', 'transacciones', 'metodos_pago',
    ];

    public function up(): void
    {
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('email', 100)->nullable();
            $table->string('evento', 20);   // LOGIN_OK, LOGIN_FALLIDO, LOGOUT, ACCESO, DENEGADO
            $table->string('metodo', 7)->nullable();
            $table->string('recurso', 60)->nullable();
            $table->string('ruta', 200)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->smallInteger('codigo_http')->nullable();
            $table->string('detalle', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
            $table->index('evento');
            $table->index('recurso');
        });

        Schema::create('visitas_pagina', function (Blueprint $table) {
            $table->id();
            $table->string('ruta', 150)->unique();
            $table->bigInteger('contador')->default(0);
            $table->timestamp('actualizado_en')->useCurrent();
        });

        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->string('tabla', 60);
            $table->string('operacion', 10);
            $table->string('registro_id', 40)->nullable();
            $table->jsonb('datos_antes')->nullable();
            $table->jsonb('datos_despues')->nullable();
            $table->string('usuario_db', 60);           // usuario de PostgreSQL que hizo el cambio
            $table->bigInteger('usuario_app_id')->nullable(); // usuario del sistema (si vino desde la app)
            $table->timestamp('fecha')->useCurrent();
            $table->index(['tabla', 'fecha']);
        });

        // ---------- Contador de visitas (función atómica) ----------
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION registrar_visita(p_ruta VARCHAR) RETURNS BIGINT AS $$
DECLARE v_total BIGINT;
BEGIN
    INSERT INTO visitas_pagina (ruta, contador, actualizado_en)
    VALUES (p_ruta, 1, CURRENT_TIMESTAMP)
    ON CONFLICT (ruta) DO UPDATE
        SET contador = visitas_pagina.contador + 1, actualizado_en = CURRENT_TIMESTAMP
    RETURNING contador INTO v_total;
    RETURN v_total;
END;
$$ LANGUAGE plpgsql;
SQL);

        // ---------- Auditoría genérica ----------
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_auditoria() RETURNS TRIGGER AS $$
DECLARE
    v_antes JSONB;
    v_despues JSONB;
    v_id TEXT;
BEGIN
    IF TG_OP IN ('UPDATE','DELETE') THEN v_antes := to_jsonb(OLD) - 'password'; END IF;
    IF TG_OP IN ('INSERT','UPDATE') THEN v_despues := to_jsonb(NEW) - 'password'; END IF;
    v_id := COALESCE(v_despues->>'id', v_antes->>'id',
                     (COALESCE(v_despues, v_antes)->>'rol_id') || '-' || (COALESCE(v_despues, v_antes)->>'permiso_id'));
    INSERT INTO auditoria (tabla, operacion, registro_id, datos_antes, datos_despues, usuario_db, usuario_app_id, fecha)
    VALUES (TG_TABLE_NAME, TG_OP, v_id, v_antes, v_despues, current_user,
            NULLIF(current_setting('app.usuario_id', true), '')::BIGINT, CURRENT_TIMESTAMP);
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
SQL);

        foreach ($this->auditadas as $tabla) {
            DB::unprepared("CREATE TRIGGER tg_auditoria_{$tabla} AFTER INSERT OR UPDATE OR DELETE ON {$tabla}
                            FOR EACH ROW EXECUTE FUNCTION fn_auditoria()");
        }

        // ---------- Reservas: impedir choques (aunque se inserte por SQL directo) ----------
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_reserva_sin_choque() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.estado = 'APROBADA' THEN
        IF EXISTS (SELECT 1 FROM reservas r
                   WHERE r.laboratorio_id = NEW.laboratorio_id AND r.fecha = NEW.fecha
                     AND r.estado = 'APROBADA' AND r.id <> NEW.id
                     AND r.hora_inicio < NEW.hora_fin AND NEW.hora_inicio < r.hora_fin) THEN
            RAISE EXCEPTION 'CHOQUE: ya existe una reserva aprobada en ese laboratorio y horario';
        END IF;
        IF EXISTS (SELECT 1 FROM horarios h JOIN semestres s ON s.id = h.semestre_id
                   WHERE s.estado = 'ACTIVO' AND h.laboratorio_id = NEW.laboratorio_id
                     AND NEW.fecha BETWEEN s.fecha_inicio AND s.fecha_fin
                     AND h.dia_semana = EXTRACT(ISODOW FROM NEW.fecha)
                     AND h.hora_inicio < NEW.hora_fin AND NEW.hora_inicio < h.hora_fin) THEN
            RAISE EXCEPTION 'CHOQUE: el laboratorio tiene una clase asignada en ese horario';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER tg_reserva_sin_choque BEFORE INSERT OR UPDATE ON reservas
    FOR EACH ROW EXECUTE FUNCTION fn_reserva_sin_choque();
SQL);

        // ---------- Incidencias: el equipo pasa a EN_REPARACION / vuelve a OPERATIVO ----------
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_incidencia_equipo() RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        UPDATE equipos SET estado = 'EN_REPARACION', updated_at = CURRENT_TIMESTAMP
         WHERE id = NEW.equipo_id AND estado <> 'BAJA';
    ELSIF NEW.estado = 'RESUELTA' AND OLD.estado <> 'RESUELTA' THEN
        IF NOT EXISTS (SELECT 1 FROM incidencias WHERE equipo_id = NEW.equipo_id
                       AND estado <> 'RESUELTA' AND id <> NEW.id) THEN
            UPDATE equipos SET estado = 'OPERATIVO', updated_at = CURRENT_TIMESTAMP
             WHERE id = NEW.equipo_id AND estado = 'EN_REPARACION';
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER tg_incidencia_equipo AFTER INSERT OR UPDATE ON incidencias
    FOR EACH ROW EXECUTE FUNCTION fn_incidencia_equipo();
SQL);

        // ---------- Cuotas: cuando todas están pagadas, el plan queda PAGADO ----------
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_cuota_cierra_plan() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.estado = 'PAGADA' AND NOT EXISTS
       (SELECT 1 FROM cuotas WHERE plan_id = NEW.plan_id AND estado <> 'PAGADA') THEN
        UPDATE planes_pago SET estado = 'PAGADO', updated_at = CURRENT_TIMESTAMP
         WHERE id = NEW.plan_id AND estado = 'VIGENTE';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER tg_cuota_cierra_plan AFTER UPDATE OF estado ON cuotas
    FOR EACH ROW EXECUTE FUNCTION fn_cuota_cierra_plan();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tg_cuota_cierra_plan ON cuotas; DROP FUNCTION IF EXISTS fn_cuota_cierra_plan();');
        DB::unprepared('DROP TRIGGER IF EXISTS tg_incidencia_equipo ON incidencias; DROP FUNCTION IF EXISTS fn_incidencia_equipo();');
        DB::unprepared('DROP TRIGGER IF EXISTS tg_reserva_sin_choque ON reservas; DROP FUNCTION IF EXISTS fn_reserva_sin_choque();');
        foreach ($this->auditadas as $tabla) {
            DB::unprepared("DROP TRIGGER IF EXISTS tg_auditoria_{$tabla} ON {$tabla}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS fn_auditoria(); DROP FUNCTION IF EXISTS registrar_visita(VARCHAR);');
        Schema::dropIfExists('auditoria');
        Schema::dropIfExists('visitas_pagina');
        Schema::dropIfExists('bitacora');
    }
};
