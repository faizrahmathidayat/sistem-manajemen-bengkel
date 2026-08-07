<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Support\AuditEvent;

class AuditLogController extends Controller
{
    public function index()
    {
        $this->authorize('audit_log.view');

        $branches = Branch::orderBy('name')->get();

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect($branches->pluck('id'))
            ->values()->all();

        $userSearch = is_string(request('user')) ? trim(request('user')) : null;

        $event = request('event');
        $event = array_key_exists($event, AuditEvent::LABELS) ? $event : null;

        $dateFrom = $this->parseDate(request('date_from'));
        $dateTo = $this->parseDate(request('date_to'));

        $logs = AuditLog::with(['branch', 'user'])
            ->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($userSearch, function ($q, $term) {
                $escaped = addcslashes($term, '%_\\');
                $q->whereHas('user', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%");
                });
            })
            ->when($event, fn ($q) => $q->where('event', $event))
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(20)
            ->withQueryString();

        return view('audit-logs.index', [
            'logs' => $logs,
            'branches' => $branches,
            'selectedBranchIds' => $branchIds,
            'userSearch' => $userSearch,
            'event' => $event,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
    }

    protected function parseDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
