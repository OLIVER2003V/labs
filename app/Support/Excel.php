<?php

namespace App\Support;

use ZipArchive;

/**
 * Genera un Excel (.xlsx) CON FORMATO, sin librerías externas (un .xlsx es un ZIP con archivos XML).
 *
 * Cada hoja sale como un reporte en cuadros:
 *   - título (azul de la marca) y subtítulo (filtros / rango / fecha de emisión)
 *   - encabezado azul con letras blancas, bordes en todas las celdas y filas alternadas
 *   - columnas con el ancho de su contenido, encabezado fijo al bajar y filtros de Excel
 *   - números como números (se pueden sumar en Excel) y una fila de TOTAL opcional
 *
 * Uso:  $ruta = Excel::generar('Reporte de reservas', 'Del 01/11 al 30/11', [
 *           ['nombre' => 'Por estado', 'columnas' => ['Estado', 'Cantidad'], 'filas' => [['APROBADA', 12], ...], 'total' => ['TOTAL', 20]],
 *       ]);
 */
class Excel
{
    // Índices de estilo (cellXfs en styles.xml)
    const TITULO = 1, SUBTITULO = 2, ENCABEZADO = 3, TEXTO = 4, NUMERO = 5, TEXTO_ALT = 6, NUMERO_ALT = 7, TOTAL_TXT = 8, TOTAL_NUM = 9, SECCION = 10;

    public static function generar(string $titulo, ?string $subtitulo, array $hojas): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $tipos = $rels = $hojasXml = $nombres = '';
        $usados = [];
        foreach (array_values($hojas) as $i => $hoja) {
            $n = $i + 1;
            $nombre = self::nombreHoja($hoja['nombre'] ?? "Hoja $n", $usados);
            [$xml, $filaEnc, $ultimaCol, $nFilas] = self::hoja($hoja, $hoja['titulo'] ?? $titulo, $subtitulo);
            $zip->addFromString("xl/worksheets/sheet$n.xml", $xml);
            // Nombres que Excel necesita: rango del filtro del encabezado y filas que se repiten al imprimir
            $hojaRef = "'".str_replace("'", "''", $nombre)."'";
            if ($nFilas) {
                $nombres .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$i.'" hidden="1">'.self::e("$hojaRef!\$A\$$filaEnc:\$$ultimaCol\$".($filaEnc + $nFilas)).'</definedName>';
            }
            $nombres .= '<definedName name="_xlnm.Print_Titles" localSheetId="'.$i.'">'.self::e("$hojaRef!\$$filaEnc:\$$filaEnc").'</definedName>';
            $tipos .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $hojasXml .= '<sheet name="'.self::e($nombre).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
        }
        $nEstilos = count($hojas) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$tipos.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$hojasXml.'</sheets><definedNames>'.$nombres.'</definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="rId'.$nEstilos.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', self::estilos());
        $zip->close();

        return $ruta;
    }

    /**
     * Una hoja: título, subtítulo, encabezado, filas con bordes, total opcional.
     * @return array{0: string, 1: int, 2: string, 3: int} [xml, fila del encabezado, última columna, cantidad de filas]
     */
    private static function hoja(array $hoja, string $titulo, ?string $subtitulo): array
    {
        $columnas = array_values($hoja['columnas']);
        $nCols = max(1, count($columnas));
        $ultimaCol = self::col($nCols - 1);
        $filas = $hoja['filas'] ?? [];
        $xml = '';
        $r = 1;

        $xml .= self::fila($r++, [$titulo], self::TITULO, 24);
        $sub = trim(($subtitulo ?? '').' · Emitido el '.now()->format('d/m/Y H:i'), ' ·');
        $xml .= self::fila($r++, [$sub], self::SUBTITULO);
        if (! empty($hoja['titulo_tabla'])) {
            $xml .= self::fila($r++, [$hoja['titulo_tabla']], self::SECCION);
        }
        $r++; // fila en blanco
        $filaEnc = $r;
        $xml .= self::fila($r++, $columnas, self::ENCABEZADO, 20);

        foreach ($filas as $i => $f) {
            $alt = $i % 2 === 1;
            $xml .= self::fila($r++, array_slice(array_values((array) $f), 0, $nCols), null, null, $alt);
        }
        if (! $filas) {
            $xml .= self::fila($r++, ['Sin datos para el filtro elegido.'], self::TEXTO);
        }
        if (! empty($hoja['total'])) {
            $xml .= self::fila($r++, array_slice(array_values($hoja['total']), 0, $nCols), null, null, false, true);
        }

        // Ancho de cada columna según su contenido (entre 8 y 60 caracteres)
        $anchos = array_map(fn ($c) => mb_strlen((string) $c) + 2, $columnas);
        foreach (array_slice($filas, 0, 500) as $f) {
            foreach (array_slice(array_values((array) $f), 0, $nCols) as $j => $v) {
                $anchos[$j] = max($anchos[$j] ?? 8, min(60, mb_strlen((string) $v) + 2));
            }
        }
        $cols = '';
        foreach ($anchos as $j => $a) {
            $cols .= '<col min="'.($j + 1).'" max="'.($j + 1).'" width="'.max(8, min(60, $a)).'" customWidth="1"/>';
        }

        $merges = '<mergeCells count="2"><mergeCell ref="A1:'.$ultimaCol.'1"/><mergeCell ref="A2:'.$ultimaCol.'2"/></mergeCells>';
        $filtro = $filas ? '<autoFilter ref="A'.$filaEnc.':'.$ultimaCol.($filaEnc + count($filas)).'"/>' : '';
        $vista = '<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="'.$filaEnc.'" topLeftCell="A'.($filaEnc + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        // Al imprimir: horizontal, ajustado al ancho de la hoja, encabezado repetido en cada página
        $impresion = '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="'.($nCols > 5 ? 'landscape' : 'portrait').'" fitToWidth="1" fitToHeight="0"/>';

        return ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'.$vista.'<cols>'.$cols.'</cols><sheetData>'.$xml.'</sheetData>'.$filtro.$merges.$impresion.'</worksheet>', $filaEnc, $ultimaCol, count($filas)];
    }

    /** Una fila. Si $estilo es null, cada celda toma estilo de texto o número (alternado / total) según su valor. */
    private static function fila(int $r, array $valores, ?int $estilo, ?int $alto = null, bool $alt = false, bool $total = false): string
    {
        $celdas = '';
        foreach (array_values($valores) as $j => $v) {
            $ref = self::col($j).$r;
            $numero = is_int($v) || is_float($v);
            $s = $estilo ?? match (true) {
                $total => $numero ? self::TOTAL_NUM : self::TOTAL_TXT,
                $alt => $numero ? self::NUMERO_ALT : self::TEXTO_ALT,
                default => $numero ? self::NUMERO : self::TEXTO,
            };
            $celdas .= $numero
                ? '<c r="'.$ref.'" s="'.$s.'"><v>'.$v.'</v></c>'
                : '<c r="'.$ref.'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.self::e((string) ($v ?? '')).'</t></is></c>';
        }

        return '<row r="'.$r.'"'.($alto ? ' ht="'.$alto.'" customHeight="1"' : '').'>'.$celdas.'</row>';
    }

    /** Fuentes, rellenos y bordes. Colores de la marca: azul marino 0F2A4A, celeste 7FC3E0 */
    private static function estilos(): string
    {
        $fuentes = '<fonts count="6">'
            .'<font><sz val="10"/><name val="Calibri"/></font>'                                        // 0 normal
            .'<font><b/><sz val="15"/><color rgb="FF0F2A4A"/><name val="Calibri"/></font>'              // 1 título
            .'<font><i/><sz val="9"/><color rgb="FF5B6B7F"/><name val="Calibri"/></font>'               // 2 subtítulo
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'              // 3 encabezado
            .'<font><b/><sz val="10"/><name val="Calibri"/></font>'                                     // 4 total
            .'<font><b/><sz val="11"/><color rgb="FFE5432F"/><name val="Calibri"/></font>'              // 5 sección
            .'</fonts>';
        $rellenos = '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF0F2A4A"/></patternFill></fill>'      // 2 azul
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/></patternFill></fill>'      // 3 gris claro (filas alternas)
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFDCEFF8"/></patternFill></fill>'      // 4 celeste (total)
            .'</fills>';
        $borde = '<left style="thin"><color rgb="FFB8C4D2"/></left><right style="thin"><color rgb="FFB8C4D2"/></right><top style="thin"><color rgb="FFB8C4D2"/></top><bottom style="thin"><color rgb="FFB8C4D2"/></bottom><diagonal/>';
        $bordes = '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>'.$borde.'</border></borders>';
        $xf = fn ($font, $fill, $border, $alin, $num = 0) => '<xf numFmtId="'.$num.'" fontId="'.$font.'" fillId="'.$fill.'" borderId="'.$border.'" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"'.($num ? ' applyNumberFormat="1"' : '').'>'.$alin.'</xf>';
        $izq = '<alignment vertical="center" wrapText="1"/>';
        $der = '<alignment horizontal="right" vertical="center"/>';
        $centro = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
        $celdas = '<cellXfs count="11">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .$xf(1, 0, 0, '<alignment vertical="center"/>')    // 1 título
            .$xf(2, 0, 0, '<alignment vertical="center"/>')    // 2 subtítulo
            .$xf(3, 2, 1, $centro)                              // 3 encabezado
            .$xf(0, 0, 1, $izq)                                 // 4 texto
            .$xf(0, 0, 1, $der)                                 // 5 número
            .$xf(0, 3, 1, $izq)                                 // 6 texto alterno
            .$xf(0, 3, 1, $der)                                 // 7 número alterno
            .$xf(4, 4, 1, $izq)                                 // 8 total texto
            .$xf(4, 4, 1, $der)                                 // 9 total número
            .$xf(5, 0, 0, '<alignment vertical="center"/>')    // 10 sección
            .'</cellXfs>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$fuentes.$rellenos.$bordes.'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.$celdas.'</styleSheet>';
    }

    /** 0 → A, 25 → Z, 26 → AA */
    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    /** Excel exige nombres de hoja de máx. 31 caracteres, sin : \ / ? * [ ] y sin repetir */
    private static function nombreHoja(string $nombre, array &$usados): string
    {
        $base = mb_substr(trim(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $nombre)) ?: 'Hoja', 0, 28);
        $n = $base;
        for ($i = 2; in_array(mb_strtolower($n), $usados, true); $i++) {
            $n = "$base $i";
        }
        $usados[] = mb_strtolower($n);

        return $n;
    }

    private static function e(string $v): string
    {
        // Quita caracteres de control que rompen el XML
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
