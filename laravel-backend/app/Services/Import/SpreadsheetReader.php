<?php

namespace App\Services\Import;

use Generator;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Streams the rows of a CSV or Excel (.xlsx) file without loading the sheet in memory.
 * Uses only PHP extensions (zip, xmlreader): an .xlsx file is a zip of XML documents.
 * Rows are returned as lists of strings; empty rows are skipped.
 */
class SpreadsheetReader
{
    /** @return Generator<int, list<string>> line number (1 = header) => cells */
    public function rows(string $path, string $extension): Generator
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => $this->csvRows($path),
            'xlsx' => $this->xlsxRows($path),
            default => throw new RuntimeException('Format non pris en charge : utilisez un fichier .csv ou .xlsx (Excel 2007 et plus récent).'),
        };
    }

    /** @return Generator<int, list<string>> */
    private function csvRows(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Fichier illisible.');
        }
        try {
            $first = (string) fgets($handle);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
            rewind($handle);
            $line = 0;
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                $cells = array_map(fn ($cell) => $this->toUtf8((string) $cell), $cells);
                if ($line === 1) {
                    $cells[0] = preg_replace('/^\x{FEFF}/u', '', $cells[0] ?? '');
                }
                if ($this->isEmpty($cells)) {
                    continue;
                }
                yield $line => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return Generator<int, list<string>> */
    private function xlsxRows(string $path): Generator
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Fichier Excel invalide ou endommagé.');
        }
        try {
            $sheetPath = $this->firstSheetPath($zip);
            $sharedStrings = $this->sharedStrings($zip);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw new RuntimeException('La première feuille du classeur est introuvable.');
            }
        } finally {
            $zip->close();
        }

        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $line = (int) $reader->getAttribute('r');
            $row = simplexml_load_string($reader->readOuterXml());
            if ($row === false) {
                continue;
            }
            $cells = [];
            foreach ($row->c as $cell) {
                $column = $this->columnIndex((string) $cell['r']);
                $type = (string) $cell['t'];
                $value = match ($type) {
                    's' => $sharedStrings[(int) $cell->v] ?? '',
                    'inlineStr' => $this->richText($cell->is),
                    'b' => ((string) $cell->v) === '1' ? 'VRAI' : 'FAUX',
                    default => (string) $cell->v,
                };
                $cells[$column] = trim($value);
            }
            if ($cells === []) {
                continue;
            }
            $dense = array_fill(0, max(array_keys($cells)) + 1, '');
            foreach ($cells as $index => $value) {
                $dense[$index] = $value;
            }
            if (!$this->isEmpty($dense)) {
                yield $line => $dense;
            }
        }
        $reader->close();
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $relations === false) {
            throw new RuntimeException('Ce fichier n’est pas un classeur Excel (.xlsx).');
        }
        $book = simplexml_load_string($workbook, options: LIBXML_NONET);
        $rels = simplexml_load_string($relations, options: LIBXML_NONET);
        $sheet = $book->sheets->sheet[0] ?? null;
        if ($sheet === null) {
            throw new RuntimeException('Le classeur ne contient aucune feuille.');
        }
        $relationId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $relationship) {
            if ((string) $relationship['Id'] === $relationId) {
                $target = ltrim((string) $relationship['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $strings = [];
        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $node = simplexml_load_string($reader->readOuterXml());
                $strings[] = $node === false ? '' : $this->richText($node);
            }
        }
        $reader->close();

        return $strings;
    }

    private function richText(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    /** "C12" → 2 */
    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/\d+/', '', $reference);
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function toUtf8(string $value): string
    {
        $value = trim($value);

        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /** @param list<string> $cells */
    private function isEmpty(array $cells): bool
    {
        return implode('', array_map('trim', $cells)) === '';
    }
}
