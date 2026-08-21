<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Sparepart;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SparepartMasterImportTest extends TestCase
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
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makeUploadedXlsx(array $rows, array $headings = ['Kode Sparepart', 'Nama Sparepart', 'Kode Rak', 'Harga Jual', 'Stok Minimum']): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // strictNullComparison=true: fromArray()'s default loose comparison treats a
        // literal 0 as equal to the $nullValue marker (0 == null), silently writing an
        // empty cell instead of a real zero. See GoodsReceiptImportTest for the full
        // writeup of this PhpSpreadsheet footgun.
        $sheet->fromArray($headings, null, 'A1', true);
        $sheet->fromArray($rows, null, 'A2', true);

        $path = tempnam(sys_get_temp_dir(), 'sparepart_import') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_download_import_template_returns_xlsx_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $response = $this->actingAs($user)->get('/sparepart-branches/import-template');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_download_import_template_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/sparepart-branches/import-template');

        $response->assertForbidden();
    }

    public function test_import_page_shows_no_access_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/sparepart-branches/import');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_import_lines_returns_parsed_lines_for_valid_file(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $rack = Rack::create(['code' => 'A1']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $file = $this->makeUploadedXlsx([
            ['BAN-01', 'Ban Depan', 'A1', 150000, 5],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'lines' => [
                [
                    'code' => 'BAN-01',
                    'name' => 'Ban Depan',
                    'rack_id' => $rack->id,
                    'selling_price' => 150000.0,
                    'minimum_stock' => 5.0,
                ],
            ],
        ]);
    }

    public function test_import_lines_rejects_duplicate_code_within_file(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $file = $this->makeUploadedXlsx([
            ['BAN-01', 'Ban Depan', '', 150000, 0],
            ['BAN-01', 'Ban Belakang', '', 140000, 0],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 3: Kode sparepart "BAN-01" duplikat dengan baris 2.']]);
    }

    public function test_import_lines_rejects_code_that_already_exists(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        Sparepart::create(['code' => 'BAN-01', 'name' => 'Sudah Ada']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $file = $this->makeUploadedXlsx([
            ['BAN-01', 'Ban Depan', '', 150000, 0],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Kode sparepart "BAN-01" sudah digunakan.']]);
    }

    public function test_import_lines_rejects_missing_name(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $file = $this->makeUploadedXlsx([
            ['BAN-01', '', '', 150000, 0],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Nama sparepart harus diisi.']]);
    }

    public function test_import_lines_rejects_unknown_rack_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $file = $this->makeUploadedXlsx([
            ['BAN-01', 'Ban Depan', 'TIDAK-ADA', 150000, 0],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Rak dengan kode "TIDAK-ADA" tidak ditemukan atau tidak aktif.']]);
    }

    public function test_import_lines_rejects_missing_price(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $file = $this->makeUploadedXlsx([
            ['BAN-01', 'Ban Depan', '', null, 0],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Harga jual harus diisi.']]);
    }

    public function test_import_lines_rejects_non_numeric_price(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $file = $this->makeUploadedXlsx([
            ['BAN-01', 'Ban Depan', '', 'mahal', 0],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Harga jual harus berupa angka.']]);
    }

    public function test_import_lines_rejects_negative_minimum_stock(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $file = $this->makeUploadedXlsx([
            ['BAN-01', 'Ban Depan', '', 150000, -5],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Stok minimum tidak boleh negatif.']]);
    }

    public function test_import_lines_rejects_file_with_more_than_100_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = ['BAN-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'Ban ' . $i, '', 100000, 0];
        }
        $file = $this->makeUploadedXlsx($rows);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Jumlah baris (101) melebihi batas maksimal 100 baris.']]);
    }

    public function test_import_lines_is_forbidden_without_sparepart_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $file = $this->makeUploadedXlsx([
            ['BAN-01', 'Ban Depan', '', 150000, 0],
        ]);

        $response = $this->actingAs($user)->post('/sparepart-branches/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertForbidden();
    }

    public function test_store_bulk_creates_spareparts_and_branch_configs(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $rack = Rack::create(['code' => 'A1']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $response = $this->actingAs($user)->post('/sparepart-branches/import', [
            'branch_id' => $branch->id,
            'lines' => [
                ['code' => 'BAN-01', 'name' => 'Ban Depan', 'rack_id' => $rack->id, 'selling_price' => 150000, 'minimum_stock' => 5],
                ['code' => 'BAN-02', 'name' => 'Ban Belakang', 'rack_id' => '', 'selling_price' => 140000, 'minimum_stock' => ''],
            ],
        ]);

        $response->assertRedirect(route('sparepart-branches.index'));
        $this->assertDatabaseHas('spareparts', ['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $this->assertDatabaseHas('spareparts', ['code' => 'BAN-02', 'name' => 'Ban Belakang']);
        $this->assertDatabaseHas('sparepart_branches', ['branch_id' => $branch->id, 'rack_id' => $rack->id, 'selling_price' => 150000]);
        $this->assertDatabaseHas('sparepart_branches', ['branch_id' => $branch->id, 'rack_id' => null, 'selling_price' => 140000, 'minimum_stock' => 0]);
    }

    public function test_store_bulk_rejects_duplicate_code_within_submission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $response = $this->actingAs($user)->post('/sparepart-branches/import', [
            'branch_id' => $branch->id,
            'lines' => [
                ['code' => 'BAN-01', 'name' => 'Ban Depan', 'selling_price' => 150000],
                ['code' => 'BAN-01', 'name' => 'Ban Belakang', 'selling_price' => 140000],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.0.code', 'lines.1.code']);
        $this->assertDatabaseMissing('spareparts', ['code' => 'BAN-01']);
    }

    public function test_store_bulk_rejects_code_that_already_exists(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        Sparepart::create(['code' => 'BAN-01', 'name' => 'Sudah Ada']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $response = $this->actingAs($user)->post('/sparepart-branches/import', [
            'branch_id' => $branch->id,
            'lines' => [
                ['code' => 'BAN-01', 'name' => 'Ban Depan', 'selling_price' => 150000],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.0.code']);
    }

    public function test_store_bulk_is_forbidden_without_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/sparepart-branches/import', [
            'branch_id' => $branch->id,
            'lines' => [
                ['code' => 'BAN-01', 'name' => 'Ban Depan', 'selling_price' => 150000],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('spareparts', ['code' => 'BAN-01']);
    }
}
