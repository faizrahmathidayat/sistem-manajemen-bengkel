<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkOrderRequest extends FormRequest
{
    public function authorize()
    {
        $branchId = (int) $this->input('branch_id');

        return $branchId && $this->user()->hasPermissionToInBranch('pkb.create', $branchId);
    }

    public function rules()
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'mechanic_id' => ['required', 'integer', 'exists:mechanics,id'],
            'work_order_date' => ['required', 'date'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'services' => ['nullable', 'array'],
            'services.*.service_catalog_id' => ['nullable', 'integer', 'exists:service_catalogs,id'],
            'services.*.description' => ['required_with:services.*.qty', 'string', 'max:255'],
            'services.*.qty' => ['required_with:services.*.description', 'numeric', 'min:0.001'],
            'services.*.unit_price' => ['required_with:services.*.description', 'numeric', 'min:0'],
            'spareparts' => ['nullable', 'array'],
            'spareparts.*.sparepart_branch_id' => ['required_with:spareparts.*.qty', 'integer', 'exists:sparepart_branches,id'],
            'spareparts.*.qty' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0.001'],
            'spareparts.*.unit_price' => ['required_with:spareparts.*.sparepart_branch_id', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $this->validateCascadingBusinessRules($validator, (int) $this->input('branch_id'));
        });
    }

    protected function validateCascadingBusinessRules($validator, int $branchId): void
    {
        $customerId = $this->input('customer_id');
        $vehicleId = $this->input('vehicle_id');
        $mechanicId = $this->input('mechanic_id');

        if ($branchId && $customerId) {
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

        if ($branchId && $mechanicId) {
            $mechanic = Mechanic::find($mechanicId);
            if (! $mechanic || ! $mechanic->is_active || ! $mechanic->hasAccessToBranch($branchId)) {
                $validator->errors()->add('mechanic_id', 'Mekanik tidak aktif atau tidak ditugaskan di cabang ini.');
            }
        }

        $services = array_filter($this->input('services', []));
        $spareparts = array_filter($this->input('spareparts', []));
        if (empty($services) && empty($spareparts)) {
            $validator->errors()->add('services', 'PKB harus memiliki minimal satu baris jasa atau sparepart.');
        }

        if ($branchId) {
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
        }
    }
}
