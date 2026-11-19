<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use RuntimeException;
use ZipArchive;

/**
 * Minimal .xlsx reader and writer (no external library, works on shared hosting).
 *
 * Reader: first worksheet → rows of strings keyed by the header row
 * (headers are lower-cased, spaces → underscores, "*" removed). Excel date
 * serials in date-like columns are converted to Y-m-d.
 *
 * Writer: one or more sheets, bold header row, column widths, and optional
 * drop-down lists (data validation) taken from a hidden "Lists" sheet.
 */
class Xlsx
{
    /* ── Reading ─────────────────────────────────────────────── */

    /**
     * @return array<int, array<string, string>>
     */
    public static function read(string $path, array $dateColumns = []): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This file is not a valid Excel (.xlsx) file.');
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = self::loadXml($xml);
            foreach ($sx->si as $si) {
                $shared[] = self::stringItem($si);
            }
        }

        // First sheet in workbook order
        $sheetPath = 'xl/worksheets/sheet1.xml';
        if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
            $wbx = self::loadXml($wb);
            $relx = self::loadXml($rels);
            $firstRid = null;
            foreach ($wbx->sheets->sheet ?? [] as $s) {
                $firstRid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                break;
            }
            foreach ($relx->Relationship ?? [] as $r) {
                if ((string) $r['Id'] === $firstRid) {
                    $target = ltrim((string) $r['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }

        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheetXml === false) {
            throw new RuntimeException('The Excel file has no worksheet.');
        }

        $sheet = self::loadXml($sheetXml);
        $grid = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $r = (int) $row['r'];
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = self::colIndex(preg_replace('/\d+/', '', $ref));
                $type = (string) $c['t'];
                $value = match ($type) {
                    's'         => $shared[(int) $c->v] ?? '',
                    'inlineStr' => self::stringItem($c->is),
                    'b'         => ((string) $c->v) === '1' ? 'TRUE' : 'FALSE',
                    default     => (string) $c->v,
                };
                $grid[$r][$col] = trim($value);
            }
        }
        if (!$grid) {
            return [];
        }

        ksort($grid);
        $headerRow = array_shift($grid);
        ksort($headerRow);
        $headers = [];
        foreach ($headerRow as $i => $h) {
            $headers[$i] = self::headerKey($h);
        }

        $rows = [];
        foreach ($grid as $cells) {
            $row = [];
            $empty = true;
            foreach ($headers as $i => $key) {
                if ($key === '') continue;
                $v = $cells[$i] ?? '';
                if ($v !== '' && in_array($key, $dateColumns, true)) {
                    $v = self::normalizeDate($v);
                }
                $row[$key] = $v;
                if ($v !== '') $empty = false;
            }
            if (!$empty) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public static function headerKey(string $h): string
    {
        $h = strtolower(trim(str_replace('*', '', $h)));
        $h = preg_replace('/\(.*?\)/', '', $h);
        $h = preg_replace('/[^a-z0-9]+/', '_', trim($h));

        return trim($h, '_');
    }

    /**
     * Excel serial (e.g. 45231) or a typed date → Y-m-d; anything else unchanged.
     * Typed dates are read day first (DD/MM/YYYY), the format the template asks for.
     */
    public static function normalizeDate(string $v): string
    {
        if (preg_match('/^\d{4,6}(\.\d+)?$/', $v)) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $v))->toDateString();
        }
        $v = trim($v);
        foreach (['d/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y', 'j.n.Y', 'Y-m-d', 'Y/m/d'] as $fmt) {
            try {
                $d = Carbon::createFromFormat('!' . $fmt, $v);
                if ($d && $d->format($fmt) === $v) {
                    return $d->toDateString();
                }
            } catch (\Throwable) {
            }
        }

        return $v;
    }

    private static function stringItem($si): string
    {
        if (isset($si->t)) {
            return (string) $si->t;
        }
        $s = '';
        foreach ($si->r ?? [] as $run) {
            $s .= (string) $run->t;
        }

        return $s;
    }

    private static function loadXml(string $xml): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new RuntimeException('The Excel file could not be read.');
        }

        return $x;
    }

    public static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    public static function colLetter(int $index): string
    {
        $s = '';
        $index++;
        while ($index > 0) {
            $m = ($index - 1) % 26;
            $s = chr(65 + $m) . $s;
            $index = intdiv($index - 1, 26);
        }

        return $s;
    }

    /* ── Writing ─────────────────────────────────────────────── */

    /**
     * Build an .xlsx file and return its binary content.
     *
     * @param  array<int, array{name: string, rows: array<int, array<int, string|int|float|null>>, widths?: array<int, int>, hidden?: bool, lists?: array<int, string>, header?: bool}>  $sheets
     *         `lists`: column index → range on another sheet (e.g. "Lists!$A$2:$A$10") used as a drop-down for rows 2–1000.
     */
    public static function build(array $sheets): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $n = count($sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $i => $s) {
            $id = $i + 1;
            $wb .= '<sheet name="' . self::esc($s['name']) . '" sheetId="' . $id . '"' . (!empty($s['hidden']) ? ' state="hidden"' : '') . ' r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
        }
        $wb .= '</sheets></workbook>';
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);

        // Styles: 0 = normal, 1 = bold header with green fill, 2 = text (@) format, 3 = grey italic note
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF0B0C10"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF8BE000"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');

        foreach ($sheets as $i => $s) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', self::sheetXml($s));
        }
        $zip->close();

        $bin = file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }

    private static function sheetXml(array $s): string
    {
        $header = $s['header'] ?? true;
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($header) {
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }
        if (!empty($s['widths'])) {
            $x .= '<cols>';
            foreach ($s['widths'] as $ci => $w) {
                // Every column is text-formatted so phone numbers and IDs keep their digits
                $x .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . (int) $w . '" customWidth="1" style="2"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        foreach ($s['rows'] as $ri => $row) {
            $r = $ri + 1;
            $x .= '<row r="' . $r . '">';
            foreach ($row as $ci => $val) {
                if ($val === null || $val === '') continue;
                $ref = self::colLetter($ci) . $r;
                $style = ($header && $ri === 0) ? 1 : (str_starts_with((string) $val, 'ℹ') ? 3 : 2);
                $x .= '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . self::esc((string) $val) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if (!empty($s['lists'])) {
            $x .= '<dataValidations count="' . count($s['lists']) . '">';
            foreach ($s['lists'] as $ci => $range) {
                $col = self::colLetter($ci);
                $x .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="' . $col . '2:' . $col . '1000">'
                    . '<formula1>' . self::esc($range) . '</formula1></dataValidation>';
            }
            $x .= '</dataValidations>';
        }
        $x .= '</worksheet>';

        return $x;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
