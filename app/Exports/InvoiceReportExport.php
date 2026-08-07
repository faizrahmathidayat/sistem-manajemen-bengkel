<?php

namespace App\Exports;

use App\Support\InvoiceDetailItemType;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class InvoiceReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected Builder $query;
    protected string $mode;
    protected string $filterSummary;

    public function __construct(Builder $query, string $mode, string $filterSummary)
    {
        $this->query = $query;
        $this->mode = $mode;
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
        return $this->mode === 'detail'
            ? ['No. Invoice', 'Tanggal', 'Customer', 'Status', 'Tipe Item', 'Nama Item', 'Qty', 'Harga Satuan', 'Subtotal Line']
            : ['No. Invoice', 'Tanggal', 'Customer', 'Subtotal Jasa', 'Subtotal Sparepart', 'Discount', 'Grand Total', 'Terbayar', 'Sisa Piutang', 'Status'];
    }

    public function map($invoice): array
    {
        if ($this->mode !== 'detail') {
            return [
                $invoice->number,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                (float) $invoice->subtotal_service,
                (float) $invoice->subtotal_sparepart,
                (float) $invoice->discount_amount,
                (float) $invoice->grand_total,
                (float) $invoice->paid_amount,
                (float) $invoice->outstanding_amount,
                $invoice->status,
            ];
        }

        if ($invoice->details->isEmpty()) {
            return [[$invoice->number, $invoice->invoice_date->format('Y-m-d'), $invoice->customer->name, $invoice->status, '-', '-', null, null, null]];
        }

        return $invoice->details->map(function ($detail) use ($invoice) {
            return [
                $invoice->number,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                $invoice->status,
                $detail->item_type === InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart',
                $detail->description,
                (float) $detail->qty,
                (float) $detail->unit_price,
                (float) $detail->line_total,
            ];
        })->all();
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
