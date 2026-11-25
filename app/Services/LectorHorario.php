<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Lee el horario de un archivo .xlsx o .csv (sin librerías externas: un .xlsx es un ZIP con XML).
 * Mismo formato que el comando CARHOR del proyecto 1:
 *
 *   LAB | DIA | INICIO | FIN | SIGLA | MATERIA | GRUPO | DOCENTE (código o correo)
 *
 * Devuelve [ ['fila' => 2, 'valores' => [8 textos]], ... ] sin el encabezado ni filas vacías.
 */
class LectorHorario
{
    const COLUMNAS = ['LAB', 'DIA', 'INICIO', 'FIN', 'SIGLA', 'MATERIA', 'GRUPO', 'DOCENTE'];
    const MAX_FILAS = 1000;

    public static function leer(string $ruta, string $nombreOriginal): array
    {
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        $filas = match ($ext) {
            'xlsx' => self::leerXlsx($ruta),
            'csv', 'txt' => self::leerCsv($ruta),
            default => throw new RuntimeException('El archivo debe ser .xlsx (Excel) o .csv.'),
        };

        $resultado = [];
        $primera = true;
        foreach ($filas as $num => $valores) {
            $valores = array_map(fn ($v) => trim((string) $v), array_pad(array_slice($valores, 0, 8), 8, ''));
            if (implode('', $valores) === '') {
                continue; // fila vacía
            }
            // Encabezado: solo la primera fila con datos, y solo si dice exactamente LAB / LABORATORIO
            $encabezado = $primera && in_array(strtoupper($valores[0]), ['LAB', 'LABORATORIO'], true);
            $primera = false;
            if (! $encabezado) {
                $resultado[] = ['fila' => $num, 'valores' => $valores];
            }
        }
        if (! $resultado) {
            throw new RuntimeException('El archivo no tiene filas de horario. Columnas esperadas: '.implode(', ', self::COLUMNAS).'.');
        }
        if (count($resultado) > self::MAX_FILAS) {
            throw new RuntimeException('El archivo tiene más de '.self::MAX_FILAS.' filas.');
        }

        return $resultado;
    }

    /** @return array<int, array<int, string>> número de fila => valores */
    private static function leerCsv(string $ruta): array
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($ruta)); // quita el BOM de Excel
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252'); // CSV guardado por Excel en Windows
        }
        $lineas = preg_split('/\r\n|\n|\r/', $texto);
        $separador = str_contains($lineas[0] ?? '', ';') ? ';' : ',';
        $filas = [];
        foreach ($lineas as $i => $linea) {
            $filas[$i + 1] = str_getcsv($linea, $separador, '"', '\\');
        }

        return $filas;
    }

    private static function leerXlsx(string $ruta): array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo Excel (¿está dañado o es un .xls antiguo? Guárdelo como .xlsx).');
        }
        // Textos compartidos: Excel guarda cada texto una sola vez y las celdas apuntan a su índice
        $textos = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $textos[] = trim(strip_tags($si->asXML()));
            }
        }
        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($hoja === false) {
            throw new RuntimeException('El Excel no tiene una primera hoja con datos.');
        }

        $filas = [];
        foreach (simplexml_load_string($hoja)->sheetData->row as $row) {
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];                          // ej: "C5"
                preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
                $col = self::columna($m[1]);
                if ($col >= 8) {
                    continue;
                }
                $tipo = (string) $c['t'];
                $valor = match ($tipo) {
                    's' => $textos[(int) $c->v] ?? '',
                    'inlineStr' => trim(strip_tags($c->is->asXML())),
                    default => (string) $c->v,
                };
                // Excel guarda las horas como fracción del día (0.3333 = 08:00)
                if (in_array($col, [2, 3], true) && in_array($tipo, ['', 'n'], true) && is_numeric($valor) && (float) $valor < 1) {
                    $min = (int) round((float) $valor * 1440);
                    $valor = sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
                }
                $filas[(int) $m[2]][$col] = $valor;
            }
        }
        foreach ($filas as &$f) {
            $f = array_replace(array_fill(0, 8, ''), $f);
        }
        ksort($filas);

        return $filas;
    }

    private static function columna(string $letras): int
    {
        $n = 0;
        foreach (str_split($letras) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return $n - 1;
    }

    /** Genera una plantilla .xlsx (encabezado + filas de ejemplo) y devuelve la ruta del archivo temporal */
    public static function plantilla(array $ejemplos): string
    {
        $filas = array_merge([self::COLUMNAS], $ejemplos);
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_XML1);
        $celdas = '';
        foreach ($filas as $i => $fila) {
            $celdas .= '<row r="'.($i + 1).'">';
            foreach (array_values($fila) as $j => $v) {
                $celdas .= '<c r="'.chr(65 + $j).($i + 1).'" t="inlineStr"'.($i === 0 ? ' s="1"' : '').'><is><t>'.$esc($v).'</t></is></c>';
            }
            $celdas .= '</row>';
        }
        $ruta = tempnam(sys_get_temp_dir(), 'plantilla').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Horario" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="4" width="11" customWidth="1"/><col min="5" max="5" width="10" customWidth="1"/><col min="6" max="6" width="34" customWidth="1"/><col min="7" max="7" width="8" customWidth="1"/><col min="8" max="8" width="26" customWidth="1"/></cols><sheetData>'.$celdas.'</sheetData></worksheet>');
        $zip->close();

        return $ruta;
    }
}
