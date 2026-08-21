<?php

namespace App\Imports;

use App\Models\ServiceCatalog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ServiceCatalogLinesImport implements ToCollection, WithHeadingRow
{
    public const MAX_ROWS = 100;

    /** @var array<int, array{code:string, name:string, default_price:float}> */
    public array $lines = [];

    /** @var array<int, string> */
    public array $errors = [];

    public function collection(Collection $rows)
    {
        $isBlank = function ($row) {
            return trim((string) ($row['kode_jasa'] ?? '')) === ''
                && trim((string) ($row['nama_jasa'] ?? '')) === ''
                && ($row['harga_default'] ?? null) === null;
        };

        $meaningfulCount = $rows->reject($isBlank)->count();

        if ($meaningfulCount === 0) {
            $this->errors[] = 'File tidak berisi baris jasa yang bisa diimport.';

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
            $code = trim((string) ($row['kode_jasa'] ?? ''));
            $name = trim((string) ($row['nama_jasa'] ?? ''));
            $priceRaw = $row['harga_default'] ?? null;

            if ($code === '') {
                $this->errors[] = "Baris {$rowNumber}: Kode jasa harus diisi.";

                continue;
            }
            if (mb_strlen($code) > 30) {
                $this->errors[] = "Baris {$rowNumber}: Kode jasa maksimal 30 karakter.";

                continue;
            }
            if (isset($seenCodes[$code])) {
                $this->errors[] = "Baris {$rowNumber}: Kode jasa \"{$code}\" duplikat dengan baris {$seenCodes[$code]}.";

                continue;
            }
            if (ServiceCatalog::where('code', $code)->exists()) {
                $this->errors[] = "Baris {$rowNumber}: Kode jasa \"{$code}\" sudah digunakan.";

                continue;
            }

            if ($name === '') {
                $this->errors[] = "Baris {$rowNumber}: Nama jasa harus diisi.";

                continue;
            }
            if (mb_strlen($name) > 150) {
                $this->errors[] = "Baris {$rowNumber}: Nama jasa maksimal 150 karakter.";

                continue;
            }

            if ($priceRaw === null || $priceRaw === '') {
                $this->errors[] = "Baris {$rowNumber}: Harga default harus diisi.";

                continue;
            }
            if (! is_numeric($priceRaw)) {
                $this->errors[] = "Baris {$rowNumber}: Harga default harus berupa angka.";

                continue;
            }
            if ((float) $priceRaw < 0) {
                $this->errors[] = "Baris {$rowNumber}: Harga default tidak boleh negatif.";

                continue;
            }

            $seenCodes[$code] = $rowNumber;

            $this->lines[] = [
                'code' => $code,
                'name' => $name,
                'default_price' => (float) $priceRaw,
            ];
        }
    }
}
