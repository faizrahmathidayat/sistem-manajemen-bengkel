<?php

namespace App\Exports;

use App\Support\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class PurchaseOrderReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
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
        return $this->query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        if ($this->mode === 'detail') {
            return ['No. Pembelian', 'Tanggal', 'Supplier', 'Cabang', 'Status', 'Kode', 'Nama Sparepart', 'Qty Pesan', 'Qty Terima', 'Sisa', 'Harga', 'Nilai'];
        }
        if ($this->mode === 'supplier') {
            return ['Supplier', 'Jumlah PO', 'Nilai Dipesan', 'Nilai Diterima', 'Outstanding'];
        }

        return ['No. Pembelian', 'Tanggal', 'Supplier', 'Cabang', 'Nilai PO', 'Diterima', 'Outstanding', '% Terima', 'Status'];
    }

    public function map($row): array
    {
        if ($this->mode === 'detail') {
            $order = $row->purchaseOrder;

            return [
                $order->number,
                $order->order_date->format('d/m/Y'),
                $order->supplier->name,
                $order->branch->name,
                PurchaseOrderStatus::LABELS[$order->status] ?? $order->status,
                $row->sparepartBranch->sparepart->code,
                $row->sparepartBranch->sparepart->name,
                (float) $row->qty_ordered,
                (float) $row->qty_received,
                $row->remainingQty(),
                (float) $row->unit_price,
                (float) $row->line_total,
            ];
        }

        if ($this->mode === 'supplier') {
            return [
                $row->supplier_name,
                (int) $row->po_count,
                (float) $row->ordered_value,
                (float) $row->received_value,
                round((float) $row->ordered_value - (float) $row->received_value, 2),
            ];
        }

        $ordered = (float) $row->qty_ordered_sum;

        return [
            $row->number,
            $row->order_date->format('d/m/Y'),
            $row->supplier->name,
            $row->branch->name,
            (float) $row->total_amount,
            (float) $row->received_value,
            round((float) $row->total_amount - (float) $row->received_value, 2),
            $ordered > 0 ? round(((float) $row->qty_received_sum / $ordered) * 100) . '%' : '0%',
            PurchaseOrderStatus::LABELS[$row->status] ?? $row->status,
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
