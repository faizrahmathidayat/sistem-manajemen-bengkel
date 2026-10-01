<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StockAdjustmentImportTest extends TestCase
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

    protected function makeUploadedXlsx(array $rows, array $headings = ['Kode Sparepart', 'Qty Fisik', 'Alasan']): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // strictNullComparison=true so a literal 0 is written as a real zero, not an empty cell.
        $sheet->fromArray($headings, null, 'A1', true);
        $sheet->fromArray($rows, null, 'A2', true);

        $path = tempnam(sys_get_temp_dir(), 'stock_adjustment_import') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function setUpBranchUser(): array
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'stock_adjustment.create');

        return [$branch, $user];
    }

    protected function import(User $user, Branch $branch, UploadedFile $file)
    {
        return $this->actingAs($user)->postJson('/stock-adjustments/import-lines', [
            'branch_id' => $branch->id,
            'file' => $file,
        ]);
    }

    public function test_download_import_template_returns_xlsx_with_permission(): void
    {
        [, $user] = $this->setUpBranchUser();

        $response = $this->actingAs($user)->get('/stock-adjustments/import-template');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_download_import_template_forbidden_without_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/stock-adjustments/import-template')->assertForbidden();
    }

    public function test_import_returns_matched_lines_for_valid_file(): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $a = $this->makeSparepartBranch($branch, 'OLI-01');
        $b = $this->makeSparepartBranch($branch, 'KAM-01');

        $response = $this->import($user, $branch, $this->makeUploadedXlsx([
            ['OLI-01', 12, 'Hasil stock opname'],
            ['KAM-01', 0, 'Barang rusak'],
        ]));

        $response->assertOk();
        $response->assertExactJson(['lines' => [
            ['sparepart_branch_id' => $a->id, 'sparepart_code' => 'OLI-01', 'sparepart_name' => 'Sparepart OLI-01', 'physical_qty' => 12, 'reason' => 'Hasil stock opname'],
            ['sparepart_branch_id' => $b->id, 'sparepart_code' => 'KAM-01', 'sparepart_name' => 'Sparepart KAM-01', 'physical_qty' => 0, 'reason' => 'Barang rusak'],
        ]]);
    }

    public function test_blank_reason_defaults_to_dash(): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $this->makeSparepartBranch($branch, 'OLI-01');
        $this->makeSparepartBranch($branch, 'KAM-01');

        $response = $this->import($user, $branch, $this->makeUploadedXlsx([
            ['OLI-01', 5, null],
            ['KAM-01', 6, '   '],
        ]));

        $response->assertOk();
        $this->assertSame('-', $response->json('lines.0.reason'));
        $this->assertSame('-', $response->json('lines.1.reason'));
    }

    public function test_file_with_only_two_columns_is_accepted(): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $this->makeSparepartBranch($branch, 'OLI-01');

        $response = $this->import($user, $branch, $this->makeUploadedXlsx([['OLI-01', 5]], ['Kode Sparepart', 'Qty Fisik']));

        $response->assertOk();
        $this->assertSame('-', $response->json('lines.0.reason'));
    }

    public function test_unknown_inactive_or_other_branch_codes_are_rejected(): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $other = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makeSparepartBranch($branch, 'OFF-01', false);
        $this->makeSparepartBranch($other, 'BDG-01');

        $response = $this->import($user, $branch, $this->makeUploadedXlsx([
            ['NOPE', 1, null],
            ['OFF-01', 1, null],
            ['BDG-01', 1, null],
        ]));

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertCount(3, $errors);
        $this->assertStringContainsString('Baris 2', $errors[0]);
        $this->assertStringContainsString('NOPE', $errors[0]);
        $this->assertStringContainsString('Baris 4', $errors[2]);
    }

    /**
     * @dataProvider invalidQtyProvider
     */
    public function test_invalid_physical_qty_is_rejected($qty, string $expected): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $this->makeSparepartBranch($branch, 'OLI-01');

        $response = $this->import($user, $branch, $this->makeUploadedXlsx([['OLI-01', $qty, null]]));

        $response->assertStatus(422);
        $this->assertStringContainsString($expected, $response->json('errors.0'));
    }

    public function invalidQtyProvider(): array
    {
        return [
            'blank' => [null, 'Qty fisik harus diisi'],
            'text' => ['abc', 'harus berupa angka'],
            'decimal' => [2.5, 'bilangan bulat'],
            'negative' => [-1, 'tidak boleh negatif'],
        ];
    }

    public function test_reason_longer_than_255_characters_is_rejected(): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $this->makeSparepartBranch($branch, 'OLI-01');

        $response = $this->import($user, $branch, $this->makeUploadedXlsx([['OLI-01', 1, str_repeat('x', 256)]]));

        $response->assertStatus(422);
        $this->assertStringContainsString('255', $response->json('errors.0'));
    }

    public function test_duplicate_codes_in_file_are_rejected(): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $this->makeSparepartBranch($branch, 'OLI-01');

        $response = $this->import($user, $branch, $this->makeUploadedXlsx([['OLI-01', 1, null], ['OLI-01', 2, null]]));

        $response->assertStatus(422);
        $this->assertStringContainsString('Baris 3', $response->json('errors.0'));
        $this->assertStringContainsString('dobel', $response->json('errors.0'));
    }

    public function test_empty_file_is_rejected(): void
    {
        [$branch, $user] = $this->setUpBranchUser();

        $response = $this->import($user, $branch, $this->makeUploadedXlsx([]));

        $response->assertStatus(422);
        $this->assertStringContainsString('tidak berisi baris', $response->json('errors.0'));
    }

    public function test_more_than_100_rows_is_rejected(): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $this->makeSparepartBranch($branch, 'OLI-01');
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = ['OLI-01', 1, null];
        }

        $response = $this->import($user, $branch, $this->makeUploadedXlsx($rows));

        $response->assertStatus(422);
        $this->assertStringContainsString('melebihi batas maksimal 100', $response->json('errors.0'));
    }

    public function test_import_is_forbidden_without_permission_in_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-01');

        $response = $this->import(User::factory()->create(), $branch, $this->makeUploadedXlsx([['OLI-01', 1, null]]));

        $response->assertForbidden();
    }

    public function test_create_and_edit_pages_show_import_controls(): void
    {
        [$branch, $user] = $this->setUpBranchUser();

        $this->actingAs($user)->get('/stock-adjustments/create')
            ->assertOk()
            ->assertSee('Import Baris')
            ->assertSee(route('stock-adjustments.import-template'), false);
    }

    public function test_edit_page_shows_import_controls(): void
    {
        [$branch, $user] = $this->setUpBranchUser();
        $adjustment = StockAdjustment::create([
            'number' => 'SA-001', 'branch_id' => $branch->id, 'adjustment_date' => '2026-09-01',
            'reason' => 'Opname', 'status' => 'draft',
        ]);

        $this->actingAs($user)->get("/stock-adjustments/{$adjustment->id}/edit")
            ->assertOk()
            ->assertSee('Import Baris')
            ->assertSee(json_encode(route('stock-adjustments.import-lines')), false);
    }
}
