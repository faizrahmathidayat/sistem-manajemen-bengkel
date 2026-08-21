<?php

namespace App\Http\Requests;

use App\Models\SparepartBranch;
use App\Models\WorkOrderServiceLine;
use App\Models\WorkOrderSparepartLine;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('invoice'));
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
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_percent' => ['required', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date', 'after_or_equal:' . $this->route('invoice')->invoice_date->toDateString()],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*' => ['array'],
            'services.*.work_order_service_line_id' => ['nullable', 'integer', 'exists:work_order_service_lines,id'],
            'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
            'services.*.qty' => ['required_with:services.*.description', 'integer', 'min:1'],
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'services.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*' => ['array'],
            'spareparts.*.work_order_sparepart_line_id' => ['nullable', 'integer', 'exists:work_order_sparepart_lines,id'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'integer', 'min:1'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
            'spareparts.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $invoice = $this->route('invoice');
            $branchId = (int) $invoice->branch_id;
            $workOrderId = (int) $invoice->work_order_id;

            foreach ($this->input('services', []) as $index => $line) {
                $lineId = $line['work_order_service_line_id'] ?? null;
                if (! $lineId) {
                    continue;
                }
                $exists = WorkOrderServiceLine::where('id', $lineId)->where('work_order_id', $workOrderId)->exists();
                if (! $exists) {
                    $validator->errors()->add("services.{$index}.work_order_service_line_id", 'Baris jasa PKB tidak valid untuk invoice ini.');
                }
            }

            foreach ($this->input('spareparts', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if ($sparepartBranchId) {
                    $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                    if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                        $validator->errors()->add("spareparts.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang invoice ini.');
                    }
                }

                $lineId = $line['work_order_sparepart_line_id'] ?? null;
                if (! $lineId) {
                    continue;
                }
                $exists = WorkOrderSparepartLine::where('id', $lineId)->where('work_order_id', $workOrderId)->exists();
                if (! $exists) {
                    $validator->errors()->add("spareparts.{$index}.work_order_sparepart_line_id", 'Baris sparepart PKB tidak valid untuk invoice ini.');
                }
            }
        });
    }
}
