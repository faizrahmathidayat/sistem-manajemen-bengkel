<?php

namespace App\Imports;

use App\Models\SparepartBranch;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class StockAdjustmentLinesImport implements ToCollection, WithHeadingRow
{
    public const MAX_ROWS = 100;
    public const DEFAULT_REASON = '-';

    /** @var array<int, array{sparepart_branch_id:int, sparepart_code:string, sparepart_name:string, physical_qty:int, reason:string}> */
    public array $lines = [];

    /** @var array<int, string> */
    public array $errors = [];

    protected int $branchId;

    public function __construct(int $branchId)
    {
        $this->branchId = $branchId;
    }

    public function collection(Collection $rows)
    {
        $isBlank = function ($row) {
            return trim((string) ($row['kode_sparepart'] ?? '')) === ''
                && ($row['qty_fisik'] ?? null) === null
                && trim((string) ($row['alasan'] ?? '')) === '';
        };

        $meaningfulCount = $rows->reject($isBlank)->count();

        if ($meaningfulCount === 0) {
            $this->errors[] = 'File tidak berisi baris sparepart yang bisa diimport.';

            return;
        }

        if ($meaningfulCount > self::MAX_ROWS) {
            $this->errors[] = 'Jumlah baris (' . $meaningfulCount . ') melebihi batas maksimal ' . self::MAX_ROWS . ' baris.';

            return;
        }

        $seenCodes = [];

        foreach ($rows as $index => $row) {
            if ($isBlank($row)) {
                continue;
            }

            $rowNumber = $index + 2;
            $code = trim((string) ($row['kode_sparepart'] ?? ''));
            $qtyRaw = $row['qty_fisik'] ?? null;
            $reason = trim((string) ($row['alasan'] ?? ''));

            if ($code === '') {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart harus diisi.";

                continue;
            }

            if ($qtyRaw === null || $qtyRaw === '') {
                $this->errors[] = "Baris {$rowNumber}: Qty fisik harus diisi.";

                continue;
            }
            if (! is_numeric($qtyRaw)) {
                $this->errors[] = "Baris {$rowNumber}: Qty fisik harus berupa angka.";

                continue;
            }
            if ((float) $qtyRaw != (int) $qtyRaw) {
                $this->errors[] = "Baris {$rowNumber}: Qty fisik harus berupa bilangan bulat, tidak boleh desimal.";

                continue;
            }
            if ((int) $qtyRaw < 0) {
                $this->errors[] = "Baris {$rowNumber}: Qty fisik tidak boleh negatif.";

                continue;
            }

            if (mb_strlen($reason) > 255) {
                $this->errors[] = "Baris {$rowNumber}: Alasan maksimal 255 karakter.";

                continue;
            }

            $key = mb_strtolower($code);
            if (isset($seenCodes[$key])) {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart \"{$code}\" dobel (sudah ada di baris {$seenCodes[$key]}).";

                continue;
            }
            $seenCodes[$key] = $rowNumber;

            $sparepartBranch = SparepartBranch::where('branch_id', $this->branchId)
                ->where('is_active', true)
                ->whereHas('sparepart', fn ($query) => $query->where('code', $code))
                ->with('sparepart')
                ->first();

            if (! $sparepartBranch) {
                $this->errors[] = "Baris {$rowNumber}: Sparepart dengan kode \"{$code}\" tidak ditemukan atau tidak aktif di cabang ini.";

                continue;
            }

            $this->lines[] = [
                'sparepart_branch_id' => $sparepartBranch->id,
                'sparepart_code' => $sparepartBranch->sparepart->code,
                'sparepart_name' => $sparepartBranch->sparepart->name,
                'physical_qty' => (int) $qtyRaw,
                'reason' => $reason === '' ? self::DEFAULT_REASON : $reason,
            ];
        }
    }
}
