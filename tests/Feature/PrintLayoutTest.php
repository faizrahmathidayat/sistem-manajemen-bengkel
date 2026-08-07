<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_print_layout_renders_title_filter_summary_and_table_sections(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();

        $rendered = $this->actingAs($user)->blade(
            '@extends("layouts.print")
            @section("report-title", "Laporan Uji Coba")
            @section("filter-summary", "Cabang: Jakarta")
            @section("table")<table class="print-table"><tr><td>Baris Uji</td></tr></table>@endsection'
        );

        $rendered->assertSee('Sistem Manajemen Bengkel');
        $rendered->assertSee('Laporan Uji Coba');
        $rendered->assertSee('Cabang: Jakarta');
        $rendered->assertSee('Baris Uji');
        $rendered->assertSee($user->name);
    }

    public function test_cap_rows_truncates_collections_over_the_limit_and_reports_truncation(): void
    {
        $subject = new class {
            use \App\Http\Controllers\Concerns\HandlesReportExport;

            public function run(\Illuminate\Support\Collection $rows, int $limit)
            {
                return $this->capRows($rows, $limit);
            }
        };

        $rows = collect(range(1, 1001));

        [$result, $truncated] = $subject->run($rows, 1000);

        $this->assertTrue($truncated);
        $this->assertCount(1000, $result);
        $this->assertSame(1, $result->first());
        $this->assertSame(1000, $result->last());
    }

    public function test_cap_rows_does_not_truncate_collections_at_or_under_the_limit(): void
    {
        $subject = new class {
            use \App\Http\Controllers\Concerns\HandlesReportExport;

            public function run(\Illuminate\Support\Collection $rows, int $limit)
            {
                return $this->capRows($rows, $limit);
            }
        };

        $rows = collect(range(1, 1000));

        [$result, $truncated] = $subject->run($rows, 1000);

        $this->assertFalse($truncated);
        $this->assertCount(1000, $result);
    }
}
