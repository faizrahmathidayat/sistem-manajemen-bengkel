<?php

namespace App\Exports;

use App\Support\PurchaseRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;

class PurchaseRequestReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
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
        return $this->mode === 'detail'
            ? ['No. Permintaan', 'Tanggal', 'Cabang', 'Status', 'Kode', 'Nama Sparepart', 'Qty', 'Supplier', 'Harga Beli', 'Subtotal', 'Dipilih', 'Urgent']
            : ['No. Permintaan', 'Tanggal', 'Cabang', 'Pemohon', 'Jumlah Item', 'Total', 'Status', 'Posisi / Keputusan', 'Lama Proses (hari)', 'Urgent'];
    }

    public function map($row): array
    {
        if ($this->mode === 'detail') {
            $request = $row->purchaseRequest;

            return [
                $request->number,
                $request->request_date->format('d/m/Y'),
                $request->branch->name,
                PurchaseRequestStatus::LABELS[$request->status] ?? $request->status,
                $row->sparepartBranch->sparepart->code,
                $row->sparepartBranch->sparepart->name,
                (float) $row->qty,
                $row->supplier ? $row->supplier->name : '-',
                $row->unit_price !== null ? (float) $row->unit_price : '-',
                $row->line_total !== null ? (float) $row->line_total : '-',
                $row->is_selected ? 'Ya' : 'Tidak',
                $request->is_urgent ? 'Ya' : '-',
            ];
        }

        $total = $row->total_amount !== null ? (float) $row->total_amount : (float) $row->lines_amount;

        return [
            $row->number,
            $row->request_date->format('d/m/Y'),
            $row->branch->name,
            optional($row->creator)->name ?? '-',
            (int) $row->lines_count,
            $total > 0 ? $total : '-',
            PurchaseRequestStatus::LABELS[$row->status] ?? $row->status,
            $row->positionLabel(),
            $row->processDays() ?? '-',
            $row->is_urgent ? 'Ya' : '-',
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
