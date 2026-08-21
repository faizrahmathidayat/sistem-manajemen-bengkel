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

class SparepartStockReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
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
        return $this->query->orderBy('sparepart_branches.branch_id')->orderBy('sparepart_branches.id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['Kode', 'Nama Sparepart', 'Cabang', 'Rak', 'Stok Min', 'On-Hand', 'Reserved', 'Available', 'Harga Jual', 'Nilai Total', 'Status']
            : ['Kode', 'Nama Sparepart', 'Cabang', 'Rak', 'Stok Min', 'Stok On-Hand', 'Harga Jual', 'Nilai Inventaris', 'Status'];
    }

    public function map($sparepartBranch): array
    {
        $onHand = (float) $sparepartBranch->on_hand_qty;
        $reserved = (float) $sparepartBranch->reserved_qty;
        $available = $onHand - $reserved;
        $minimumStock = (float) $sparepartBranch->minimum_stock;
        $sellingPrice = (float) $sparepartBranch->selling_price;
        $rackCode = optional($sparepartBranch->rack)->code ?? '-';

        if ($onHand == 0.0) {
            $status = 'Habis';
        } elseif ($minimumStock > 0.0 && $available < $minimumStock) {
            $status = 'Kritis';
        } else {
            $status = 'Tersedia';
        }

        if ($this->mode !== 'detail') {
            return [
                $sparepartBranch->sparepart->code,
                $sparepartBranch->sparepart->name,
                $sparepartBranch->branch->name,
                $rackCode,
                $minimumStock,
                $onHand,
                $sellingPrice,
                $onHand * $sellingPrice,
                $status,
            ];
        }

        return [
            $sparepartBranch->sparepart->code,
            $sparepartBranch->sparepart->name,
            $sparepartBranch->branch->name,
            $rackCode,
            $minimumStock,
            $onHand,
            $reserved,
            $available,
            $sellingPrice,
            $onHand * $sellingPrice,
            $status,
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
