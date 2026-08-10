<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\ServiceCatalog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicServiceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_mechanic_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $mechanic = Mechanic::create([
            'name' => 'Agus Setiawan',
            'phone' => '081234567890',
            'nip' => 'NIP-001',
            'join_date' => '2020-01-15',
        ]);

        $this->assertSame('Agus Setiawan', $mechanic->name);
        $this->assertSame('NIP-001', $mechanic->nip);
        $this->assertTrue($mechanic->is_active);
        $this->assertSame($user->id, $mechanic->created_by);
    }

    public function test_mechanic_join_date_is_cast_to_a_date(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'join_date' => '2020-01-15']);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $mechanic->join_date);
        $this->assertSame('2020-01-15', $mechanic->join_date->format('Y-m-d'));
    }

    public function test_mechanic_display_label_combines_nip_and_name(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);

        $this->assertSame('MEK-001 - Agus Setiawan', $mechanic->display_label);
    }

    public function test_mechanic_display_label_falls_back_to_name_when_nip_is_null(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);

        $this->assertSame('Agus Setiawan', $mechanic->display_label);
    }

    public function test_mechanic_branches_rejects_duplicate_pair(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);

        $this->expectException(QueryException::class);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
    }

    public function test_deleting_mechanic_cascades_to_mechanic_branches(): void
    {
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);

        $mechanic->delete();

        $this->assertDatabaseMissing('mechanic_branches', ['mechanic_id' => $mechanic->id]);
    }

    public function test_service_catalog_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $service = ServiceCatalog::create([
            'code' => 'GANTI-OLI',
            'name' => 'Ganti Oli',
            'default_price' => 75000,
        ]);

        $this->assertSame('Ganti Oli', $service->name);
        $this->assertEquals(75000, $service->default_price);
        $this->assertTrue($service->is_active);
        $this->assertSame($user->id, $service->created_by);
    }

    public function test_service_catalog_code_is_unique(): void
    {
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli', 'default_price' => 75000]);

        $this->expectException(QueryException::class);
        ServiceCatalog::create(['code' => 'GANTI-OLI', 'name' => 'Ganti Oli Duplikat', 'default_price' => 50000]);
    }
}
