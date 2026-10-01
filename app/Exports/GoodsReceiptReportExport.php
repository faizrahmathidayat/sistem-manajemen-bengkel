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

class GoodsReceiptReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
{
    protected const STATUS_LABELS = ['draft' => 'Draft', 'posted' => 'Diposting', 'cancelled' => 'Dibatalkan'];

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
            ? ['No. Dokumen', 'Tanggal', 'Cabang', 'Status', 'Kode', 'Nama Sparepart', 'Qty', 'Harga Beli', 'Total']
            : ['No. Dokumen', 'Tanggal', 'Cabang', 'No. Referensi', 'Status', 'Jumlah Item', 'Total Qty', 'Total Nilai'];
    }

    public function map($row): array
    {
        if ($this->mode === 'detail') {
            $receipt = $row->goodsReceipt;

            return [
                $receipt->number,
                $receipt->receipt_date->format('d/m/Y'),
                $receipt->branch->name,
                self::STATUS_LABELS[$receipt->status] ?? $receipt->status,
                $row->sparepartBranch->sparepart->code,
                $row->sparepartBranch->sparepart->name,
                (float) $row->qty,
                (float) $row->purchase_price,
                (float) $row->line_total,
            ];
        }

        return [
            $row->number,
            $row->receipt_date->format('d/m/Y'),
            $row->branch->name,
            $row->reference_number ?? '-',
            self::STATUS_LABELS[$row->status] ?? $row->status,
            (int) $row->lines_count,
            (float) $row->total_qty,
            (float) $row->total_nilai,
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
