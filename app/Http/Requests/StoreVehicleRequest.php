<?php

namespace App\Http\Requests;

use App\Models\VehicleBrand;
use App\Models\VehicleType;
use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('vehicle.create');
    }

    public function rules()
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'plate_number' => ['nullable', 'string', 'max:30', 'unique:vehicles,plate_number'],
            'frame_number' => ['nullable', 'string', 'max:100', 'unique:vehicles,frame_number'],
            'engine_number' => ['nullable', 'string', 'max:100', 'unique:vehicles,engine_number'],
            'category_id' => ['required', 'exists:vehicle_categories,id'],
            'brand_id' => ['required', 'exists:vehicle_brands,id'],
            'type_id' => ['required', 'exists:vehicle_types,id'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $brandId = $this->input('brand_id');
            $categoryId = $this->input('category_id');
            $typeId = $this->input('type_id');

            if ($brandId && $categoryId) {
                $brand = VehicleBrand::find($brandId);
                if ($brand && (int) $brand->category_id !== (int) $categoryId) {
                    $validator->errors()->add('brand_id', 'Merk yang dipilih tidak sesuai dengan kategori.');
                }
            }

            if ($typeId && $brandId) {
                $type = VehicleType::find($typeId);
                if ($type && (int) $type->brand_id !== (int) $brandId) {
                    $validator->errors()->add('type_id', 'Tipe yang dipilih tidak sesuai dengan merk.');
                }
            }
        });
    }
}
