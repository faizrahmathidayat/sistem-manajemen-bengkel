<?php

namespace App\Imports;

use App\Models\Rack;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Reads rows that reference spareparts already in the master (by code) so they can be
 * attached to one branch. Counterpart of SparepartMasterLinesImport, which creates new ones.
 */
class SparepartExistingLinesImport implements ToCollection, WithHeadingRow
{
    public const MAX_ROWS = 100;

    /** @var array<int, array{sparepart_id:int, code:string, name:string, rack_id:?int, selling_price:float, minimum_stock:float}> */
    public array $lines = [];

    /** @var array<int, string> */
    public array $errors = [];

    /** @var int */
    private $branchId;

    public function __construct(int $branchId)
    {
        $this->branchId = $branchId;
    }

    public function collection(Collection $rows)
    {
        $isBlank = function ($row) {
            return trim((string) ($row['kode_sparepart'] ?? '')) === ''
                && ($row['harga_jual'] ?? null) === null;
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
            $rackCode = trim((string) ($row['kode_rak'] ?? ''));
            $priceRaw = $row['harga_jual'] ?? null;
            $stockRaw = $row['stok_minimum'] ?? null;

            if ($code === '') {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart harus diisi.";

                continue;
            }
            if (isset($seenCodes[$code])) {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart \"{$code}\" duplikat dengan baris {$seenCodes[$code]}.";

                continue;
            }

            $sparepart = Sparepart::where('code', $code)->first();
            if (! $sparepart) {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart \"{$code}\" tidak ditemukan di master sparepart.";

                continue;
            }
            if (! $sparepart->is_active) {
                $this->errors[] = "Baris {$rowNumber}: Sparepart \"{$code}\" tidak aktif di master.";

                continue;
            }
            if (SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $this->branchId)->exists()) {
                $this->errors[] = "Baris {$rowNumber}: Sparepart \"{$code}\" sudah terkonfigurasi di cabang ini.";

                continue;
            }

            $rackId = null;
            if ($rackCode !== '') {
                $rack = Rack::where('code', $rackCode)->where('is_active', true)->first();
                if (! $rack) {
                    $this->errors[] = "Baris {$rowNumber}: Rak dengan kode \"{$rackCode}\" tidak ditemukan atau tidak aktif.";

                    continue;
                }
                $rackId = $rack->id;
            }

            if ($priceRaw === null || $priceRaw === '') {
                $this->errors[] = "Baris {$rowNumber}: Harga jual harus diisi.";

                continue;
            }
            if (! is_numeric($priceRaw)) {
                $this->errors[] = "Baris {$rowNumber}: Harga jual harus berupa angka.";

                continue;
            }
            if ((float) $priceRaw < 0) {
                $this->errors[] = "Baris {$rowNumber}: Harga jual tidak boleh negatif.";

                continue;
            }

            if ($stockRaw !== null && $stockRaw !== '' && ! is_numeric($stockRaw)) {
                $this->errors[] = "Baris {$rowNumber}: Stok minimum harus berupa angka.";

                continue;
            }
            if ($stockRaw !== null && $stockRaw !== '' && (float) $stockRaw < 0) {
                $this->errors[] = "Baris {$rowNumber}: Stok minimum tidak boleh negatif.";

                continue;
            }

            $seenCodes[$code] = $rowNumber;

            $this->lines[] = [
                'sparepart_id' => $sparepart->id,
                'code' => $sparepart->code,
                'name' => $sparepart->name,
                'rack_id' => $rackId,
                'selling_price' => round((float) $priceRaw),
                'minimum_stock' => ($stockRaw === null || $stockRaw === '') ? 0.0 : round((float) $stockRaw),
            ];
        }
    }
}
