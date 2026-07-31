<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Services\DocumentNumberGenerator;
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

        $period = now()->format('Ym');
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

        $period = now()->format('Ym');
        $this->assertSame("PKB/BDG/{$period}/00001", $bandungFirst);
    }

    public function test_sequences_are_isolated_per_document_type(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $generator = new DocumentNumberGenerator();

        $generator->next($branch, 'PKB');
        $invoiceFirst = $generator->next($branch, 'INV');

        $period = now()->format('Ym');
        $this->assertSame("INV/JKT/{$period}/00001", $invoiceFirst);
    }
}
