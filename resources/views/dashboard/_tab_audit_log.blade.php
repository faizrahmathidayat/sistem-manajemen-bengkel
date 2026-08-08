<div class="row g-2 mb-3">
    <div class="col-md-4">
        <select class="form-select form-select-sm" disabled>
            <option>Semua User</option>
        </select>
    </div>
    <div class="col-md-4">
        <select class="form-select form-select-sm" disabled>
            <option>Semua Jenis Event</option>
        </select>
    </div>
    <div class="col-md-4 text-md-end">
        <a href="{{ route('audit-logs.index') }}" class="btn btn-outline-secondary btn-sm">Lihat Semua Audit Log</a>
    </div>
</div>
<ul class="list-group list-group-flush" id="auditLogFeed">
    @forelse ($auditLogRows as $row)
        @php
            $severityClass = ['LOW' => 'status-active', 'MEDIUM' => 'status-warning', 'HIGH' => 'status-inactive'][$row['severity']] ?? 'status-active';
        @endphp
        <li class="list-group-item px-0">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold">{{ $row['user'] }}</span>
                <span class="small" style="color: var(--color-ink-muted);">{{ $row['timestamp'] }}</span>
            </div>
            <div class="small mb-1">
                <code>{{ $row['event'] }}</code>
            </div>
            <div>{{ $row['description'] }}</div>
            <span class="status-dot {{ $severityClass }}">{{ $row['severity'] }}</span>
        </li>
    @empty
        <li class="list-group-item px-0 text-muted text-center py-3">Belum ada aktivitas untuk cabang terpilih.</li>
    @endforelse
</ul>
