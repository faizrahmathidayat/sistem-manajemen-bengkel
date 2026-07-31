<?php

namespace Tests\Feature;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_branch_can_have_attachments_uploaded_and_linked_polymorphically(): void
    {
        Storage::fake('local');
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $file = UploadedFile::fake()->create('izin-usaha.pdf', 100, 'application/pdf');

        $attachment = $branch->addAttachment($file);

        $this->assertCount(1, $branch->attachments()->get());
        $this->assertSame('izin-usaha.pdf', $attachment->original_name);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_attachments_from_different_branches_do_not_leak_into_each_other(): void
    {
        Storage::fake('local');
        $jakarta = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bandung = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);

        $jakarta->addAttachment(UploadedFile::fake()->create('a.pdf'));

        $this->assertCount(1, $jakarta->attachments()->get());
        $this->assertCount(0, $bandung->attachments()->get());
    }
}
