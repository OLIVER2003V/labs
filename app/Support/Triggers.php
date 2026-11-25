<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Funciones y TRIGGERS de PostgreSQL del sistema.
 * Se instalan desde la migración y se pueden REINSTALAR en cualquier momento con:
 *     php artisan sistema:reparar
 * (útil si alguien borra un trigger o una función directamente en la base de datos).
 * Todo es idempotente: CREATE OR REPLACE / DROP IF EXISTS.
 */
class Triggers
{
    /** Tablas con auditoría automática (INSERT / UPDATE / DELETE) */
    const AUDITADAS = [
        'usuarios', 'roles', 'rol_permiso', 'laboratorios', 'semestres', 'materias', 'horarios',
        'reservas', 'equipos', 'incidencias', 'objetos_encontrados', 'turnos', 'asistencias', 'permutas',
        'conceptos_pago', 'planes_pago', 'cuotas', 'transacciones', 'metodos_pago',
    ];

    /** Triggers que deben existir: nombre => tabla */
    public static function esperados(): array
    {
        $lista = [];
        foreach (self::AUDITADAS as $t) {
            $lista["tg_auditoria_$t"] = $t;
        }

        return $lista + [
            'tg_reserva_sin_choque' => 'reservas',
            'tg_incidencia_equipo' => 'incidencias',
            'tg_cuota_cierra_plan' => 'cuotas',
        ];
    }

    const FUNCIONES = ['registrar_visita', 'fn_auditoria', 'fn_reserva_sin_choque', 'fn_incidencia_equipo', 'fn_cuota_cierra_plan'];

    public static function instalar(): void
    {
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
$$ LANGUAGE plpgsql SET search_path FROM CURRENT;
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
$$ LANGUAGE plpgsql SET search_path FROM CURRENT;
SQL);

        foreach (self::AUDITADAS as $tabla) {
            if (! DB::selectOne('SELECT to_regclass(?) AS t', [$tabla])->t) {
                continue; // la tabla la crea una migración posterior, que vuelve a llamar a instalar()
            }
            DB::unprepared("DROP TRIGGER IF EXISTS tg_auditoria_{$tabla} ON {$tabla};
                CREATE TRIGGER tg_auditoria_{$tabla} AFTER INSERT OR UPDATE OR DELETE ON {$tabla}
                FOR EACH ROW EXECUTE FUNCTION fn_auditoria();");
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
$$ LANGUAGE plpgsql SET search_path FROM CURRENT;
DROP TRIGGER IF EXISTS tg_reserva_sin_choque ON reservas;
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
$$ LANGUAGE plpgsql SET search_path FROM CURRENT;
DROP TRIGGER IF EXISTS tg_incidencia_equipo ON incidencias;
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
$$ LANGUAGE plpgsql SET search_path FROM CURRENT;
DROP TRIGGER IF EXISTS tg_cuota_cierra_plan ON cuotas;
CREATE TRIGGER tg_cuota_cierra_plan AFTER UPDATE OF estado ON cuotas
    FOR EACH ROW EXECUTE FUNCTION fn_cuota_cierra_plan();
SQL);
    }

    public static function desinstalar(): void
    {
        foreach (self::esperados() as $trigger => $tabla) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON {$tabla}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS fn_cuota_cierra_plan(); DROP FUNCTION IF EXISTS fn_incidencia_equipo();
            DROP FUNCTION IF EXISTS fn_reserva_sin_choque(); DROP FUNCTION IF EXISTS fn_auditoria();
            DROP FUNCTION IF EXISTS registrar_visita(VARCHAR);');
    }
}
