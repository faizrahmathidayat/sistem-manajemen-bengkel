<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceCatalogRequest;
use App\Http\Requests\UpdateServiceCatalogRequest;
use App\Models\ServiceCatalog;

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
