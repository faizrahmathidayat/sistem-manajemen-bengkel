<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Support\WhatsAppInvoiceLinkBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppInvoiceLinkBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function makeInvoice(array $customerOverrides = []): Invoice
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(array_merge([
            'customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso',
            'phone' => '0851 9955 8442',
        ], $customerOverrides));
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000]],
            'spareparts' => [],
        ]);
        (new InvoiceService())->postInvoice($invoice);

        return $invoice->fresh(['customer', 'branch']);
    }

    public function test_format_phone_converts_leading_zero_to_country_code(): void
    {
        $this->assertSame('6285199558442', WhatsAppInvoiceLinkBuilder::formatPhone('0851 9955 8442'));
    }

    public function test_format_phone_keeps_already_prefixed_number(): void
    {
        $this->assertSame('6285199558442', WhatsAppInvoiceLinkBuilder::formatPhone('62 851 9955 8442'));
    }

    public function test_format_phone_returns_null_for_empty_input(): void
    {
        $this->assertNull(WhatsAppInvoiceLinkBuilder::formatPhone(null));
        $this->assertNull(WhatsAppInvoiceLinkBuilder::formatPhone(''));
    }

    public function test_message_contains_all_expected_placeholders_with_correct_values(): void
    {
        $invoice = $this->makeInvoice();

        $message = WhatsAppInvoiceLinkBuilder::message($invoice);

        $this->assertStringContainsString('Halo Budi Santoso,', $message);
        $this->assertStringContainsString('Berikut invoice dari Cabang Jakarta.', $message);
        $this->assertStringContainsString("No. Invoice : {$invoice->number}", $message);
        $this->assertStringContainsString('Total : Rp 50.000', $message);
        $this->assertStringContainsString(route('public-invoices.show', $invoice), $message);
        $this->assertStringContainsString("pin : {$invoice->pin}", $message);
    }

    public function test_build_returns_wa_me_url_with_encoded_message(): void
    {
        $invoice = $this->makeInvoice();

        $link = WhatsAppInvoiceLinkBuilder::build($invoice);

        $this->assertStringStartsWith('https://wa.me/6285199558442?text=', $link);
        $this->assertStringContainsString(urlencode($invoice->number), $link);
    }

    public function test_build_returns_null_when_customer_has_no_phone(): void
    {
        $invoice = $this->makeInvoice(['phone' => null]);

        $this->assertNull(WhatsAppInvoiceLinkBuilder::build($invoice));
    }

    public function test_build_returns_null_when_invoice_has_no_hash_id_or_pin(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->forceFill(['hash_id' => null, 'pin' => null])->save();

        $this->assertNull(WhatsAppInvoiceLinkBuilder::build($invoice->fresh(['customer', 'branch'])));
    }
}
