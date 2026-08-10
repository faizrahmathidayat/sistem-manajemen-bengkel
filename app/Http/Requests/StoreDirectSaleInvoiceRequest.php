<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use Illuminate\Foundation\Http\FormRequest;

class StoreDirectSaleInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId > 0 && $this->user()->hasPermissionToInBranch('invoice.create', $branchId);
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'services' => array_values(array_filter($this->input('services', []), function ($line) {
                return isset($line['description']) && trim($line['description']) !== '';
            })),
            'spareparts' => array_values(array_filter($this->input('spareparts', []), function ($line) {
                return ! empty($line['sparepart_branch_id']);
            })),
        ]);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'invoice_date' => ['required', 'date'],
            'services' => ['nullable', 'array'],
            'services.*' => ['array'],
            'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
            'services.*.qty' => ['required_with:services.*.description', 'numeric', 'min:0.001'],
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'services.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*' => ['array'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0.001'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
            'spareparts.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->input('branch_id');
            $services = $this->input('services', []);
            $spareparts = $this->input('spareparts', []);

            if (empty($services) && empty($spareparts)) {
                $validator->errors()->add('services', 'Invoice harus punya minimal satu baris jasa atau sparepart.');
            }

            foreach ($spareparts as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("spareparts.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang yang dipilih.');
                }
            }
        });
    }
}
