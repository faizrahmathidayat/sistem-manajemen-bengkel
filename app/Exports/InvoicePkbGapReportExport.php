<?php

namespace App\Exports;

use App\Models\Invoice;
use App\Support\InvoicePkbGapComparator;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class InvoicePkbGapReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
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
        return $this->query->orderByDesc('invoices.invoice_date')->orderByDesc('invoices.id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. PKB', 'No. Invoice', 'Tanggal', 'Customer', 'Tipe Item', 'Nama Item', 'Qty PKB', 'Harga PKB', 'Qty Invoice', 'Harga Invoice', 'Kategori']
            : ['No. PKB', 'No. Invoice', 'Tanggal', 'Customer', 'Total PKB', 'Total Invoice', 'Selisih (Rp)', 'Status Gap'];
    }

    public function map($invoice): array
    {
        $pkbTotal = (float) $invoice->pkb_total;
        $grandTotal = (float) $invoice->grand_total;

        if ($this->mode !== 'detail') {
            $selisih = $grandTotal - $pkbTotal;
            $statusGap = $selisih == 0.0 ? 'Sesuai' : ($selisih > 0 ? 'Invoice > PKB' : 'Invoice < PKB');

            return [
                $invoice->workOrder->number,
                $invoice->number,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                $pkbTotal,
                $grandTotal,
                $selisih,
                $statusGap,
            ];
        }

        $categoryLabels = ['sesuai' => 'Sesuai', 'changed' => 'Berubah', 'removed' => 'Dihapus', 'added' => 'Ditambahkan'];
        $lines = InvoicePkbGapComparator::build($invoice);

        if (empty($lines)) {
            return [[$invoice->workOrder->number, $invoice->number, $invoice->invoice_date->format('Y-m-d'), $invoice->customer->name, '-', '-', null, null, null, null, '-']];
        }

        return array_map(function ($line) use ($invoice, $categoryLabels) {
            return [
                $invoice->workOrder->number,
                $invoice->number,
                $invoice->invoice_date->format('Y-m-d'),
                $invoice->customer->name,
                $line['item_type'],
                $line['item_name'],
                $line['pkb_qty'],
                $line['pkb_price'],
                $line['invoice_qty'],
                $line['invoice_price'],
                $categoryLabels[$line['category']],
            ];
        }, $lines);
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
