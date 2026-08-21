<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
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

class GoodsReceiptImportTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch, string $code, bool $active = true): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => $code, 'name' => "Sparepart {$code}"]);

        return SparepartBranch::create([
            'sparepart_id' => $sparepart->id,
            'branch_id' => $branch->id,
            'selling_price' => 60000,
            'is_active' => $active,
        ]);
    }

    protected function makeUploadedXlsx(array $rows, array $headings = ['Kode Sparepart', 'Qty', 'Harga Satuan']): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // strictNullComparison=true is required here: fromArray()'s default loose
        // comparison treats a literal 0 in $rows as equal to the $nullValue marker
        // (PHP's `0 == null` is true), silently writing an empty cell instead of a
        // real zero — which would make the "qty is zero" test fixture indistinguishable
        // from "qty is blank" before it even reaches the code under test.
        $sheet->fromArray($headings, null, 'A1', true);
        $sheet->fromArray($rows, null, 'A2', true);

        $path = tempnam(sys_get_temp_dir(), 'goods_receipt_import') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_download_import_template_returns_xlsx_with_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs($user)->get('/goods-receipts/import-template');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_download_import_template_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/goods-receipts/import-template');

        $response->assertForbidden();
    }

    public function test_import_lines_returns_matched_lines_for_valid_file(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', 10, 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'lines' => [
                [
                    'sparepart_branch_id' => $sparepartBranch->id,
                    'sparepart_code' => 'OLI-01',
                    'sparepart_name' => 'Sparepart OLI-01',
                    'qty' => 10.0,
                    'purchase_price' => 25000.0,
                ],
            ],
        ]);
    }

    public function test_import_lines_rejects_unknown_sparepart_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['TIDAK-ADA', 10, 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Sparepart dengan kode "TIDAK-ADA" tidak ditemukan atau tidak aktif di cabang ini.']]);
    }

    public function test_import_lines_rejects_sparepart_from_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makeSparepartBranch($branchB, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', 10, 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branchA->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Sparepart dengan kode "OLI-01" tidak ditemukan atau tidak aktif di cabang ini.']]);
    }

    public function test_import_lines_rejects_inactive_sparepart(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01', false);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', 10, 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_import_lines_rejects_missing_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', null, 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Qty harus diisi.']]);
    }

    public function test_import_lines_rejects_non_numeric_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', 'sepuluh', 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Qty harus berupa angka.']]);
    }

    public function test_import_lines_rejects_zero_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', 0, 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Qty harus lebih besar dari 0.']]);
    }

    public function test_import_lines_rejects_negative_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', -5, 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Qty harus lebih besar dari 0.']]);
    }

    public function test_import_lines_rejects_missing_price(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', 10, null],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Harga satuan harus diisi.']]);
    }

    public function test_import_lines_rejects_non_numeric_price(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $file = $this->makeUploadedXlsx([
            ['OLI-01', 10, 'mahal'],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Harga satuan harus berupa angka.']]);
    }

    public function test_import_lines_rejects_file_with_more_than_100_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = ['OLI-01', 1, 1000];
        }
        $file = $this->makeUploadedXlsx($rows);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Jumlah baris (101) melebihi batas maksimal 100 baris.']]);
    }

    public function test_import_lines_accepts_exactly_100_rows(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = ['OLI-01', 1, 1000];
        }
        $file = $this->makeUploadedXlsx($rows);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertOk();
        $this->assertCount(100, $response->json('lines'));
    }

    public function test_import_lines_is_forbidden_without_receipt_create_permission_in_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');
        $user = User::factory()->create();

        $file = $this->makeUploadedXlsx([
            ['OLI-01', 10, 25000],
        ]);

        $response = $this->actingAs($user)->post('/goods-receipts/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);

        $response->assertForbidden();
    }
}
