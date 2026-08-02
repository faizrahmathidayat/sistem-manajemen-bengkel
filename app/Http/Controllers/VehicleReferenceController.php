<?php

namespace App\Http\Controllers;

use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Http\Request;

class VehicleReferenceController extends Controller
{
    public function index()
    {
        $this->authorize('vehicle_reference.view');

        $categories = VehicleCategory::with('brands.types')->orderBy('name')->get();

        return view('vehicle-references.index', compact('categories'));
    }

    public function storeCategory(Request $request)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:vehicle_categories,name'],
        ]);

        $category = VehicleCategory::create($data);

        return response()->json(['message' => 'Kategori berhasil ditambahkan.', 'category' => $category]);
    }

    public function updateCategory(Request $request, VehicleCategory $category)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:vehicle_categories,name,' . $category->id],
            'is_active' => ['required', 'boolean'],
        ]);

        $category->update($data);

        return response()->json(['message' => 'Kategori berhasil diperbarui.', 'category' => $category]);
    }

    public function storeBrand(Request $request)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'category_id' => ['required', 'exists:vehicle_categories,id'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $exists = VehicleBrand::where('category_id', $data['category_id'])->where('name', $data['name'])->exists();
        if ($exists) {
            return response()->json(['message' => 'Merk dengan nama ini sudah ada pada kategori tersebut.'], 422);
        }

        $brand = VehicleBrand::create($data);

        return response()->json(['message' => 'Merk berhasil ditambahkan.', 'brand' => $brand]);
    }

    public function updateBrand(Request $request, VehicleBrand $brand)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ]);

        $exists = VehicleBrand::where('category_id', $brand->category_id)
            ->where('name', $data['name'])
            ->where('id', '!=', $brand->id)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Merk dengan nama ini sudah ada pada kategori tersebut.'], 422);
        }

        $brand->update($data);

        return response()->json(['message' => 'Merk berhasil diperbarui.', 'brand' => $brand]);
    }

    public function storeType(Request $request)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'brand_id' => ['required', 'exists:vehicle_brands,id'],
            'name' => ['required', 'string', 'max:150'],
        ]);

        $exists = VehicleType::where('brand_id', $data['brand_id'])->where('name', $data['name'])->exists();
        if ($exists) {
            return response()->json(['message' => 'Tipe dengan nama ini sudah ada pada merk tersebut.'], 422);
        }

        $type = VehicleType::create($data);

        return response()->json(['message' => 'Tipe berhasil ditambahkan.', 'type' => $type]);
    }

    public function updateType(Request $request, VehicleType $type)
    {
        $this->authorize('vehicle_reference.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ]);

        $exists = VehicleType::where('brand_id', $type->brand_id)
            ->where('name', $data['name'])
            ->where('id', '!=', $type->id)
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Tipe dengan nama ini sudah ada pada merk tersebut.'], 422);
        }

        $type->update($data);

        return response()->json(['message' => 'Tipe berhasil diperbarui.', 'type' => $type]);
    }
}
