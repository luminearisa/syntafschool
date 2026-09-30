<?php

namespace App\Modules\Supervisi\Application\Support;

use RuntimeException;
use ZipArchive;

/**
 * Penulis berkas XLSX (OpenXML) tanpa dependensi tambahan.
 *
 * Instalasi ini tidak memuat pustaka spreadsheet, sedangkan laporan supervisi
 * harus dapat diunduh dalam format yang lazim dibuka pimpinan/dinas. Berkas
 * `.xlsx` pada dasarnya arsip ZIP berisi beberapa dokumen XML; kelas ini
 * menulis bentuk paling sederhana dari format tersebut: satu lembar kerja,
 * sel teks (inline string) dan angka.
 *
 * Catatan: seluruh teks ditulis sebagai *inline string* supaya tidak perlu
 * tabel string bersama — berkas sedikit lebih besar tetapi tetap sah dan
 * terbuka di Excel, LibreOffice, maupun Google Sheets.
 */
class XlsxWriter
{
    /** @var list<string> */
    protected array $headers = [];

    /** @var list<list<string|int|float|null>> */
    protected array $rows = [];

    public function __construct(protected string $sheetName = 'Laporan')
    {
    }

    /** @param list<string> $headers */
    public function setHeaders(array $headers): static
    {
        $this->headers = array_values(array_map(fn ($value) => (string) $value, $headers));

        return $this;
    }

    /** @param list<list<string|int|float|null>> $rows */
    public function setRows(array $rows): static
    {
        $this->rows = array_values($rows);

        return $this;
    }

    public function toString(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sup-xlsx-');

        if ($path === false) {
            throw new RuntimeException('Tidak dapat menyiapkan berkas sementara untuk XLSX.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('Tidak dapat membuka arsip XLSX untuk ditulis.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelations());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelations());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();

        $binary = file_get_contents($path);
        @unlink($path);

        if ($binary === false) {
            throw new RuntimeException('Berkas XLSX gagal dibaca kembali.');
        }

        return $binary;
    }

    protected function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    protected function rootRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    protected function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($this->sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    protected function workbookRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }

    protected function sheet(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $rowNumber = 1;

        if ($this->headers !== []) {
            $xml .= $this->row($this->headers, $rowNumber);
            $rowNumber++;
        }

        foreach ($this->rows as $row) {
            $xml .= $this->row($row, $rowNumber);
            $rowNumber++;
        }

        return $xml.'</sheetData></worksheet>';
    }

    /** @param list<string|int|float|null> $cells */
    protected function row(array $cells, int $rowNumber): string
    {
        $xml = '<row r="'.$rowNumber.'">';
        $column = 0;

        foreach ($cells as $value) {
            $reference = $this->columnName($column).$rowNumber;

            if (is_int($value) || is_float($value)) {
                $xml .= '<c r="'.$reference.'"><v>'.rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.').'</v></c>';
            } elseif ($value === null || $value === '') {
                $xml .= '<c r="'.$reference.'"/>';
            } else {
                $xml .= '<c r="'.$reference.'" t="inlineStr">'
                    .'<is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
            }

            $column++;
        }

        return $xml.'</row>';
    }

    /** Nama kolom gaya Excel dari indeks 0-based (0 → A, 26 → AA). */
    protected function columnName(int $index): string
    {
        $name = '';
        $index++;

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
