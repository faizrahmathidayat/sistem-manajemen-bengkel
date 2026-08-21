<?php

namespace App\Imports;

use App\Models\Rack;
use App\Models\Sparepart;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class SparepartMasterLinesImport implements ToCollection, WithHeadingRow
{
    public const MAX_ROWS = 100;

    /** @var array<int, array{code:string, name:string, rack_id:?int, rack_code:?string, selling_price:float, minimum_stock:float}> */
    public array $lines = [];

    /** @var array<int, string> */
    public array $errors = [];

    public function collection(Collection $rows)
    {
        $isBlank = function ($row) {
            return trim((string) ($row['kode_sparepart'] ?? '')) === ''
                && trim((string) ($row['nama_sparepart'] ?? '')) === ''
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
            $name = trim((string) ($row['nama_sparepart'] ?? ''));
            $rackCode = trim((string) ($row['kode_rak'] ?? ''));
            $priceRaw = $row['harga_jual'] ?? null;
            $stockRaw = $row['stok_minimum'] ?? null;

            if ($code === '') {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart harus diisi.";

                continue;
            }
            if (mb_strlen($code) > 30) {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart maksimal 30 karakter.";

                continue;
            }
            if (isset($seenCodes[$code])) {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart \"{$code}\" duplikat dengan baris {$seenCodes[$code]}.";

                continue;
            }
            if (Sparepart::where('code', $code)->exists()) {
                $this->errors[] = "Baris {$rowNumber}: Kode sparepart \"{$code}\" sudah digunakan.";

                continue;
            }

            if ($name === '') {
                $this->errors[] = "Baris {$rowNumber}: Nama sparepart harus diisi.";

                continue;
            }
            if (mb_strlen($name) > 150) {
                $this->errors[] = "Baris {$rowNumber}: Nama sparepart maksimal 150 karakter.";

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
                'code' => $code,
                'name' => $name,
                'rack_id' => $rackId,
                'rack_code' => $rackId ? $rackCode : null,
                'selling_price' => (float) $priceRaw,
                'minimum_stock' => ($stockRaw === null || $stockRaw === '') ? 0.0 : (float) $stockRaw,
            ];
        }
    }
}
