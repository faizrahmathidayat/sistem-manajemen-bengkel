<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class DemoMasterDataSeeder extends Seeder
{
    public function run()
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('DemoMasterDataSeeder only runs in local/testing environments (fake business data, not for production).');

            return;
        }

        $this->call(VehicleCategorySeeder::class);

        $branches = $this->seedBranches();
        ['brands' => $brands, 'types' => $types] = $this->seedVehicleReferenceData();
        $customers = $this->seedCustomers($branches);
        $this->seedVehicles($customers, $brands, $types);
        $this->seedMechanics($branches);
        $this->seedServiceCatalogs();
        $this->seedSpareparts($branches);

        $this->command->info('Demo master data seeded (local/testing only): 3 customers, 5 vehicles, 8 mechanics, 10 service catalogs, 12 spareparts.');
    }

    /**
     * Same branch definitions as DemoUsersSeeder::seedBranches() — kept independent
     * (not calling that seeder) so this seeder works standalone on a fresh DB too.
     */
    protected function seedBranches()
    {
        $definitions = [
            ['code' => 'BENGKEL1', 'name' => 'Bengkel 1'],
            ['code' => 'BENGKEL2', 'name' => 'Bengkel 2'],
            ['code' => 'BENGKEL3', 'name' => 'Bengkel 3'],
        ];

        return collect($definitions)->map(function ($definition) {
            return Branch::updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'is_active' => true]
            );
        })->keyBy('code');
    }

    protected function seedVehicleReferenceData(): array
    {
        $motor = VehicleCategory::where('name', 'Motor')->firstOrFail();

        $brandDefinitions = ['Honda', 'Yamaha'];
        $brands = collect($brandDefinitions)->mapWithKeys(function ($name) use ($motor) {
            $brand = VehicleBrand::firstOrCreate(['category_id' => $motor->id, 'name' => $name]);

            return [$name => $brand];
        });

        $typeDefinitions = [
            'Honda' => ['Beat', 'Vario'],
            'Yamaha' => ['NMAX', 'Jupiter Z'],
        ];

        $types = collect();
        foreach ($typeDefinitions as $brandName => $typeNames) {
            foreach ($typeNames as $typeName) {
                $type = VehicleType::firstOrCreate(['brand_id' => $brands[$brandName]->id, 'name' => $typeName]);
                $types["{$brandName} {$typeName}"] = $type;
            }
        }

        return ['brands' => $brands, 'types' => $types];
    }

    protected function seedCustomers($branches)
    {
        $definitions = [
            [
                'key' => 'ahmad',
                'branch' => 'BENGKEL1',
                'data' => [
                    'customer_type' => 'INDIVIDUAL',
                    'name' => 'Ahmad Fauzi',
                    'stnk_name' => 'Ahmad Fauzi',
                    'phone' => '081234500001',
                    'address' => 'Jl. Merdeka No. 10, Jakarta',
                ],
            ],
            [
                'key' => 'dewi',
                'branch' => 'BENGKEL2',
                'data' => [
                    'customer_type' => 'INDIVIDUAL',
                    'name' => 'Dewi Anggraini',
                    'stnk_name' => 'Dewi Anggraini',
                    'phone' => '081234500002',
                    'address' => 'Jl. Asia Afrika No. 25, Bandung',
                ],
            ],
            [
                'key' => 'sinar_motor',
                'branch' => 'BENGKEL3',
                'data' => [
                    'customer_type' => 'COMPANY',
                    'name' => 'CV Sinar Motor',
                    'stnk_name' => 'CV Sinar Motor',
                    'phone' => '081234500003',
                    'address' => 'Jl. Gatot Subroto No. 88, Surabaya',
                ],
            ],
        ];

        $customers = [];

        foreach ($definitions as $definition) {
            $customer = Customer::updateOrCreate(
                ['name' => $definition['data']['name']],
                $definition['data'] + ['is_active' => true]
            );

            CustomerBranch::firstOrCreate([
                'customer_id' => $customer->id,
                'branch_id' => $branches[$definition['branch']]->id,
            ], ['is_active' => true]);

            $customers[$definition['key']] = $customer;
        }

        return $customers;
    }

    protected function seedVehicles($customers, $brands, $types)
    {
        $definitions = [
            // Ahmad Fauzi — 2 vehicles
            ['customer' => 'ahmad', 'brand' => 'Honda', 'type' => 'Honda Beat', 'plate' => 'B 1234 ABC', 'frame' => 'FRAME-DEMO-001', 'engine' => 'ENGINE-DEMO-001'],
            ['customer' => 'ahmad', 'brand' => 'Yamaha', 'type' => 'Yamaha NMAX', 'plate' => 'B 5678 CDE', 'frame' => 'FRAME-DEMO-002', 'engine' => 'ENGINE-DEMO-002'],
            // Dewi Anggraini — 2 vehicles
            ['customer' => 'dewi', 'brand' => 'Honda', 'type' => 'Honda Vario', 'plate' => 'D 2345 FGH', 'frame' => 'FRAME-DEMO-003', 'engine' => 'ENGINE-DEMO-003'],
            ['customer' => 'dewi', 'brand' => 'Yamaha', 'type' => 'Yamaha Jupiter Z', 'plate' => 'D 6789 IJK', 'frame' => 'FRAME-DEMO-004', 'engine' => 'ENGINE-DEMO-004'],
            // CV Sinar Motor — 1 vehicle
            ['customer' => 'sinar_motor', 'brand' => 'Honda', 'type' => 'Honda Beat', 'plate' => 'F 1122 LMN', 'frame' => 'FRAME-DEMO-005', 'engine' => 'ENGINE-DEMO-005'],
        ];

        $motorCategory = VehicleCategory::where('name', 'Motor')->firstOrFail();

        foreach ($definitions as $definition) {
            Vehicle::updateOrCreate(
                ['plate_number' => $definition['plate']],
                [
                    'customer_id' => $customers[$definition['customer']]->id,
                    'frame_number' => $definition['frame'],
                    'engine_number' => $definition['engine'],
                    'category_id' => $motorCategory->id,
                    'brand_id' => $brands[$definition['brand']]->id,
                    'type_id' => $types[$definition['type']]->id,
                    'is_active' => true,
                ]
            );
        }
    }

    protected function seedMechanics($branches)
    {
        $definitions = [
            ['name' => 'Agus Salim', 'branch' => 'BENGKEL1'],
            ['name' => 'Bambang Wijaya', 'branch' => 'BENGKEL1'],
            ['name' => 'Candra Kusuma', 'branch' => 'BENGKEL1'],
            ['name' => 'Deni Setiawan', 'branch' => 'BENGKEL2'],
            ['name' => 'Eko Prasetyo', 'branch' => 'BENGKEL2'],
            ['name' => 'Fajar Nugroho', 'branch' => 'BENGKEL2'],
            ['name' => 'Gunawan Saputra', 'branch' => 'BENGKEL3'],
            ['name' => 'Hendra Kurniawan', 'branch' => 'BENGKEL3'],
        ];

        foreach ($definitions as $index => $definition) {
            $mechanic = Mechanic::updateOrCreate(
                ['name' => $definition['name']],
                [
                    'phone' => '08123460' . str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                    'is_active' => true,
                ]
            );

            MechanicBranch::firstOrCreate([
                'mechanic_id' => $mechanic->id,
                'branch_id' => $branches[$definition['branch']]->id,
            ], ['is_active' => true]);
        }
    }

    protected function seedServiceCatalogs()
    {
        $definitions = [
            ['code' => 'SVC-TUN01', 'name' => 'Tune-Up & Service Ringan', 'default_price' => 70000],
            ['code' => 'SVC-OIL01', 'name' => 'Ganti Oli Mesin & Gardan', 'default_price' => 95000],
            ['code' => 'SVC-CVT01', 'name' => 'Service & Clean CVT', 'default_price' => 80000],
            ['code' => 'SVC-INJ01', 'name' => 'Service Injeksi & Reset ECU', 'default_price' => 105000],
            ['code' => 'SVC-BRK01', 'name' => 'Service Rem & Ganti Kampas', 'default_price' => 90000],
            ['code' => 'SVC-SUS01', 'name' => 'Service Shockbreaker Depan', 'default_price' => 160000],
            ['code' => 'SVC-STE01', 'name' => 'Ganti & Setel Komstir', 'default_price' => 200000],
            ['code' => 'SVC-ELC01', 'name' => 'Diagnostic & Kelistrikan', 'default_price' => 320000],
            ['code' => 'SVC-OVR01', 'name' => 'Top Overhaul (Turun Mesin Atas)', 'default_price' => 600000],
            ['code' => 'SVC-OVR02', 'name' => 'Total Overhaul (Turun Mesin Total)', 'default_price' => 1250000],
        ];

        foreach ($definitions as $definition) {
            ServiceCatalog::updateOrCreate(
                ['code' => $definition['code']],
                $definition + ['is_active' => true]
            );
        }
    }

    /**
     * Configured identically in all 3 branches — SparepartBranch's booted()
     * hook auto-creates a zeroed SparepartBranchStock row per (sparepart,
     * branch) pair, matching reality until migration 008's goods receipt
     * flow gives on_hand_qty a writer (out of scope here — master data only).
     */
    protected function seedSpareparts($branches)
    {
        $definitions = [
            ['code' => 'SP-OLI-001', 'name' => 'Oli Mesin Yamalube 800ml', 'rack' => 'A1-01', 'price' => 45000, 'minimum_stock' => 10],
            ['code' => 'SP-OLI-002', 'name' => 'Oli Mesin AHM MPX2 800ml', 'rack' => 'A1-02', 'price' => 48000, 'minimum_stock' => 10],
            ['code' => 'SP-KMP-001', 'name' => 'Kampas Rem Depan Universal', 'rack' => 'A2-01', 'price' => 35000, 'minimum_stock' => 8],
            ['code' => 'SP-KMP-002', 'name' => 'Kampas Rem Belakang Universal', 'rack' => 'A2-02', 'price' => 32000, 'minimum_stock' => 8],
            ['code' => 'SP-BUS-001', 'name' => 'Busi NGK CPR8EA-9', 'rack' => 'A3-01', 'price' => 25000, 'minimum_stock' => 15],
            ['code' => 'SP-FIL-001', 'name' => 'Filter Udara Standar', 'rack' => 'A3-02', 'price' => 40000, 'minimum_stock' => 6],
            ['code' => 'SP-AKI-001', 'name' => 'Aki GS Astra GTZ5S', 'rack' => 'B1-01', 'price' => 185000, 'minimum_stock' => 4],
            ['code' => 'SP-BAN-001', 'name' => 'Ban Luar FDR 80/90-14', 'rack' => 'B1-02', 'price' => 210000, 'minimum_stock' => 4],
            ['code' => 'SP-RAN-001', 'name' => 'Rantai Motor 428H', 'rack' => 'B2-01', 'price' => 95000, 'minimum_stock' => 5],
            ['code' => 'SP-GER-001', 'name' => 'Gear Set (Depan+Belakang)', 'rack' => 'B2-02', 'price' => 120000, 'minimum_stock' => 5],
            ['code' => 'SP-VBE-001', 'name' => 'V-Belt CVT', 'rack' => 'B3-01', 'price' => 150000, 'minimum_stock' => 5],
            ['code' => 'SP-LAM-001', 'name' => 'Lampu Depan LED Universal', 'rack' => 'B3-02', 'price' => 65000, 'minimum_stock' => 6],
        ];

        foreach ($definitions as $definition) {
            $sparepart = Sparepart::updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'is_active' => true]
            );

            foreach ($branches as $branch) {
                SparepartBranch::firstOrCreate(
                    ['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id],
                    [
                        'rack_number' => $definition['rack'],
                        'selling_price' => $definition['price'],
                        'minimum_stock' => $definition['minimum_stock'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
