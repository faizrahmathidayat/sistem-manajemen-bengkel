<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>@yield('report-title', 'Laporan')</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; padding: 20px; }
        .print-header { text-align: center; margin-bottom: 12px; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px; }
        .print-header h1 { font-size: 16px; margin: 0 0 2px; }
        .print-header .subtitle { font-size: 11px; color: #555; margin: 0; }
        .print-filters { font-size: 9px; color: #444; margin-bottom: 10px; }
        .print-note { font-size: 9px; color: #b45309; margin-bottom: 8px; font-style: italic; }
        table.print-table { width: 100%; border-collapse: collapse; font-size: 9px; }
        table.print-table th, table.print-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        table.print-table th { background: #eee; font-weight: bold; }
        .print-footer { margin-top: 14px; font-size: 8px; color: #666; text-align: right; }
    </style>
</head>
<body>
    <div class="print-header">
        <h1>Sistem Manajemen Bengkel</h1>
        <p class="subtitle">@yield('report-title', 'Laporan')</p>
    </div>
    <div class="print-filters">@yield('filter-summary')</div>
    @yield('note')
    @yield('table')
    <div class="print-footer">
        Dicetak oleh {{ auth()->user()->name }} pada {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
