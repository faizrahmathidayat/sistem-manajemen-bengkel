<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkOrderRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('update', $this->route('workOrder'));
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
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'mechanic_id' => ['required', 'integer', 'exists:mechanics,id'],
            'work_order_date' => ['required', 'date'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*' => ['array'],
            'services.*.service_catalog_id' => ['required_with:services.*.qty', 'integer', 'exists:service_catalogs,id'],
            'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
            'services.*.qty' => ['required_with:services.*.description', 'integer', 'min:1'],
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*' => ['array'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'integer', 'min:1'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $branchId = (int) $this->route('workOrder')->branch_id;
            $customerId = $this->input('customer_id');
            $vehicleId = $this->input('vehicle_id');
            $mechanicId = $this->input('mechanic_id');

            if ($customerId) {
                $customer = Customer::find($customerId);
                if ($customer && ! $customer->hasAccessToBranch($branchId)) {
                    $validator->errors()->add('customer_id', 'Customer tidak dapat dilayani di cabang ini.');
                }
            }

            if ($customerId && $vehicleId) {
                $vehicle = Vehicle::find($vehicleId);
                if ($vehicle && (int) $vehicle->customer_id !== (int) $customerId) {
                    $validator->errors()->add('vehicle_id', 'Kendaraan tidak sesuai dengan customer yang dipilih.');
                }
            }

            if ($mechanicId) {
                $mechanic = Mechanic::find($mechanicId);
                if (! $mechanic || ! $mechanic->is_active || ! $mechanic->hasAccessToBranch($branchId)) {
                    $validator->errors()->add('mechanic_id', 'Mekanik tidak aktif atau tidak ditugaskan di cabang ini.');
                }
            }

            // services/spareparts are already stripped of all-blank rows in
            // prepareForValidation(), so an empty array here means the user
            // genuinely submitted no meaningful lines.
            if (empty($this->input('services', [])) && empty($this->input('spareparts', []))) {
                $validator->errors()->add('services', 'PKB harus memiliki minimal satu baris jasa atau sparepart.');
            }

            foreach ($this->input('spareparts', []) as $index => $line) {
                $sparepartBranchId = $line['sparepart_branch_id'] ?? null;
                if (! $sparepartBranchId) {
                    continue;
                }
                $sparepartBranch = SparepartBranch::find($sparepartBranchId);
                if (! $sparepartBranch || ! $sparepartBranch->is_active || (int) $sparepartBranch->branch_id !== $branchId) {
                    $validator->errors()->add("spareparts.{$index}.sparepart_branch_id", 'Sparepart tidak aktif atau bukan dari cabang PKB ini.');
                }
            }
        });
    }
}
