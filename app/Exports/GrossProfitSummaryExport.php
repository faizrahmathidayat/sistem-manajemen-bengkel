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

class GrossProfitSummaryExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, ShouldAutoSize, WithEvents
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
        return $this->query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        return ['Periode', 'Cabang ID', 'Pendapatan Jasa', 'Pendapatan Sparepart', 'Total HPP', 'Laba Kotor', 'Margin %'];
    }

    public function map($row): array
    {
        $pendapatan = (float) $row->pendapatan_jasa + (float) $row->pendapatan_sparepart;
        $labaKotor = $pendapatan - (float) $row->total_hpp;
        $margin = $pendapatan > 0 ? round(($labaKotor / $pendapatan) * 100, 1) : 0;

        return [
            $row->period,
            $row->branch_id,
            (float) $row->pendapatan_jasa,
            (float) $row->pendapatan_sparepart,
            (float) $row->total_hpp,
            $labaKotor,
            $margin,
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
