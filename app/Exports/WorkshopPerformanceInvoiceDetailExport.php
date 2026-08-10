<?php

namespace App\Exports;

use App\Support\WorkshopPerformanceLinePairer;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class WorkshopPerformanceInvoiceDetailExport implements FromArray, ShouldAutoSize, WithEvents
{
    protected Collection $invoices;
    protected string $filterSummary;

    public function __construct(Collection $invoices, string $filterSummary)
    {
        $this->invoices = $invoices;
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

                $sheet->mergeCells('A1:K1');
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);

                $row = 2;

                foreach ($this->invoices as $invoice) {
                    $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-';

                    $metaHeadings = ['No. Invoice', 'Tanggal', 'Status', 'Customer', 'Mekanik', 'Cabang'];
                    foreach ($metaHeadings as $index => $heading) {
                        $sheet->setCellValueByColumnAndRow($index + 1, $row, $heading);
                    }
                    $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
                    $row++;

                    $sheet->setCellValue("A{$row}", $invoice->number);
                    $sheet->setCellValue("B{$row}", $invoice->invoice_date->format('Y-m-d'));
                    $sheet->setCellValue("C{$row}", $invoice->status);
                    $sheet->setCellValue("D{$row}", $invoice->customer->name);
                    $sheet->setCellValue("E{$row}", $mechanicLabel);
                    $sheet->setCellValue("F{$row}", $invoice->branch->name);
                    $row++;

                    $lineHeadings = [
                        'Jasa', 'Harga Satuan Jasa', 'Qty', 'Diskon (%)', 'Subtotal Jasa',
                        'Sparepart', 'Harga Satuan Sparepart', 'Qty', 'Diskon (%)', 'Subtotal Sparepart',
                        'Subtotal Line',
                    ];
                    foreach ($lineHeadings as $index => $heading) {
                        $sheet->setCellValueByColumnAndRow($index + 1, $row, $heading);
                    }
                    $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
                    $row++;

                    $pairs = WorkshopPerformanceLinePairer::build($invoice);
                    $firstPairRow = $row;

                    if (empty($pairs)) {
                        $sheet->setCellValue("A{$row}", '-');
                        $sheet->setCellValue("B{$row}", 0);
                        $sheet->setCellValue("C{$row}", 0);
                        $sheet->setCellValue("D{$row}", 0);
                        $sheet->setCellValue("E{$row}", "=B{$row}*C{$row}*(1-D{$row}/100)");
                        $sheet->setCellValue("F{$row}", '-');
                        $sheet->setCellValue("G{$row}", 0);
                        $sheet->setCellValue("H{$row}", 0);
                        $sheet->setCellValue("I{$row}", 0);
                        $sheet->setCellValue("J{$row}", "=G{$row}*H{$row}*(1-I{$row}/100)");
                        $sheet->setCellValue("K{$row}", "=J{$row}+E{$row}");
                        $row++;
                    } else {
                        foreach ($pairs as $pair) {
                            $sheet->setCellValue("A{$row}", $pair['jasa_desc']);
                            $sheet->setCellValue("B{$row}", $pair['jasa_price']);
                            $sheet->setCellValue("C{$row}", $pair['jasa_qty']);
                            $sheet->setCellValue("D{$row}", $pair['jasa_discount_percent']);
                            $sheet->setCellValue("E{$row}", "=B{$row}*C{$row}*(1-D{$row}/100)");
                            $sheet->setCellValue("F{$row}", $pair['sparepart_desc']);
                            $sheet->setCellValue("G{$row}", $pair['sparepart_price']);
                            $sheet->setCellValue("H{$row}", $pair['sparepart_qty']);
                            $sheet->setCellValue("I{$row}", $pair['sparepart_discount_percent']);
                            $sheet->setCellValue("J{$row}", "=G{$row}*H{$row}*(1-I{$row}/100)");
                            $sheet->setCellValue("K{$row}", "=J{$row}+E{$row}");
                            $row++;
                        }
                    }

                    $lastPairRow = $row - 1;

                    $sheet->setCellValue("A{$row}", 'Total');
                    $sheet->setCellValue("E{$row}", "=SUM(E{$firstPairRow}:E{$lastPairRow})");
                    $sheet->setCellValue("J{$row}", "=SUM(J{$firstPairRow}:J{$lastPairRow})");
                    $sheet->setCellValue("K{$row}", "=J{$row}+E{$row}");
                    $sheet->getStyle("A{$row}:K{$row}")->getFont()->setBold(true);
                    $row++;
                }
            },
        ];
    }
}
