<?php

namespace App\Http\Controllers\Concerns;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

trait HandlesReportExport
{
    protected function authorizeExport($permittedBranches): void
    {
        abort_if($permittedBranches->isEmpty(), 403);
    }

    protected function capRows(Collection $rows, int $limit = 1000): array
    {
        $truncated = $rows->count() > $limit;

        return [$truncated ? $rows->slice(0, $limit)->values() : $rows, $truncated];
    }

    protected function streamPdf(string $view, array $data, string $filenameBase, string $disposition)
    {
        $pdf = Pdf::loadView($view, $data)->setPaper('a4', 'landscape');
        $filename = $filenameBase . '-' . now()->format('Ymd-His') . '.pdf';

        return $disposition === 'attachment' ? $pdf->download($filename) : $pdf->stream($filename);
    }
}
