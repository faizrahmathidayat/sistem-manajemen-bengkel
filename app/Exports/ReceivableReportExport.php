<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class ReceivableReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $filterSummary;

    public function __construct(Builder $query, string $filterSummary)
    {
        $this->query = $query;
        $this->filterSummary = $filterSummary;
    }

    public function query()
    {
        return $this->query->orderByDesc('invoice_date')->orderByDesc('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return ['No. Invoice', 'Tanggal', 'Customer', 'Cabang', 'Grand Total', 'Sudah Dibayar', 'Sisa Piutang', 'Jatuh Tempo', 'Umur Piutang (hari)', 'Status'];
    }

    public function map($invoice): array
    {
        $referenceDate = $invoice->due_date ?? $invoice->invoice_date;
        $daysOverdue = (int) $referenceDate->diffInDays(now(), false);

        return [
            $invoice->number,
            $invoice->invoice_date->format('Y-m-d'),
            $invoice->customer->name,
            $invoice->branch->name,
            (float) $invoice->grand_total,
            (float) $invoice->paid_amount,
            (float) ($invoice->grand_total - $invoice->paid_amount),
            $invoice->due_date ? $invoice->due_date->format('Y-m-d') : '-',
            $daysOverdue,
            $invoice->status,
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $sheet->getHighestColumn();
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->setCellValue('A1', $this->filterSummary);
                $sheet->getStyle('A1')->getFont()->setBold(true);
            },
        ];
    }
}
