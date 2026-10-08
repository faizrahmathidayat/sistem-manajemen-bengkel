<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SparepartAddExistingBulkTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::firstOrCreate(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makeUploadedXlsx(array $rows, array $headings = ['Kode Sparepart', 'Kode Rak', 'Harga Jual', 'Stok Minimum']): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // strictNullComparison=true so a literal 0 stays a real zero instead of an empty cell.
        $sheet->fromArray(array_merge([$headings], $rows), null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'existing') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'existing.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @return array{0: User, 1: Branch} */
    protected function userWithCreateAccess(): array
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'SMG', 'name' => 'Cabang Semarang']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        return [User::find($user->id), $branch];
    }

    public function test_store_existing_attaches_many_spareparts_in_one_submit(): void
    {
        [$user, $branch] = $this->userWithCreateAccess();
        $rack = Rack::create(['code' => 'A1']);
        $a = Sparepart::create(['code' => 'A-01', 'name' => 'A']);
        $b = Sparepart::create(['code' => 'B-01', 'name' => 'B']);

        $response = $this->actingAs($user)->post('/sparepart-branches/existing', [
            'branch_id' => $branch->id,
            'lines' => [
                ['sparepart_id' => $a->id, 'rack_id' => $rack->id, 'selling_price' => 1000, 'minimum_stock' => 2],
                ['sparepart_id' => '', 'selling_price' => ''],
                ['sparepart_id' => $b->id, 'rack_id' => '', 'selling_price' => 2000, 'minimum_stock' => ''],
            ],
        ]);

        $response->assertRedirect('/sparepart-branches');
        $response->assertSessionHas('status', fn ($status) => str_contains($status, '2 sparepart berhasil ditambahkan'));
        $this->assertSame(2, SparepartBranch::where('branch_id', $branch->id)->count());
        $this->assertDatabaseHas('sparepart_branches', ['sparepart_id' => $a->id, 'branch_id' => $branch->id, 'rack_id' => $rack->id, 'selling_price' => 1000, 'minimum_stock' => 2]);
        $this->assertDatabaseHas('sparepart_branches', ['sparepart_id' => $b->id, 'branch_id' => $branch->id, 'rack_id' => null, 'selling_price' => 2000, 'minimum_stock' => 0]);
        $configB = SparepartBranch::where('sparepart_id', $b->id)->first();
        $this->assertDatabaseHas('sparepart_branch_stocks', ['sparepart_branch_id' => $configB->id, 'on_hand_qty' => 0]);
    }

    public function test_store_existing_is_all_or_nothing_when_one_line_is_already_configured(): void
    {
        [$user, $branch] = $this->userWithCreateAccess();
        $fresh = Sparepart::create(['code' => 'A-01', 'name' => 'A']);
        $taken = Sparepart::create(['code' => 'B-01', 'name' => 'B']);
        SparepartBranch::create(['sparepart_id' => $taken->id, 'branch_id' => $branch->id, 'selling_price' => 500]);

        $response = $this->actingAs($user)->post('/sparepart-branches/existing', [
            'branch_id' => $branch->id,
            'lines' => [
                ['sparepart_id' => $fresh->id, 'selling_price' => 1000],
                ['sparepart_id' => $taken->id, 'selling_price' => 2000],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.1.sparepart_id']);
        $this->assertSame(1, SparepartBranch::where('branch_id', $branch->id)->count());
    }

    public function test_store_existing_rejects_same_sparepart_twice_and_decimal_values(): void
    {
        [$user, $branch] = $this->userWithCreateAccess();
        $a = Sparepart::create(['code' => 'A-01', 'name' => 'A']);

        $this->actingAs($user)->post('/sparepart-branches/existing', [
            'branch_id' => $branch->id,
            'lines' => [
                ['sparepart_id' => $a->id, 'selling_price' => 1000],
                ['sparepart_id' => $a->id, 'selling_price' => 1000],
            ],
        ])->assertSessionHasErrors(['lines.0.sparepart_id', 'lines.1.sparepart_id']);

        $this->post('/sparepart-branches/existing', [
            'branch_id' => $branch->id,
            'lines' => [['sparepart_id' => $a->id, 'selling_price' => '1000.5', 'minimum_stock' => '1.5']],
        ])->assertSessionHasErrors(['lines.0.selling_price', 'lines.0.minimum_stock']);

        $this->assertSame(0, SparepartBranch::count());
    }

    public function test_store_existing_requires_at_least_one_line(): void
    {
        [$user, $branch] = $this->userWithCreateAccess();

        $this->actingAs($user)->post('/sparepart-branches/existing', [
            'branch_id' => $branch->id,
            'lines' => [['sparepart_id' => '', 'selling_price' => '']],
        ])->assertSessionHasErrors(['lines']);
    }

    public function test_store_existing_forbidden_without_create_permission(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'SMG', 'name' => 'Cabang Semarang']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $a = Sparepart::create(['code' => 'A-01', 'name' => 'A']);

        $this->actingAs(User::find($user->id))->post('/sparepart-branches/existing', [
            'branch_id' => $branch->id,
            'lines' => [['sparepart_id' => $a->id, 'selling_price' => 1000]],
        ])->assertForbidden();
    }

    public function test_import_lines_resolves_codes_to_existing_spareparts(): void
    {
        [$user, $branch] = $this->userWithCreateAccess();
        $rack = Rack::create(['code' => 'A1']);
        $a = Sparepart::create(['code' => 'A-01', 'name' => 'Alpha']);
        $b = Sparepart::create(['code' => 'B-01', 'name' => 'Beta']);

        $response = $this->actingAs($user)->post('/sparepart-branches/existing-import-lines', [
            'branch_id' => $branch->id,
            'file' => $this->makeUploadedXlsx([
                ['A-01', 'A1', 150000, 5],
                ['B-01', '', 90000, ''],
            ]),
        ]);

        $response->assertOk();
        $response->assertJson(['lines' => [
            ['sparepart_id' => $a->id, 'code' => 'A-01', 'name' => 'Alpha', 'rack_id' => $rack->id, 'selling_price' => 150000.0, 'minimum_stock' => 5.0],
            ['sparepart_id' => $b->id, 'code' => 'B-01', 'name' => 'Beta', 'rack_id' => null, 'selling_price' => 90000.0, 'minimum_stock' => 0.0],
        ]]);
    }

    public function test_import_lines_reports_every_row_problem(): void
    {
        [$user, $branch] = $this->userWithCreateAccess();
        Sparepart::create(['code' => 'OK-01', 'name' => 'Ok']);
        Sparepart::create(['code' => 'OFF-01', 'name' => 'Off', 'is_active' => false]);
        $taken = Sparepart::create(['code' => 'TAKEN-01', 'name' => 'Taken']);
        SparepartBranch::create(['sparepart_id' => $taken->id, 'branch_id' => $branch->id, 'selling_price' => 500]);

        $response = $this->actingAs($user)->post('/sparepart-branches/existing-import-lines', [
            'branch_id' => $branch->id,
            'file' => $this->makeUploadedXlsx([
                ['NOPE-01', '', 1000, 0],
                ['OFF-01', '', 1000, 0],
                ['TAKEN-01', '', 1000, 0],
                ['OK-01', 'ZZ', 1000, 0],
                ['OK-01', '', '', 0],
                ['OK-01', '', 1000, -1],
            ]),
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertCount(6, $errors);
        $this->assertStringContainsString('tidak ditemukan di master', $errors[0]);
        $this->assertStringContainsString('tidak aktif', $errors[1]);
        $this->assertStringContainsString('sudah terkonfigurasi', $errors[2]);
        $this->assertStringContainsString('Rak dengan kode "ZZ"', $errors[3]);
        $this->assertStringContainsString('Harga jual harus diisi', $errors[4]);
        $this->assertStringContainsString('Stok minimum tidak boleh negatif', $errors[5]);
    }

    public function test_import_lines_rejects_duplicate_codes_in_file(): void
    {
        [$user, $branch] = $this->userWithCreateAccess();
        Sparepart::create(['code' => 'A-01', 'name' => 'A']);

        $response = $this->actingAs($user)->post('/sparepart-branches/existing-import-lines', [
            'branch_id' => $branch->id,
            'file' => $this->makeUploadedXlsx([['A-01', '', 1000, 0], ['A-01', '', 2000, 0]]),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('duplikat dengan baris 2', $response->json('errors')[0]);
    }

    public function test_import_lines_forbidden_without_create_permission_and_template_downloads(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $user = User::find($user->id);

        $this->actingAs($user)->post('/sparepart-branches/existing-import-lines', [
            'branch_id' => $branch->id,
            'file' => $this->makeUploadedXlsx([['A-01', '', 1000, 0]]),
        ])->assertForbidden();
        $this->get('/sparepart-branches/existing-import-template')->assertForbidden();

        [$creator] = $this->userWithCreateAccess();
        $this->actingAs($creator)->get('/sparepart-branches/existing-import-template')->assertOk();
    }

    public function test_page_renders_line_editor_with_column_headers_and_replays_old_lines_with_labels(): void
    {
        [$user, $branch] = $this->userWithCreateAccess();
        $a = Sparepart::create(['code' => 'A-01', 'name' => 'Alpha']);
        $this->actingAs($user)->get('/sparepart-branches');

        $this->get('/sparepart-branches/create-existing')
            ->assertOk()
            ->assertSee('Tambah Baris')
            ->assertSee('Import Baris')
            ->assertSee('Stok Min.');

        $response = $this->withSession(['_old_input' => ['lines' => [['sparepart_id' => $a->id, 'selling_price' => '1000']]]])
            ->get('/sparepart-branches/create-existing');
        $response->assertSee('A-01 \u2014 Alpha', false);
    }
}
