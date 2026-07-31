<?php

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;

trait HasAttachments
{
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function addAttachment(UploadedFile $file, $uploadedBy = null, string $disk = 'local'): Attachment
    {
        $path = $file->store($this->attachmentStoragePath(), $disk);

        return $this->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    protected function attachmentStoragePath(): string
    {
        return strtolower(class_basename($this)) . 's/' . $this->getKey();
    }
}
