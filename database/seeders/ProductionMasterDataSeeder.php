<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Rack;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class ProductionMasterDataSeeder extends Seeder
{
    public function run()
    {
        $branch = Branch::where('code', 'CABANGUTAMA')->firstOrFail();

        $rack = $this->seedRack();
        $vehicleTypes = $this->seedVehicleReference();
        $this->seedSpareparts($branch, $rack);
        $this->seedServiceCatalogs();
        $this->seedCustomersAndVehicles($branch, $vehicleTypes);

        $this->command->info('Master data production seeded: 1 rack, ' . count($this->sparepartDefinitions()) . ' sparepart, '
            . count($this->serviceCatalogDefinitions()) . ' jasa, ' . count($this->customerVehicleDefinitions()) . ' customer+kendaraan.');
    }

    protected function seedRack(): Rack
    {
        return Rack::updateOrCreate(['code' => '001'], ['is_active' => true]);
    }

    /**
     * @return array<string, array<string, VehicleType>> keyed [merk][tipe] => VehicleType
     */
    protected function seedVehicleReference(): array
    {
        $this->call(VehicleCategorySeeder::class);
        $category = VehicleCategory::where('name', 'Motor')->firstOrFail();

        $types = [];

        foreach ($this->vehicleReferenceDefinitions() as $merk => $tipeList) {
            $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => $merk]);

            foreach ($tipeList as $tipe) {
                $types[$merk][$tipe] = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => $tipe]);
            }
        }

        return $types;
    }

    protected function seedSpareparts(Branch $branch, Rack $rack): void
    {
        foreach ($this->sparepartDefinitions() as $definition) {
            $sparepart = Sparepart::updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'is_active' => true]
            );

            SparepartBranch::firstOrCreate(
                ['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id],
                [
                    'rack_id' => $rack->id,
                    'selling_price' => $definition['price'],
                    'minimum_stock' => $definition['min_stock'],
                    'is_active' => true,
                ]
            );
        }
    }

    protected function seedServiceCatalogs(): void
    {
        foreach ($this->serviceCatalogDefinitions() as $definition) {
            ServiceCatalog::updateOrCreate(
                ['code' => $definition['code']],
                ['name' => $definition['name'], 'default_price' => $definition['price'], 'is_active' => true]
            );
        }
    }

    /**
     * @param  array<string, array<string, VehicleType>>  $vehicleTypes
     */
    protected function seedCustomersAndVehicles(Branch $branch, array $vehicleTypes): void
    {
        $category = VehicleCategory::where('name', 'Motor')->firstOrFail();

        foreach ($this->customerVehicleDefinitions() as $definition) {
            $customer = Customer::updateOrCreate(
                ['stnk_name' => $definition['stnk_name'], 'phone' => $definition['phone']],
                [
                    'customer_type' => 'INDIVIDUAL',
                    'name' => $definition['name'],
                    'address' => $definition['address'],
                    'is_active' => true,
                ]
            );

            CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);

            $brand = VehicleBrand::where('category_id', $category->id)->where('name', $definition['merk'])->firstOrFail();
            $type = $vehicleTypes[$definition['merk']][$definition['tipe']];

            Vehicle::updateOrCreate(
                ['plate_number' => $definition['plate']],
                [
                    'customer_id' => $customer->id,
                    'frame_number' => $definition['frame'],
                    'engine_number' => $definition['engine'],
                    'category_id' => $category->id,
                    'brand_id' => $brand->id,
                    'type_id' => $type->id,
                    'is_active' => true,
                ]
            );
        }
    }

    protected function vehicleReferenceDefinitions(): array
    {
        return [
            'HONDA' => ['BEAT', 'REVO', 'SCOOPY', 'VARIO', 'VARIO 125 CBS', 'VARIO 150'],
            'SUZUKI' => ['SUZUKI NEX'],
            'YAMAHA' => ['MIO', 'MIO J', 'MIO SOUL GT', 'XEON'],
        ];
    }

    protected function sparepartDefinitions(): array
    {
        return [
            ['code' => '23100K2SN01', 'name' => 'V-BELT ONLY', 'price' => 164500, 'min_stock' => 1],
            ['code' => '54PWE44510', 'name' => 'FILTER UDARA', 'price' => 40200, 'min_stock' => 1],
            ['code' => '17210K1ZN20', 'name' => 'FILTER UDARA', 'price' => 77500, 'min_stock' => 1],
            ['code' => '17210K97N00', 'name' => 'FILTER UDARA', 'price' => 81000, 'min_stock' => 1],
            ['code' => '17210K16900', 'name' => 'FILTER UDARA', 'price' => 54500, 'min_stock' => 1],
            ['code' => '17210KZR600', 'name' => 'FILTER UDARA', 'price' => 56500, 'min_stock' => 1],
            ['code' => '17210K0JN00', 'name' => 'FILTER UDARA', 'price' => 55000, 'min_stock' => 1],
            ['code' => '2DPWE76J02', 'name' => 'V-BELT SET', 'price' => 190000, 'min_stock' => 1],
            ['code' => '06410KWB600', 'name' => 'KARET TROMOL', 'price' => 28000, 'min_stock' => 1],
            ['code' => 'HB6301RSIND', 'name' => 'BEARING INDOPART', 'price' => 13000, 'min_stock' => 1],
            ['code' => 'HB6300RSIND', 'name' => 'BEARING INDOPART', 'price' => 13000, 'min_stock' => 1],
            ['code' => 'HB6004RSIND', 'name' => 'BEARING INDOPART', 'price' => 15000, 'min_stock' => 1],
            ['code' => 'HB6201RSIND', 'name' => 'BEARING INDOPART', 'price' => 12000, 'min_stock' => 1],
            ['code' => '45530K59A71', 'name' => 'SEAL MASTER REM', 'price' => 28500, 'min_stock' => 1],
            ['code' => '3C1W001A20', 'name' => 'GEAR SET', 'price' => 384000, 'min_stock' => 1],
            ['code' => '3S0W001A10', 'name' => 'GEAR SET', 'price' => 232000, 'min_stock' => 1],
            ['code' => '06401KWW900', 'name' => 'GEAR SET', 'price' => 211000, 'min_stock' => 1],
            ['code' => '5240AKTM850ZB', 'name' => 'SHOCK SET', 'price' => 409500, 'min_stock' => 1],
            ['code' => '52400K59A11', 'name' => 'SHOCK BREKER', 'price' => 256500, 'min_stock' => 1],
            ['code' => '1S7F530K00', 'name' => 'KAMPAS BELAKANG YAMAHA', 'price' => 66000, 'min_stock' => 1],
            ['code' => '06455KVB1310', 'name' => 'KAMPAS DEPAN KVBT ASPIRA', 'price' => 27500, 'min_stock' => 1],
            ['code' => '34901KFVB51', 'name' => 'BOHLAM DEPAN', 'price' => 20000, 'min_stock' => 1],
            ['code' => '22123K36T00', 'name' => 'ROLLER', 'price' => 56000, 'min_stock' => 1],
            ['code' => '22123K0JN00', 'name' => 'ROLLER', 'price' => 48500, 'min_stock' => 1],
            ['code' => '2212AKVY900', 'name' => 'ROLLER', 'price' => 63000, 'min_stock' => 1],
            ['code' => '23100K1ZBA0', 'name' => 'V-BELT ASSY', 'price' => 236500, 'min_stock' => 1],
            ['code' => '23100K1ZN21', 'name' => 'V-BELT ONLY', 'price' => 193000, 'min_stock' => 1],
            ['code' => '23100KVYBA1', 'name' => 'V-BELT ASSY', 'price' => 171000, 'min_stock' => 1],
            ['code' => 'YAMALUBE AT 0,8L', 'name' => 'YAMALUBE MATICK 0,8L', 'price' => 62000, 'min_stock' => 1],
            ['code' => 'YAMALUBE AT 1L', 'name' => 'YAMALUBE SUPER MATICK 1L', 'price' => 94000, 'min_stock' => 1],
            ['code' => 'DELTALUBE AT 0,8L', 'name' => 'DELTALUBE MATICK 0,8L', 'price' => 96000, 'min_stock' => 1],
            ['code' => 'SHELL AX5 AT 0,8L', 'name' => 'SHELL AX5 0,8L', 'price' => 63000, 'min_stock' => 1],
            ['code' => 'DELTALUBE AT1L', 'name' => 'DELTALUBE MATICK 1L', 'price' => 139000, 'min_stock' => 1],
            ['code' => 'ENDURO AT1L', 'name' => 'ENDURO MATICK 1L', 'price' => 74500, 'min_stock' => 1],
            ['code' => 'ENDURO AT 0,8L', 'name' => 'ENDUROMATICK 0,8L', 'price' => 63000, 'min_stock' => 1],
            ['code' => 'FDR0,8L', 'name' => 'OLI FEDERAL 0,8L', 'price' => 63000, 'min_stock' => 1],
            ['code' => '06401K45NA0', 'name' => 'GEARSET', 'price' => 391000, 'min_stock' => 1],
            ['code' => '06401KYZ900', 'name' => 'GEAR SET', 'price' => 222000, 'min_stock' => 1],
            ['code' => '06401K41N01', 'name' => 'GEAR SET', 'price' => 229000, 'min_stock' => 1],
            ['code' => '06401KPH881', 'name' => 'GEAR SET', 'price' => 210000, 'min_stock' => 1],
            ['code' => '90793AJ842', 'name' => 'YAMALUBE GEAR 100ML', 'price' => 17000, 'min_stock' => 1],
            ['code' => 'STPWF01A10', 'name' => 'GEAR SET', 'price' => 224000, 'min_stock' => 1],
            ['code' => '45530KET920', 'name' => 'SEAL MASTER REM', 'price' => 84500, 'min_stock' => 1],
            ['code' => '5ERH414000', 'name' => 'PITING LAMPU YAMAHA', 'price' => 25500, 'min_stock' => 1],
            ['code' => 'OLI MPX2 0,8L', 'name' => 'OLI MPX2 0,8L', 'price' => 78500, 'min_stock' => 1],
            ['code' => 'OLI MPX2 0,65L', 'name' => 'OLI MPX2 0,65L', 'price' => 67000, 'min_stock' => 1],
            ['code' => 'SCOOTER AHM 120ml', 'name' => 'OLI GARDAN HONDA', 'price' => 19500, 'min_stock' => 1],
            ['code' => '12209GB4682', 'name' => 'SEAL KLEP AHM', 'price' => 6000, 'min_stock' => 1],
            ['code' => '31917K0RV01', 'name' => 'BUSI HONDA 160CC', 'price' => 70000, 'min_stock' => 1],
            ['code' => '06455K59A71', 'name' => 'KANVAS REM DEPAN', 'price' => 96000, 'min_stock' => 1],
            ['code' => '06455KREK02', 'name' => 'KANVAS REM DEPAN', 'price' => 130000, 'min_stock' => 1],
            ['code' => '06455K84902', 'name' => 'KANVAS REM DEPAN', 'price' => 78000, 'min_stock' => 1],
            ['code' => '43130KZL930', 'name' => 'KANVAS REM BELAKANG', 'price' => 58500, 'min_stock' => 1],
            ['code' => '23100K1ABA0', 'name' => 'V-BELT ASSY', 'price' => 136000, 'min_stock' => 1],
            ['code' => '23100K1AN23', 'name' => 'V-BELT ONLY', 'price' => 103500, 'min_stock' => 1],
            ['code' => '23100K36BA0', 'name' => 'V-BELT ASSY', 'price' => 215000, 'min_stock' => 1],
            ['code' => '23100K44BA0', 'name' => 'V-BELT ASSY', 'price' => 171500, 'min_stock' => 1],
            ['code' => '22011K81N00', 'name' => 'PIECE SET SLIDE', 'price' => 21000, 'min_stock' => 1],
            ['code' => '22011KWN900', 'name' => 'PIECE SET SLIDE', 'price' => 28000, 'min_stock' => 1],
            ['code' => '06435K97N01', 'name' => 'KANVAS REM DEPAN', 'price' => 142500, 'min_stock' => 1],
            ['code' => 'IRC 70/9-1', 'name' => '17 -BAN DEPAN (Non Tubless)', 'price' => 228000, 'min_stock' => 1],
            ['code' => 'IRC 80/9-1', 'name' => '17-BAN BELAKANG (Non Tubless)', 'price' => 272000, 'min_stock' => 1],
            ['code' => 'IRC 70/9-2', 'name' => '17 - BAN DEPAN (Non Tubeles)', 'price' => 39900, 'min_stock' => 1],
            ['code' => 'IRC 80/9-2', 'name' => '17 - BAN BELAKANG (Non Tubeles)', 'price' => 39900, 'min_stock' => 1],
            ['code' => 'IRC 70/9-3', 'name' => '17 - BAN DEPAN (Tubless)', 'price' => 262000, 'min_stock' => 1],
            ['code' => 'IRC 80/9-3', 'name' => '17 - BAN BELAKANG (Tubless)', 'price' => 325000, 'min_stock' => 1],
            ['code' => 'IRC 80/9-4', 'name' => '14 - BAN DEPAN (Tubless)', 'price' => 295000, 'min_stock' => 1],
            ['code' => 'IRC 90/9', 'name' => '14 - BAN BELAKANG (Tubless)', 'price' => 330000, 'min_stock' => 1],
            ['code' => 'IRC 90/8', 'name' => '14 - BAN DEPAN (Tubless)', 'price' => 330000, 'min_stock' => 1],
            ['code' => 'IRC 100/8', 'name' => '14 - BAN BELAKANG (Tubless)', 'price' => 378000, 'min_stock' => 1],
            ['code' => 'IRC 100/9', 'name' => '12 - BAN DEPAN (Tubless)', 'price' => 325000, 'min_stock' => 1],
            ['code' => 'IRC 110/9', 'name' => '12 - BAN BELAKANG (Tubless)', 'price' => 380000, 'min_stock' => 1],
            ['code' => 'MAXXIS Victra 70/9', 'name' => '14 - BAN DEPAN (Tubless)', 'price' => 273000, 'min_stock' => 1],
            ['code' => 'MAXXIS Victra 80/9', 'name' => '14 - BAN DEPAN (Tubless)', 'price' => 340000, 'min_stock' => 1],
            ['code' => 'MAXXIS Victra 90/9', 'name' => '14 - BAN BELAKANG (Tubless)', 'price' => 417000, 'min_stock' => 1],
        ];
    }

    protected function serviceCatalogDefinitions(): array
    {
        return [
            ['code' => 'JSA-001', 'name' => 'Servis Berkala / Ringan', 'price' => 45000],
            ['code' => 'JSA-002', 'name' => 'Servis Injeksi / Throttle Body', 'price' => 60000],
            ['code' => 'JSA-003', 'name' => 'Servis CVT Lengkap', 'price' => 50000],
            ['code' => 'JSA-004', 'name' => 'Ganti Oli Mesin / Gear', 'price' => 10000],
            ['code' => 'JSA-005', 'name' => 'Ganti Kampas Rem', 'price' => 15000],
            ['code' => 'JSA-006', 'name' => 'Ganti Ban Outer/Inner', 'price' => 20000],
            ['code' => 'JSA-007', 'name' => 'Overhaul / Turun Mesin', 'price' => 350000],
            ['code' => 'JSA-008', 'name' => 'Press Velg / Segitiga', 'price' => 80000],
            ['code' => 'JSA-009', 'name' => 'Service Kelistrikan & Urut Kabel', 'price' => 75000],
            ['code' => 'JSA-010', 'name' => 'Ganti Air Radiator / Coolant', 'price' => 20000],
        ];
    }

    protected function customerVehicleDefinitions(): array
    {
        return [
            ['plate' => 'B 6431 JJH', 'engine' => 'KF41E2007743', 'frame' => 'MH1KF41264K007743', 'merk' => 'HONDA', 'tipe' => 'VARIO 150', 'stnk_name' => 'ADAN', 'name' => 'ADAN', 'address' => 'KARAWACI RESIDENCE', 'phone' => '0851 9955 8442'],
            ['plate' => 'B 6276 VKR', 'engine' => 'JFS2B1004435', 'frame' => 'MI11JFS210FK004383', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'YUNIZAR', 'name' => 'ARTIK MARDIANI', 'address' => 'ALFAMART DOTKOM', 'phone' => '0895 3862 22954'],
            ['plate' => 'F 6862 FJN', 'engine' => null, 'frame' => null, 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'JULIAN', 'name' => 'JULIAN', 'address' => 'ALFAMART DOTKOM', 'phone' => '0831 7188 4308'],
            ['plate' => 'B 6754 JEZ', 'engine' => 'JMD1E1488265', 'frame' => 'MH1JMD117RK488060', 'merk' => 'HONDA', 'tipe' => 'VARIO', 'stnk_name' => 'SAIPUL MIKDAR', 'name' => 'SAIPUL', 'address' => 'ALFAMART DOTKOM', 'phone' => '0812 1089 5184'],
            ['plate' => 'B 3710 CSG', 'engine' => 'JM81E2261061', 'frame' => 'MH1JM8128NK259302', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'RINA RIANA', 'name' => 'YUSUF', 'address' => 'ALFAMART DOTKOM', 'phone' => '0831 7690 2828'],
            ['plate' => 'B 6539 JUX', 'engine' => 'JMJ2E1121591', 'frame' => 'MH1JMJ110TR1215', 'merk' => 'HONDA', 'tipe' => 'VARIO', 'stnk_name' => 'AHMAD SURURI APIP', 'name' => 'APIP', 'address' => 'ALFAMART DOTKOM', 'phone' => '0857 1979 6025'],
            ['plate' => 'BB 6373 RG', 'engine' => 'JM04E115270', 'frame' => 'MH1JM0416PK152806', 'merk' => 'HONDA', 'tipe' => 'SCOOPY', 'stnk_name' => 'HAYATI MEI NORA', 'name' => 'MAHDI WAHYUDI', 'address' => 'ALFAMART DOTKOM', 'phone' => '0815 3434 0879'],
            ['plate' => 'B 5968 TOG', 'engine' => 'JMC1E1162243', 'frame' => 'MH1JMC116PK162225', 'merk' => 'HONDA', 'tipe' => 'VARIO', 'stnk_name' => 'FAISAL ARIF TARMIZI', 'name' => 'SUGENG', 'address' => 'ALFAMART DOTKOM', 'phone' => '0831 4641 6968'],
            ['plate' => 'B 6744 WHE', 'engine' => '1KP108725', 'frame' => 'MH31KP001CK109865', 'merk' => 'YAMAHA', 'tipe' => 'MIO', 'stnk_name' => 'ROHMAWATI', 'name' => 'CAHYANI', 'address' => 'ALFAMART DOTKOM', 'phone' => '0896 4780 3216'],
            ['plate' => 'A 5809 WAH', 'engine' => 'JM81E2055244', 'frame' => 'MH12JM6820NK063533', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'AHMAD JUHANI', 'name' => 'AHMAD JUHANI', 'address' => 'ALFAMART DOTKOM', 'phone' => '0831 3343 7000'],
            ['plate' => 'B 3192 CSU', 'engine' => 'JM91E2944709', 'frame' => 'MH1JM9123PK946857', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'NURHETTY BR SIMAMORA', 'name' => 'ARDANA', 'address' => 'ALFAMART DOTKOM', 'phone' => '0895 3291 54158'],
            ['plate' => 'Z 3772 TAG', 'engine' => 'JM31E3334085', 'frame' => 'MH1JM3134LK338720', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'MUSLIHUDIN', 'name' => 'MUSLIH', 'address' => 'ALFAMART DOTKOM', 'phone' => '0813 1313 3874'],
            ['plate' => 'B 3678 SUV', 'engine' => 'KF11E1220004', 'frame' => 'MH1KE1119FK213847', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'FACHRUL RIZAL', 'name' => 'FAHRUL', 'address' => 'ALFAMART DOTKOM', 'phone' => '0896 7769 2889'],
            ['plate' => 'B 6495 JYW', 'engine' => 'JM02E1488067', 'frame' => 'MH1JM0216MK487918', 'merk' => 'HONDA', 'tipe' => 'SCOOPY', 'stnk_name' => 'NURUL IMAM', 'name' => 'NURUL IMAM', 'address' => 'ALFAMART DOTKOM', 'phone' => '0895 6157 34453'],
            ['plate' => 'A 5549 WAY', 'engine' => 'JM91E2885801', 'frame' => 'MH1JM9126PK888016', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'HAPSAH', 'name' => 'HAPSAH', 'address' => 'ALFAMART DOTKOM', 'phone' => '0856 9373 5207'],
            ['plate' => 'B 3974 CUH', 'engine' => 'JM91E3593996', 'frame' => 'MH1JM9136RK597138', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'RIVAN NUR FADILLAH', 'name' => 'RIVAN', 'address' => 'ALFAMART DOTKOM', 'phone' => '0896 3695 2523'],
            ['plate' => 'B 3122 CAB', 'engine' => 'JM54P1129666', 'frame' => 'MH354P20FEJ1296', 'merk' => 'YAMAHA', 'tipe' => 'MIO J', 'stnk_name' => 'FADLIN.D', 'name' => 'FADLIN.D', 'address' => 'ALFAMART DOTKOM', 'phone' => '0878 2518 7112'],
            ['plate' => 'B 3720 CYT', 'engine' => 'JMK1E1062658', 'frame' => 'MH1JMK111TK062554', 'merk' => 'HONDA', 'tipe' => 'VARIO', 'stnk_name' => 'EGI KOSWARA', 'name' => 'EGI', 'address' => 'ALFAMART DOTKOM', 'phone' => '0896 3099 0574'],
            ['plate' => 'B 6509 ZJH', 'engine' => 'JFU1E1595974', 'frame' => 'MH1JFU117GK595438', 'merk' => 'HONDA', 'tipe' => 'VARIO', 'stnk_name' => 'MUHAMMAD ROHIM', 'name' => 'MUHAMMAD ROHIM', 'address' => 'ALFAMART DOTKOM', 'phone' => '0896 9608 9165'],
            ['plate' => 'B 3718 EPK', 'engine' => 'E3R2E2615680', 'frame' => 'MH3SEE410KJ106267', 'merk' => 'YAMAHA', 'tipe' => 'MIO', 'stnk_name' => 'SAMADI', 'name' => 'SAMADI', 'address' => 'ALFAMART DOTKOM', 'phone' => '0821 1499 8711'],
            ['plate' => 'B 6981 EUQ', 'engine' => '44D119458', 'frame' => 'MH344D001BK119479', 'merk' => 'YAMAHA', 'tipe' => 'XEON', 'stnk_name' => 'HAMDANI', 'name' => 'INDRA PRASITIO', 'address' => 'ALFAMART DOTKOM', 'phone' => '0813 2188 6933'],
            ['plate' => 'B 3950 TWM', 'engine' => 'JFF1E1161207', 'frame' => 'MH1JFF11XDK162770', 'merk' => 'HONDA', 'tipe' => 'VARIO', 'stnk_name' => 'MUHAMMAD SANUSI', 'name' => 'MUHAMMAD SANUSI', 'address' => 'ALFAMART DOTKOM', 'phone' => '0895 3573 25958'],
            ['plate' => 'B 6007 JJP', 'engine' => 'AE541D563139', 'frame' => 'MH81B11ANPJ16790', 'merk' => 'SUZUKI', 'tipe' => 'SUZUKI NEX', 'stnk_name' => 'DEDE', 'name' => 'DEDE', 'address' => 'JL. DASANA', 'phone' => '0857 7656 7653'],
            ['plate' => 'B 6797 ZVE', 'engine' => 'JM91E3709542', 'frame' => 'MH1JM9131RK713667', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'M DANDIH', 'name' => 'DANDI', 'address' => 'ALFAMART DOTKOM', 'phone' => '0819 0507 0142'],
            ['plate' => 'B 3614 EZD', 'engine' => 'JM81E2336244', 'frame' => 'MH1JM8126PK334761', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'SITI JULAEHA', 'name' => 'BINTANG', 'address' => 'ALFAMART DOTKOM', 'phone' => '0812 1046 5436'],
            ['plate' => 'F 6647 FDQ', 'engine' => 'JFZ1F3101188', 'frame' => 'MH1JFZ134KK101169', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'ROHIM', 'name' => 'ROHIM', 'address' => 'ALFAMART DOTKOM', 'phone' => '0877 4587 1885'],
            ['plate' => 'T 2481 LS', 'engine' => 'JFD2E2587640', 'frame' => 'MH1JFD226DK597362', 'merk' => 'HONDA', 'tipe' => 'BEAT', 'stnk_name' => 'BUDI AHMAD MAHMUDI', 'name' => 'ARDIANSYAH', 'address' => 'ALFAMART DOTKOM', 'phone' => '0858 1303 9852'],
            ['plate' => 'B 3044 EUP', 'engine' => 'JBC2E1605130', 'frame' => 'MH1JB9126PK888016', 'merk' => 'HONDA', 'tipe' => 'REVO', 'stnk_name' => 'MUHAMMAD AGIL', 'name' => 'MUHAMMAD AGIL', 'address' => 'ALFAMART DOTKOM', 'phone' => '0819 2785 4938'],
            ['plate' => 'B 5735 BLT', 'engine' => null, 'frame' => null, 'merk' => 'YAMAHA', 'tipe' => 'MIO J', 'stnk_name' => 'ERIK', 'name' => 'ERIK', 'address' => 'JL.DASANA INDAH', 'phone' => '0858 8231 2576'],
            ['plate' => 'B 6076 JSN', 'engine' => null, 'frame' => null, 'merk' => 'HONDA', 'tipe' => 'VARIO 125 CBS', 'stnk_name' => 'PUTRA NOTO', 'name' => 'PUTRA NOTO', 'address' => 'JL.DASANA INDAH', 'phone' => '0895 3208 51268'],
            ['plate' => 'B 2222 WB', 'engine' => null, 'frame' => null, 'merk' => 'YAMAHA', 'tipe' => 'MIO SOUL GT', 'stnk_name' => 'NANDA', 'name' => 'NANDA', 'address' => 'KARAWACI', 'phone' => '0852 2227 7227'],
        ];
    }
}
