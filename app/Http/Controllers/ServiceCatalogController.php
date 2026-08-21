<?php

namespace App\Http\Controllers;

use App\Exports\ServiceCatalogImportTemplateExport;
use App\Http\Requests\ImportServiceCatalogLinesRequest;
use App\Http\Requests\StoreServiceCatalogBulkRequest;
use App\Http\Requests\StoreServiceCatalogRequest;
use App\Http\Requests\UpdateServiceCatalogRequest;
use App\Imports\ServiceCatalogLinesImport;
use App\Models\ServiceCatalog;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ServiceCatalogController extends Controller
{
    public function index()
    {
        $this->authorize('service.view');

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $serviceCatalogs = ServiceCatalog::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('code', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('name', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->simplePaginate(15)
            ->withQueryString();

        return view('service-catalogs.index', compact('serviceCatalogs'))->with('search', $search);
    }

    public function create()
    {
        $this->authorize('service.create');

        $serviceCatalog = new ServiceCatalog();

        return view('service-catalogs.create', compact('serviceCatalog'));
    }

    public function store(StoreServiceCatalogRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        ServiceCatalog::create($data);

        return redirect()->route('service-catalogs.index')->with('status', 'Jasa service berhasil ditambahkan.');
    }

    public function importPage()
    {
        $this->authorize('service.create');

        return view('service-catalogs.import');
    }

    public function downloadImportTemplate()
    {
        $this->authorize('service.create');

        return Excel::download(new ServiceCatalogImportTemplateExport(), 'template-import-jasa-service.xlsx');
    }

    public function importLines(ImportServiceCatalogLinesRequest $request)
    {
        $data = $request->validated();

        $import = new ServiceCatalogLinesImport();
        Excel::import($import, $data['file']);

        if (! empty($import->errors)) {
            return response()->json(['errors' => $import->errors], 422);
        }

        return response()->json(['lines' => $import->lines]);
    }

    public function storeBulk(StoreServiceCatalogBulkRequest $request)
    {
        $data = $request->validated();

        $count = DB::transaction(function () use ($data) {
            foreach ($data['lines'] as $line) {
                ServiceCatalog::create([
                    'code' => $line['code'],
                    'name' => $line['name'],
                    'default_price' => $line['default_price'],
                ]);
            }

            return count($data['lines']);
        });

        return redirect()->route('service-catalogs.index')->with('status', "{$count} jasa service berhasil diimport.");
    }

    public function edit(ServiceCatalog $serviceCatalog)
    {
        $this->authorize('service.edit');

        return view('service-catalogs.edit', compact('serviceCatalog'));
    }

    public function update(UpdateServiceCatalogRequest $request, ServiceCatalog $serviceCatalog)
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');

        $serviceCatalog->update($data);

        return redirect()->route('service-catalogs.index')->with('status', 'Jasa service berhasil diperbarui.');
    }
}
