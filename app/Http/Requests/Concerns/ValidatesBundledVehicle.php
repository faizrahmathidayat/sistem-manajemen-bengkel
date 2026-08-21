<?php

namespace App\Http\Requests\Concerns;

use App\Models\VehicleBrand;
use App\Models\VehicleType;
use Illuminate\Validation\Validator;

trait ValidatesBundledVehicle
{
    protected function bundledVehicleRules(): array
    {
        return [
            'vehicle_category_id' => ['nullable', 'exists:vehicle_categories,id'],
            'vehicle_brand_id' => ['nullable', 'exists:vehicle_brands,id'],
            'vehicle_type_id' => ['nullable', 'exists:vehicle_types,id'],
            'vehicle_plate_number' => ['nullable', 'string', 'max:30', 'unique:vehicles,plate_number'],
            'vehicle_frame_number' => ['nullable', 'string', 'max:100', 'unique:vehicles,frame_number'],
            'vehicle_engine_number' => ['nullable', 'string', 'max:100', 'unique:vehicles,engine_number'],
            'vehicle_year' => ['nullable', 'integer', 'digits:4', 'between:1900,' . (now()->year + 1)],
        ];
    }

    protected function validateBundledVehicle(Validator $validator): void
    {
        $fields = [
            'vehicle_category_id', 'vehicle_brand_id', 'vehicle_type_id',
            'vehicle_plate_number', 'vehicle_frame_number', 'vehicle_engine_number', 'vehicle_year',
        ];

        if (collect($fields)->every(fn ($field) => ! $this->filled($field))) {
            return;
        }

        foreach (['vehicle_category_id' => 'Kategori', 'vehicle_brand_id' => 'Merk', 'vehicle_type_id' => 'Tipe'] as $field => $label) {
            if (! $this->filled($field)) {
                $validator->errors()->add($field, "{$label} kendaraan wajib diisi.");
            }
        }

        $categoryId = $this->input('vehicle_category_id');
        $brandId = $this->input('vehicle_brand_id');
        $typeId = $this->input('vehicle_type_id');

        if ($brandId && $categoryId) {
            $brand = VehicleBrand::find($brandId);
            if ($brand && (int) $brand->category_id !== (int) $categoryId) {
                $validator->errors()->add('vehicle_brand_id', 'Merk yang dipilih tidak sesuai dengan kategori.');
            }
        }

        if ($typeId && $brandId) {
            $type = VehicleType::find($typeId);
            if ($type && (int) $type->brand_id !== (int) $brandId) {
                $validator->errors()->add('vehicle_type_id', 'Tipe yang dipilih tidak sesuai dengan merk.');
            }
        }
    }

    protected function hasBundledVehicle(): bool
    {
        return collect([
            'vehicle_category_id', 'vehicle_brand_id', 'vehicle_type_id',
            'vehicle_plate_number', 'vehicle_frame_number', 'vehicle_engine_number', 'vehicle_year',
        ])->contains(fn ($field) => $this->filled($field));
    }
}
