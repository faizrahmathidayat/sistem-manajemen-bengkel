<div class="btn-group">
    <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-download me-1"></i>Export
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="{{ route($excelRoute, request()->query()) }}" download>Export Excel</a></li>
        <li><a class="dropdown-item" href="{{ route($pdfPreviewRoute, request()->query()) }}" target="_blank" rel="noopener">Preview PDF</a></li>
        <li><a class="dropdown-item" href="{{ route($pdfDownloadRoute, request()->query()) }}" download>Download PDF</a></li>
    </ul>
</div>
