<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ServiceCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    protected function grantPermission(User $user, string $code): void
    {
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
    }

    protected function makeUploadedXlsx(array $rows, array $headings = ['Kode Jasa', 'Nama Jasa', 'Harga Default']): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headings, null, 'A1', true);
        $sheet->fromArray($rows, null, 'A2', true);

        $path = tempnam(sys_get_temp_dir(), 'service_import') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_download_import_template_returns_xlsx_with_permission(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $response = $this->actingAs($user)->get('/service-catalogs/import-template');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_download_import_template_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/service-catalogs/import-template');

        $response->assertForbidden();
    }

    public function test_import_page_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/service-catalogs/import');

        $response->assertForbidden();
    }

    public function test_import_page_is_visible_with_permission(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $response = $this->actingAs($user)->get('/service-catalogs/import');

        $response->assertOk();
        $response->assertSee('Import Jasa Service');
    }

    public function test_import_lines_returns_parsed_lines_for_valid_file(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $file = $this->makeUploadedXlsx([
            ['SVC-01', 'Ganti Oli', 50000],
        ]);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertOk();
        $response->assertJson([
            'lines' => [
                ['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000.0],
            ],
        ]);
    }

    public function test_import_lines_rejects_duplicate_code_within_file(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $file = $this->makeUploadedXlsx([
            ['SVC-01', 'Ganti Oli', 50000],
            ['SVC-01', 'Servis Rem', 40000],
        ]);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 3: Kode jasa "SVC-01" duplikat dengan baris 2.']]);
    }

    public function test_import_lines_rejects_code_that_already_exists(): void
    {
        ServiceCatalog::create(['code' => 'SVC-01', 'name' => 'Sudah Ada', 'default_price' => 10000]);
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $file = $this->makeUploadedXlsx([
            ['SVC-01', 'Ganti Oli', 50000],
        ]);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Kode jasa "SVC-01" sudah digunakan.']]);
    }

    public function test_import_lines_rejects_missing_name(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $file = $this->makeUploadedXlsx([
            ['SVC-01', '', 50000],
        ]);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Nama jasa harus diisi.']]);
    }

    public function test_import_lines_rejects_missing_price(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $file = $this->makeUploadedXlsx([
            ['SVC-01', 'Ganti Oli', null],
        ]);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Harga default harus diisi.']]);
    }

    public function test_import_lines_rejects_non_numeric_price(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $file = $this->makeUploadedXlsx([
            ['SVC-01', 'Ganti Oli', 'mahal'],
        ]);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Harga default harus berupa angka.']]);
    }

    public function test_import_lines_rejects_negative_price(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $file = $this->makeUploadedXlsx([
            ['SVC-01', 'Ganti Oli', -1000],
        ]);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Baris 2: Harga default tidak boleh negatif.']]);
    }

    public function test_import_lines_rejects_file_with_more_than_100_rows(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = ['SVC-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'Jasa ' . $i, 10000];
        }
        $file = $this->makeUploadedXlsx($rows);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['errors' => ['Jumlah baris (101) melebihi batas maksimal 100 baris.']]);
    }

    public function test_import_lines_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $file = $this->makeUploadedXlsx([
            ['SVC-01', 'Ganti Oli', 50000],
        ]);

        $response = $this->actingAs($user)->post('/service-catalogs/import-lines', [
            'file' => $file,
        ]);

        $response->assertForbidden();
    }

    public function test_store_bulk_creates_service_catalogs(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $response = $this->actingAs($user)->post('/service-catalogs/import', [
            'lines' => [
                ['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000],
                ['code' => 'SVC-02', 'name' => 'Servis Rem', 'default_price' => 40000],
            ],
        ]);

        $response->assertRedirect(route('service-catalogs.index'));
        $this->assertDatabaseHas('service_catalogs', ['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $this->assertDatabaseHas('service_catalogs', ['code' => 'SVC-02', 'name' => 'Servis Rem', 'default_price' => 40000]);
    }

    public function test_store_bulk_rejects_duplicate_code_within_submission(): void
    {
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $response = $this->actingAs($user)->post('/service-catalogs/import', [
            'lines' => [
                ['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000],
                ['code' => 'SVC-01', 'name' => 'Servis Rem', 'default_price' => 40000],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.0.code', 'lines.1.code']);
        $this->assertDatabaseMissing('service_catalogs', ['code' => 'SVC-01']);
    }

    public function test_store_bulk_rejects_code_that_already_exists(): void
    {
        ServiceCatalog::create(['code' => 'SVC-01', 'name' => 'Sudah Ada', 'default_price' => 10000]);
        $user = User::factory()->create();
        $this->grantPermission($user, 'service.create');

        $response = $this->actingAs($user)->post('/service-catalogs/import', [
            'lines' => [
                ['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000],
            ],
        ]);

        $response->assertSessionHasErrors(['lines.0.code']);
    }

    public function test_store_bulk_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/service-catalogs/import', [
            'lines' => [
                ['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('service_catalogs', ['code' => 'SVC-01']);
    }
}
