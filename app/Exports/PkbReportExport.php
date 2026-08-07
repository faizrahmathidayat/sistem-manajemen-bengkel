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

class PkbReportExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
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
        return $this->query->orderByDesc('work_order_date')->orderByDesc('id');
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return $this->mode === 'detail'
            ? ['No. PKB', 'Tanggal', 'Customer & Kendaraan', 'Tipe Item', 'Nama Item/Jasa', 'Qty', 'Harga Satuan', 'Subtotal Line', 'Status']
            : ['No. PKB', 'Tanggal', 'Customer & Kendaraan', 'Mekanik', 'Subtotal Jasa', 'Subtotal Sparepart', 'Grand Total', 'Status'];
    }

    public function map($workOrder): array
    {
        $customerVehicle = $workOrder->customer->name . ($workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : '');

        if ($this->mode !== 'detail') {
            $subtotalService = (float) $workOrder->serviceLines->sum('line_total');
            $subtotalSparepart = (float) $workOrder->sparepartLines->sum('line_total');

            return [
                $workOrder->number,
                $workOrder->work_order_date->format('Y-m-d'),
                $customerVehicle,
                $workOrder->mechanic->name,
                $subtotalService,
                $subtotalSparepart,
                $subtotalService + $subtotalSparepart,
                $workOrder->status,
            ];
        }

        $rows = [];
        foreach ($workOrder->serviceLines as $line) {
            $rows[] = [$workOrder->number, $workOrder->work_order_date->format('Y-m-d'), $customerVehicle, 'Jasa', $line->description, (float) $line->qty, (float) $line->unit_price, (float) $line->line_total, $workOrder->status];
        }
        foreach ($workOrder->sparepartLines as $line) {
            $rows[] = [$workOrder->number, $workOrder->work_order_date->format('Y-m-d'), $customerVehicle, 'Sparepart', $line->item_name_snapshot, (float) $line->qty, (float) $line->unit_price, (float) $line->line_total, $workOrder->status];
        }

        return $rows;
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
