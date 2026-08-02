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
</div>
<ul class="list-group list-group-flush" id="auditLogFeed">
    @foreach ($auditLogRows as $row)
        <li class="list-group-item px-0">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold">{{ $row['user'] }}</span>
                <span class="small" style="color: var(--color-ink-muted);">{{ $row['timestamp'] }}</span>
            </div>
            <div class="small mb-1">
                <code>{{ $row['permission'] }}</code>
            </div>
            <div>{{ $row['description'] }}</div>
            <span class="status-dot {{ $row['impact'] === 'HIGH' ? 'status-inactive' : 'status-active' }}">{{ $row['impact'] }}</span>
        </li>
    @endforeach
</ul>
