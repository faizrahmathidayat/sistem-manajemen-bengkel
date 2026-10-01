<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StockAdjustmentLineImportTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    public function headings(): array
    {
        return ['Kode Sparepart', 'Qty Fisik', 'Alasan'];
    }

    public function array(): array
    {
        return [
            ['SPR-00001', 12, 'Hasil stock opname'],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
