<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_creates_an_audit_log_row_with_the_acting_user_and_request_context(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->actingAs($user);

        (new AuditLogger())->log(
            AuditEvent::INVOICE_POSTED,
            $branch->id,
            null,
            ['status' => 'draft'],
            ['status' => 'posted']
        );

        $log = AuditLog::first();
        $this->assertNotNull($log);
        $this->assertSame(AuditEvent::INVOICE_POSTED, $log->event);
        $this->assertSame($branch->id, $log->branch_id);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(['status' => 'draft'], $log->old_values);
        $this->assertSame(['status' => 'posted'], $log->new_values);
    }

    public function test_log_records_the_auditable_polymorphic_reference(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);

        (new AuditLogger())->log(AuditEvent::INVOICE_POSTED, $branch->id, $customer);

        $log = AuditLog::first();
        $this->assertSame(Customer::class, $log->auditable_type);
        $this->assertSame($customer->id, $log->auditable_id);
        $this->assertTrue($log->auditable->is($customer));
    }

    public function test_log_never_throws_even_when_the_write_itself_fails(): void
    {
        // A branch_id that doesn't exist violates audit_logs' own FK constraint —
        // a genuine DB-level failure, not a mock, to prove the try/catch is real.
        $nonExistentBranchId = 999999;

        (new AuditLogger())->log('test.event', $nonExistentBranchId, null);

        $this->assertSame(0, AuditLog::count());
    }
}
