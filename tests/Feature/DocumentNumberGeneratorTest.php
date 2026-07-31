<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Services\DocumentNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_sequential_formatted_numbers_per_branch_and_type(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $generator = new DocumentNumberGenerator();

        $first = $generator->next($branch, 'PKB');
        $second = $generator->next($branch, 'PKB');

        $period = now()->timezone(config('app.business_timezone', 'Asia/Jakarta'))->format('Ym');
        $this->assertSame("PKB/JKT/{$period}/00001", $first);
        $this->assertSame("PKB/JKT/{$period}/00002", $second);
    }

    public function test_sequences_are_isolated_per_branch(): void
    {
        $jakarta = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bandung = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $generator = new DocumentNumberGenerator();

        $generator->next($jakarta, 'PKB');
        $bandungFirst = $generator->next($bandung, 'PKB');

        $period = now()->timezone(config('app.business_timezone', 'Asia/Jakarta'))->format('Ym');
        $this->assertSame("PKB/BDG/{$period}/00001", $bandungFirst);
    }

    public function test_sequences_are_isolated_per_document_type(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $generator = new DocumentNumberGenerator();

        $generator->next($branch, 'PKB');
        $invoiceFirst = $generator->next($branch, 'INV');

        $period = now()->timezone(config('app.business_timezone', 'Asia/Jakarta'))->format('Ym');
        $this->assertSame("INV/JKT/{$period}/00001", $invoiceFirst);
    }

    public function test_it_does_not_swallow_a_non_duplicate_key_query_exception(): void
    {
        // A branch that was never persisted has a non-existent id, so the very first
        // insert into document_number_sequences violates the branch_id foreign key
        // (MySQL error 1452), not the (branch_id, document_type, period) unique
        // constraint (1062). The narrowed catch must let this propagate rather than
        // swallowing it and masking it behind an unrelated ModelNotFoundException.
        $branch = new Branch(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branch->id = 999999;
        $generator = new DocumentNumberGenerator();

        $this->expectException(QueryException::class);

        $generator->next($branch, 'PKB');
    }
}
