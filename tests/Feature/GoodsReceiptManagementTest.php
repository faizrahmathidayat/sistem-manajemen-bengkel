<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\GoodsReceiptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsReceiptManagementTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch, string $codeSuffix = ''): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => "OLI-01{$codeSuffix}", 'name' => 'Oli Mesin']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
    }

    protected function baseStorePayload(Branch $branch, SparepartBranch $sparepartBranch): array
    {
        return [
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
            'reference_number' => 'NOTA-001',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 10, 'purchase_price' => 40000],
            ],
        ];
    }

    public function test_store_creates_goods_receipt_with_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));

        $goodsReceipt = GoodsReceipt::first();
        $response->assertRedirect(route('goods-receipts.show', $goodsReceipt));
        $this->assertSame(GoodsReceiptStatus::DRAFT, $goodsReceipt->status);
        $this->assertStringStartsWith('PB/JKT/', $goodsReceipt->number);
        $this->assertCount(1, $goodsReceipt->lines);
        $this->assertSame(400000.0, (float) $goodsReceipt->lines->first()->line_total);

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(0.0, (float) $stock->on_hand_qty, 'Creating a DRAFT receipt must not touch stock.');
    }

    public function test_store_rejects_decimal_qty(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $payload = $this->baseStorePayload($branch, $sparepartBranch);
        $payload['lines'][0]['qty'] = 2.5;

        $response = $this->actingAs(User::find($user->id))->post('/goods-receipts', $payload);

        $response->assertSessionHasErrors(['lines.0.qty']);
        $this->assertSame(0, GoodsReceipt::count());
    }

    public function test_store_recomputes_line_total_server_side(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $payload = $this->baseStorePayload($branch, $sparepartBranch);
        $payload['lines'][0]['line_total'] = 999999;

        $this->actingAs(User::find($user->id))->post('/goods-receipts', $payload);

        $goodsReceipt = GoodsReceipt::first();
        $this->assertSame(400000.0, (float) $goodsReceipt->lines->first()->line_total);
    }

    public function test_store_is_forbidden_without_receipt_create_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));

        $response->assertForbidden();
    }

    public function test_store_rejects_a_receipt_with_no_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->post('/goods-receipts', [
            'branch_id' => $branch->id, 'receipt_date' => now()->format('Y-m-d'), 'lines' => [],
        ]);

        $response->assertSessionHasErrors(['lines']);
    }

    public function test_store_rejects_sparepart_from_a_different_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $otherSparepartBranch = $this->makeSparepartBranch($otherBranch, '-other');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $otherSparepartBranch));

        $response->assertSessionHasErrors(['lines.0.sparepart_branch_id']);
    }

    public function test_post_increases_stock_and_writes_ledger_entry(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response->assertRedirect(route('goods-receipts.show', $goodsReceipt));
        $goodsReceipt->refresh();
        $this->assertSame(GoodsReceiptStatus::POSTED, $goodsReceipt->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(10.0, (float) $stock->on_hand_qty);
        $movement = \DB::table('inventory_movements')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame(10.0, (float) $movement->qty_in);
        $this->assertSame(0.0, (float) $movement->qty_out);
        $this->assertSame(10.0, (float) $movement->balance_after);
        $this->assertSame('receipt', $movement->movement_type);
    }

    public function test_post_with_two_lines_of_different_spareparts_increases_both_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranchA = $this->makeSparepartBranch($branch, '-a');
        $sparepartBranchB = $this->makeSparepartBranch($branch, '-b');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', [
            'branch_id' => $branch->id,
            'receipt_date' => now()->format('Y-m-d'),
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranchB->id, 'qty' => 5, 'purchase_price' => 20000],
                ['sparepart_branch_id' => $sparepartBranchA->id, 'qty' => 8, 'purchase_price' => 15000],
            ],
        ]);
        $goodsReceipt = GoodsReceipt::first();

        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $stockA = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchA->id)->first();
        $stockB = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranchB->id)->first();
        $this->assertSame(8.0, (float) $stockA->on_hand_qty);
        $this->assertSame(5.0, (float) $stockB->on_hand_qty);
        $this->assertSame(2, \DB::table('inventory_movements')->count());
    }

    public function test_post_is_forbidden_without_receipt_post_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response->assertForbidden();
    }

    public function test_post_is_forbidden_for_a_non_draft_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response->assertForbidden();
    }

    public function test_cancel_from_draft_sets_cancelled_with_no_stock_impact(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/cancel");

        $response->assertRedirect(route('goods-receipts.show', $goodsReceipt));
        $goodsReceipt->refresh();
        $this->assertSame(GoodsReceiptStatus::CANCELLED, $goodsReceipt->status);
        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(0.0, (float) $stock->on_hand_qty);
        $this->assertSame(0, \DB::table('inventory_movements')->count());
    }

    public function test_cancel_is_forbidden_for_a_posted_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response = $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/cancel");

        $response->assertForbidden();
    }

    public function test_update_is_forbidden_for_a_posted_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response = $this->actingAs(User::find($user->id))->put("/goods-receipts/{$goodsReceipt->id}", [
            'receipt_date' => now()->format('Y-m-d'),
            'lines' => [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'purchase_price' => 1000]],
        ]);

        $response->assertForbidden();
    }

    public function test_create_form_renders_for_a_user_with_receipt_create_in_some_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->get('/goods-receipts/create');

        $response->assertOk();
    }

    public function test_create_form_shows_line_column_headers_and_marks_qty_and_price_required(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->get('/goods-receipts/create');

        $response->assertOk();
        $content = $response->getContent();

        $response->assertSee('<div class="col-md-5">Sparepart</div>', false);
        $response->assertSee('<div class="col-md-3">Qty</div>', false);
        $response->assertSee('<div class="col-md-3">Harga HPP</div>', false);

        preg_match('/<input[^>]*goods-receipt-qty[^>]*>/', $content, $qtyMatch);
        $this->assertNotEmpty($qtyMatch, 'Input qty baris sparepart tidak ditemukan.');
        $this->assertStringContainsString('required', $qtyMatch[0]);

        preg_match('/<input[^>]*goods-receipt-purchase-price[^>]*>/', $content, $priceMatch);
        $this->assertNotEmpty($priceMatch, 'Input harga satuan baris sparepart tidak ditemukan.');
        $this->assertStringContainsString('required', $priceMatch[0]);
    }

    public function test_edit_form_shows_line_column_headers_and_marks_qty_and_price_required(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $goodsReceipt = \App\Models\GoodsReceipt::create([
            'number' => 'GR-TEST-1', 'branch_id' => $branch->id, 'receipt_date' => now()->toDateString(),
            'status' => GoodsReceiptStatus::DRAFT,
        ]);
        \App\Models\GoodsReceiptLine::create([
            'goods_receipt_id' => $goodsReceipt->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'qty' => 5, 'purchase_price' => 1000, 'line_total' => 5000, 'sort_order' => 0,
        ]);

        $response = $this->actingAs(User::find($user->id))->get("/goods-receipts/{$goodsReceipt->id}/edit");

        $response->assertOk();
        $content = $response->getContent();

        $response->assertSee('<div class="col-md-5">Sparepart</div>', false);
        $response->assertSee('<div class="col-md-3">Qty</div>', false);
        $response->assertSee('<div class="col-md-3">Harga HPP</div>', false);

        preg_match('/<input[^>]*goods-receipt-qty[^>]*>/', $content, $qtyMatch);
        $this->assertNotEmpty($qtyMatch, 'Input qty baris sparepart tidak ditemukan.');
        $this->assertStringContainsString('required', $qtyMatch[0]);

        preg_match('/<input[^>]*goods-receipt-purchase-price[^>]*>/', $content, $priceMatch);
        $this->assertNotEmpty($priceMatch, 'Input harga satuan baris sparepart tidak ditemukan.');
        $this->assertStringContainsString('required', $priceMatch[0]);
    }

    public function test_create_form_replays_old_lines_after_a_validation_error(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $payload = [
            'branch_id' => $branch->id,
            // receipt_date deliberately omitted so validation fails, while
            // 'lines' remains present and valid so old('lines') is non-empty
            // on the redirected-back create form.
            'reference_number' => 'NOTA-001',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 7, 'purchase_price' => 40000],
            ],
        ];

        $this->actingAs(User::find($user->id))
            ->from(route('goods-receipts.create'))
            ->post('/goods-receipts', $payload)
            ->assertSessionHasErrors(['receipt_date']);

        $response = $this->actingAs(User::find($user->id))->get(route('goods-receipts.create'));

        $response->assertOk();
        $response->assertSee('oldLines', false);
        $response->assertSee((string) $sparepartBranch->id, false);
        $response->assertSee('"qty":7', false);
    }

    public function test_edit_form_renders_for_a_user_with_receipt_create_on_a_draft_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->get("/goods-receipts/{$goodsReceipt->id}/edit");

        $response->assertOk();
    }

    public function test_update_successfully_replaces_lines_and_recomputes_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $updatedSparepartBranch = $this->makeSparepartBranch($branch, '-updated');
        $user = User::find(User::factory()->create()->id);
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->actingAs($user)->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs($user)->put("/goods-receipts/{$goodsReceipt->id}", [
            'receipt_date' => now()->format('Y-m-d'),
            'reference_number' => 'NOTA-001',
            'lines' => [
                ['sparepart_branch_id' => $updatedSparepartBranch->id, 'qty' => 3, 'purchase_price' => 25000],
            ],
        ]);

        $response->assertRedirect(route('goods-receipts.show', $goodsReceipt));
        $goodsReceipt->refresh();
        $this->assertCount(1, $goodsReceipt->lines);
        $this->assertSame($updatedSparepartBranch->id, $goodsReceipt->lines->first()->sparepart_branch_id);
        $this->assertSame(75000.0, (float) $goodsReceipt->lines->first()->line_total);

        $oldStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $newStock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $updatedSparepartBranch->id)->first();
        $this->assertSame(0.0, (float) $oldStock->on_hand_qty, 'Updating a DRAFT receipt must never touch stock.');
        $this->assertSame(0.0, (float) $newStock->on_hand_qty, 'Updating a DRAFT receipt must never touch stock.');
    }

    public function test_update_can_change_header_fields(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::find(User::factory()->create()->id);
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->actingAs($user)->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $this->actingAs($user)->put("/goods-receipts/{$goodsReceipt->id}", [
            'receipt_date' => now()->format('Y-m-d'),
            'reference_number' => 'NOTA-UPDATED',
            'notes' => 'Catatan diperbarui',
            'lines' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 10, 'purchase_price' => 40000],
            ],
        ]);

        $goodsReceipt->refresh();
        $this->assertSame('NOTA-UPDATED', $goodsReceipt->reference_number);
        $this->assertSame('Catatan diperbarui', $goodsReceipt->notes);
    }

    public function test_index_lists_receipts_for_authorized_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepartBranchA = $this->makeSparepartBranch($branchA, '-a');
        $sparepartBranchB = $this->makeSparepartBranch($branchB, '-b');
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'receipt.view');
        $this->grantBranchPermission($user, $branchA, 'receipt.create');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branchA, $sparepartBranchA));
        $this->grantBranchPermission($user, $branchB, 'receipt.create');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branchB, $sparepartBranchB));

        $response = $this->actingAs(User::find($user->id))->get('/goods-receipts');

        $response->assertOk();
        $receiptA = GoodsReceipt::where('branch_id', $branchA->id)->first();
        $receiptB = GoodsReceipt::where('branch_id', $branchB->id)->first();
        $response->assertSee($receiptA->number);
        $response->assertDontSee($receiptB->number);
    }

    public function test_index_filters_by_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $draft = GoodsReceipt::create([
            'number' => 'GR-DRAFT-TEST', 'branch_id' => $branch->id, 'receipt_date' => now()->toDateString(),
            'status' => GoodsReceiptStatus::DRAFT,
        ]);
        $posted = GoodsReceipt::create([
            'number' => 'GR-POSTED-TEST', 'branch_id' => $branch->id, 'receipt_date' => now()->toDateString(),
            'status' => GoodsReceiptStatus::POSTED,
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.view');

        $response = $this->actingAs($user)->get('/goods-receipts?status=' . GoodsReceiptStatus::POSTED);

        $response->assertOk();
        $response->assertSee($posted->number);
        $response->assertDontSee($draft->number);
    }

    public function test_index_shows_no_access_page_without_any_receipt_view_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/goods-receipts');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_shows_empty_state_when_no_receipts_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.view');

        $response = $this->actingAs(User::find($user->id))->get('/goods-receipts');

        $response->assertOk();
        $response->assertSee('Belum ada penerimaan barang');
    }

    public function test_show_renders_post_and_cancel_buttons_for_a_draft_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.view');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->get("/goods-receipts/{$goodsReceipt->id}");

        $response->assertOk();
        $response->assertSee(route('goods-receipts.post', $goodsReceipt), false);
        $response->assertSee(route('goods-receipts.cancel', $goodsReceipt), false);
    }

    public function test_show_hides_post_and_cancel_buttons_for_a_posted_receipt(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->grantBranchPermission($user, $branch, 'receipt.view');
        $this->grantBranchPermission($user, $branch, 'receipt.post');
        $this->grantBranchPermission($user, $branch, 'receipt.cancel');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();
        $this->actingAs(User::find($user->id))->patch("/goods-receipts/{$goodsReceipt->id}/post");

        $response = $this->actingAs(User::find($user->id))->get("/goods-receipts/{$goodsReceipt->id}");

        $response->assertOk();
        $response->assertSee('Diposting');
        $response->assertDontSee(route('goods-receipts.post', $goodsReceipt), false);
        $response->assertDontSee(route('goods-receipts.cancel', $goodsReceipt), false);
    }

    public function test_create_page_loads_select2_for_sparepart_picker(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');

        $response = $this->actingAs(User::find($user->id))->get('/goods-receipts/create');

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
    }

    public function test_edit_page_loads_select2_for_sparepart_picker(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'receipt.create');
        $this->actingAs(User::find($user->id))->post('/goods-receipts', $this->baseStorePayload($branch, $sparepartBranch));
        $goodsReceipt = GoodsReceipt::first();

        $response = $this->actingAs(User::find($user->id))->get("/goods-receipts/{$goodsReceipt->id}/edit");

        $response->assertOk();
        $response->assertSee('select2-ajax-picker.js', false);
    }
}
