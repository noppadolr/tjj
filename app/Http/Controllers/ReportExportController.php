<?php

namespace App\Http\Controllers;

use App\Models\TradeExit;
use App\Support\TradeCostCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class ReportExportController extends Controller
{
    public function __construct(private readonly TradeCostCalculator $costCalculator) {}

    public function __invoke(Request $request, string $format): Response|BinaryFileResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'contract' => ['nullable', 'string', 'max:50'],
        ]);

        $rows = $this->reportRows($filters);
        $filename = 'tfex-journal-report-'.now()->format('Ymd-His').'.'.$format;

        return match ($format) {
            'xlsx' => response()->download($this->createXlsx($rows), $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(),
            'pdf' => response($this->createPdf($rows), 200, [
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Content-Type' => 'application/pdf',
            ]),
        };
    }

    /**
     * @param  array{from?: string|null, to?: string|null, contract?: string|null}  $filters
     * @return Collection<int, array<string, float|int|string>>
     */
    private function reportRows(array $filters): Collection
    {
        return TradeExit::query()
            ->with(['trade', 'tradeCommission'])
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('exit_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('exit_date', '<=', $to))
            ->when($filters['contract'] ?? null, fn ($query, $contract) => $query->whereHas('trade', fn ($trade) => $trade->where('contract', 'like', '%'.$contract.'%')))
            ->latest('exit_date')
            ->latest('id')
            ->get()
            ->map(fn (TradeExit $exit): array => [
                'Exit date' => $exit->exit_date->toDateString(),
                'Contract' => (string) $exit->trade->contract,
                'Contracts' => (int) $exit->exit_contracts,
                'Gross' => (float) $exit->gross_profit,
                'Commission' => $this->commissionFor($exit),
                'VAT' => $this->vatFor($exit),
                'Total Cost' => $this->totalCostFor($exit),
                'Net' => $this->netFor($exit),
            ]);
    }

    private function commissionFor(TradeExit $exit): float
    {
        return $this->costCalculator->commissionFor($exit);
    }

    private function vatFor(TradeExit $exit): float
    {
        return $this->costCalculator->vatFor($exit);
    }

    private function totalCostFor(TradeExit $exit): float
    {
        return $this->costCalculator->totalCostFor($exit);
    }

    private function netFor(TradeExit $exit): float
    {
        return $this->costCalculator->netFor($exit);
    }

    /**
     * @param  Collection<int, array<string, float|int|string>>  $rows
     */
    private function createXlsx(Collection $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tfex-report-').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($rows));
        $zip->close();

        return $path;
    }

    /**
     * @param  Collection<int, array<string, float|int|string>>  $rows
     */
    private function sheetXml(Collection $rows): string
    {
        $headers = ['Exit date', 'Contract', 'Contracts', 'Gross', 'Commission', 'VAT', 'Total Cost', 'Net'];
        $xmlRows = [$this->sheetRow(1, $headers, true)];

        foreach ($rows->values() as $index => $row) {
            $xmlRows[] = $this->sheetRow($index + 2, array_map(fn ($header) => $row[$header], $headers));
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            .'<cols><col min="1" max="1" width="14" customWidth="1"/><col min="2" max="2" width="14" customWidth="1"/><col min="3" max="8" width="13" customWidth="1"/></cols>'
            .'<sheetData>'.implode('', $xmlRows).'</sheetData>'
            .'</worksheet>';
    }

    /**
     * @param  array<int, float|int|string>  $values
     */
    private function sheetRow(int $rowNumber, array $values, bool $heading = false): string
    {
        $cells = '';

        foreach ($values as $index => $value) {
            $cell = $this->columnName($index + 1).$rowNumber;
            $style = $heading ? ' s="1"' : '';

            if (is_numeric($value) && ! $heading) {
                $cells .= '<c r="'.$cell.'"'.$style.'><v>'.$value.'</v></c>';
            } else {
                $cells .= '<c r="'.$cell.'" t="inlineStr"'.$style.'><is><t>'.e((string) $value).'</t></is></c>';
            }
        }

        return '<row r="'.$rowNumber.'">'.$cells.'</row>';
    }

    private function columnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    /**
     * @param  Collection<int, array<string, float|int|string>>  $rows
     */
    private function createPdf(Collection $rows): string
    {
        $lines = ['TFEX Journal Report', 'Generated: '.now()->format('Y-m-d H:i:s'), ''];
        $lines[] = 'Date | Contract | Qty | Gross | Commission | VAT | Total Cost | Net';
        $lines[] = str_repeat('-', 96);

        foreach ($rows as $row) {
            $lines[] = implode(' | ', [
                $row['Exit date'],
                $row['Contract'],
                $row['Contracts'],
                number_format((float) $row['Gross'], 2),
                number_format((float) $row['Commission'], 2),
                number_format((float) $row['VAT'], 2),
                number_format((float) $row['Total Cost'], 2),
                number_format((float) $row['Net'], 2),
            ]);
        }

        if ($rows->isEmpty()) {
            $lines[] = 'No exits found.';
        }

        return $this->pdfFromLines($lines);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function pdfFromLines(array $lines): string
    {
        $content = "BT\n/F1 10 Tf\n50 545 Td\n14 TL\n";

        foreach ($lines as $line) {
            $content .= '('.$this->pdfText($line).") Tj\nT*\n";
        }

        $content .= "ET\n";
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Courier >> endobj',
            '5 0 obj << /Length '.strlen($content).' >> stream'."\n".$content.'endstream endobj',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        $pdf .= 'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF";

        return $pdf;
    }

    private function pdfText(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Reports" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf fontId="0" fillId="0" borderId="0" xfId="0"/><xf fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>';
    }
}
