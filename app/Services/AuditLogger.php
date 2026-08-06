<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditLogger
{
    public function log(string $event, ?int $branchId, ?Model $auditable, array $oldValues = [], array $newValues = []): void
    {
        try {
            AuditLog::create([
                'branch_id' => $branchId,
                'user_id' => auth()->id(),
                'event' => $event,
                'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
                'auditable_id' => $auditable ? $auditable->getKey() : null,
                'old_values' => $oldValues ?: null,
                'new_values' => $newValues ?: null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::error('Audit log write failed', [
                'event' => $event,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
