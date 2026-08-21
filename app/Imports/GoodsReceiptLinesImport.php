<?php

namespace App\Imports;

use App\Models\SparepartBranch;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class GoodsReceiptLinesImport implements ToCollection, WithHeadingRow
{
    public const MAX_ROWS = 100;

    /** @var array<int, array{sparepart_branch_id:int, sparepart_code:string, sparepart_name:string, qty:int, purchase_price:float}> */
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
                && ($row['qty'] ?? null) === null
                && ($row['harga_satuan'] ?? null) === null;
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

        foreach ($rows as $index => $row) {
            if ($isBlank($row)) {
                continue;
            }

            $rowNumber = $index + 2;
            $code = trim((string) ($row['kode_sparepart'] ?? ''));
            $qtyRaw = $row['qty'] ?? null;
            $priceRaw = $row['harga_satuan'] ?? null;

            if ($code === '') {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart harus diisi.";

                continue;
            }

            if ($qtyRaw === null || $qtyRaw === '') {
                $this->errors[] = "Baris {$rowNumber}: Qty harus diisi.";

                continue;
            }
            if (! is_numeric($qtyRaw)) {
                $this->errors[] = "Baris {$rowNumber}: Qty harus berupa angka.";

                continue;
            }
            if ((float) $qtyRaw != (int) $qtyRaw) {
                $this->errors[] = "Baris {$rowNumber}: Qty harus berupa bilangan bulat, tidak boleh desimal.";

                continue;
            }
            if ((int) $qtyRaw <= 0) {
                $this->errors[] = "Baris {$rowNumber}: Qty harus lebih besar dari 0.";

                continue;
            }

            if ($priceRaw === null || $priceRaw === '') {
                $this->errors[] = "Baris {$rowNumber}: Harga satuan harus diisi.";

                continue;
            }
            if (! is_numeric($priceRaw)) {
                $this->errors[] = "Baris {$rowNumber}: Harga satuan harus berupa angka.";

                continue;
            }
            if ((float) $priceRaw < 0) {
                $this->errors[] = "Baris {$rowNumber}: Harga satuan tidak boleh negatif.";

                continue;
            }

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
                'qty' => (int) $qtyRaw,
                'purchase_price' => (float) $priceRaw,
            ];
        }
    }
}
