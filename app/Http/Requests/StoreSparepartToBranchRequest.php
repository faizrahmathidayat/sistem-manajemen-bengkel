<?php

namespace App\Http\Requests;

use App\Models\Sparepart;
use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreSparepartToBranchRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('sparepart.create', $branchId);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'lines' => array_values(array_filter((array) $this->input('lines', []), function ($line) {
                return is_array($line) && ! empty($line['sparepart_id']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.sparepart_id' => ['required', 'integer', 'distinct', 'exists:spareparts,id'],
            'lines.*.rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'lines.*.selling_price' => ['required', 'integer', 'min:0'],
            'lines.*.minimum_stock' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages()
    {
        return [
            'lines.required' => 'Tambahkan minimal satu sparepart.',
            'lines.min' => 'Tambahkan minimal satu sparepart.',
            'lines.max' => 'Maksimal 100 baris per penyimpanan.',
            'lines.*.sparepart_id.distinct' => 'Sparepart yang sama dipilih lebih dari sekali.',
            'lines.*.selling_price.required' => 'Harga jual harus diisi untuk setiap baris.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $branchId = (int) $this->input('branch_id');
            $lines = $this->input('lines', []);
            $configured = SparepartBranch::where('branch_id', $branchId)
                ->whereIn('sparepart_id', collect($lines)->pluck('sparepart_id'))
                ->pluck('sparepart_id')
                ->all();

            if ($configured === []) {
                return;
            }

            $codes = Sparepart::whereIn('id', $configured)->pluck('code', 'id');
            foreach ($lines as $index => $line) {
                if (in_array((int) $line['sparepart_id'], $configured, true)) {
                    $validator->errors()->add(
                        "lines.{$index}.sparepart_id",
                        "Sparepart {$codes[(int) $line['sparepart_id']]} sudah terkonfigurasi di cabang ini."
                    );
                }
            }
        });
    }
}
