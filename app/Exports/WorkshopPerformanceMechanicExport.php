<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class WorkshopPerformanceMechanicExport implements FromArray, ShouldAutoSize, WithEvents
{
    protected Collection $rows;
    protected string $filterSummary;

    public function __construct(Collection $rows, string $filterSummary)
    {
        $this->rows = $rows;
        $this->filterSummary = $filterSummary;
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:I1');
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);

                $headings = [
                    'Mekanik', 'Total Customer', 'Total Qty Jasa', 'Total Discount Jasa (Rp)', 'Subtotal Jasa',
                    'Total Qty Sparepart', 'Total Discount Sparepart (Rp)', 'Subtotal Sparepart', 'Grand Total',
                ];
                foreach ($headings as $index => $heading) {
                    $sheet->setCellValueByColumnAndRow($index + 1, 2, $heading);
                }
                $sheet->getStyle('A2:I2')->getFont()->setBold(true);

                $row = 3;
                foreach ($this->rows as $mechanicRow) {
                    $mechanicLabel = $mechanicRow->mechanic_nip
                        ? "{$mechanicRow->mechanic_nip} - {$mechanicRow->mechanic_name}"
                        : $mechanicRow->mechanic_name;

                    $sheet->setCellValue("A{$row}", $mechanicLabel);
                    $sheet->setCellValue("B{$row}", (float) $mechanicRow->total_customer);
                    $sheet->setCellValue("C{$row}", (float) $mechanicRow->total_qty_jasa);
                    $sheet->setCellValue("D{$row}", (float) $mechanicRow->total_discount_jasa);
                    $sheet->setCellValue("E{$row}", (float) $mechanicRow->subtotal_jasa);
                    $sheet->setCellValue("F{$row}", (float) $mechanicRow->total_qty_sparepart);
                    $sheet->setCellValue("G{$row}", (float) $mechanicRow->total_discount_sparepart);
                    $sheet->setCellValue("H{$row}", (float) $mechanicRow->subtotal_sparepart);
                    $sheet->setCellValue("I{$row}", "=E{$row}+H{$row}");

                    $row++;
                }
            },
        ];
    }
}
