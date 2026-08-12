<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CustomerBranchAssignmentController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoicePkbGapReportController;
use App\Http\Controllers\InvoiceReportController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\MechanicBranchAssignmentController;
use App\Http\Controllers\MechanicController;
use App\Http\Controllers\PaymentLookupController;
use App\Http\Controllers\PaymentReceiptController;
use App\Http\Controllers\PkbReportController;
use App\Http\Controllers\PublicInvoiceController;
use App\Http\Controllers\WorkshopPerformanceReportController;
use App\Http\Controllers\ReceivableReportController;
use App\Http\Controllers\UserBranchAssignmentController;
use App\Http\Controllers\UserBranchPermissionAssignmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RackController;
use App\Http\Controllers\ServiceCatalogController;
use App\Http\Controllers\SparepartBranchController;
use App\Http\Controllers\SparepartStockReportController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockCardController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\UserPermissionAssignmentController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleReferenceController;
use App\Http\Controllers\VehicleReferenceLookupController;
use App\Http\Controllers\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1');
});

Route::prefix('i')->name('public-invoices.')->group(function () {
    Route::get('/{invoice:hash_id}', [PublicInvoiceController::class, 'showPinForm'])->name('show');
    Route::post('/{invoice:hash_id}/verify', [PublicInvoiceController::class, 'verifyPin'])
        ->name('verify')->middleware('throttle:10,1');
    Route::get('/{invoice:hash_id}/pdf', [PublicInvoiceController::class, 'showPdf'])->name('pdf');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('branches')->name('branches.')->group(function () {
        Route::get('/', [BranchController::class, 'index'])->name('index');
        Route::get('/create', [BranchController::class, 'create'])->name('create');
        Route::post('/', [BranchController::class, 'store'])->name('store');
        Route::get('/{branch}/edit', [BranchController::class, 'edit'])->name('edit');
        Route::put('/{branch}', [BranchController::class, 'update'])->name('update');
    });

    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::get('/create', [CustomerController::class, 'create'])->name('create');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::get('/{customer}', [CustomerController::class, 'show'])->name('show');
        Route::put('/{customer}', [CustomerController::class, 'update'])->name('update');

        Route::prefix('{customer}/branches')->name('branches.')->group(function () {
            Route::post('/{branch}', [CustomerBranchAssignmentController::class, 'store'])->name('store');
            Route::delete('/{branch}', [CustomerBranchAssignmentController::class, 'destroy'])->name('destroy');
        });
    });

    Route::prefix('vehicles')->name('vehicles.')->group(function () {
        Route::get('/', [VehicleController::class, 'index'])->name('index');
        Route::get('/create', [VehicleController::class, 'create'])->name('create');
        Route::post('/', [VehicleController::class, 'store'])->name('store');
        Route::get('/lookup/brands/{category}', [VehicleReferenceLookupController::class, 'brandsByCategory'])->name('lookup.brands');
        Route::get('/lookup/types/{brand}', [VehicleReferenceLookupController::class, 'typesByBrand'])->name('lookup.types');
        Route::get('/{vehicle}/edit', [VehicleController::class, 'edit'])->name('edit');
        Route::put('/{vehicle}', [VehicleController::class, 'update'])->name('update');
    });

    Route::prefix('vehicle-references')->name('vehicle-references.')->group(function () {
        Route::get('/', [VehicleReferenceController::class, 'index'])->name('index');
        Route::post('/categories', [VehicleReferenceController::class, 'storeCategory'])->name('categories.store');
        Route::put('/categories/{category}', [VehicleReferenceController::class, 'updateCategory'])->name('categories.update');
        Route::post('/brands', [VehicleReferenceController::class, 'storeBrand'])->name('brands.store');
        Route::put('/brands/{brand}', [VehicleReferenceController::class, 'updateBrand'])->name('brands.update');
        Route::post('/types', [VehicleReferenceController::class, 'storeType'])->name('types.store');
        Route::put('/types/{type}', [VehicleReferenceController::class, 'updateType'])->name('types.update');
    });

    Route::prefix('mechanics')->name('mechanics.')->group(function () {
        Route::get('/', [MechanicController::class, 'index'])->name('index');
        Route::get('/create', [MechanicController::class, 'create'])->name('create');
        Route::post('/', [MechanicController::class, 'store'])->name('store');
        Route::get('/{mechanic}', [MechanicController::class, 'show'])->name('show');
        Route::put('/{mechanic}', [MechanicController::class, 'update'])->name('update');

        Route::prefix('{mechanic}/branches')->name('branches.')->group(function () {
            Route::post('/{branch}', [MechanicBranchAssignmentController::class, 'store'])->name('store');
            Route::delete('/{branch}', [MechanicBranchAssignmentController::class, 'destroy'])->name('destroy');
        });
    });

    Route::prefix('service-catalogs')->name('service-catalogs.')->group(function () {
        Route::get('/', [ServiceCatalogController::class, 'index'])->name('index');
        Route::get('/create', [ServiceCatalogController::class, 'create'])->name('create');
        Route::post('/', [ServiceCatalogController::class, 'store'])->name('store');
        Route::get('/{serviceCatalog}/edit', [ServiceCatalogController::class, 'edit'])->name('edit');
        Route::put('/{serviceCatalog}', [ServiceCatalogController::class, 'update'])->name('update');
    });

    Route::prefix('racks')->name('racks.')->group(function () {
        Route::get('/', [RackController::class, 'index'])->name('index');
        Route::get('/create', [RackController::class, 'create'])->name('create');
        Route::post('/', [RackController::class, 'store'])->name('store');
        Route::get('/{rack}/edit', [RackController::class, 'edit'])->name('edit');
        Route::put('/{rack}', [RackController::class, 'update'])->name('update');
    });

    Route::prefix('sparepart-branches')->name('sparepart-branches.')->group(function () {
        Route::get('/', [SparepartBranchController::class, 'index'])->name('index');
        Route::get('/create', [SparepartBranchController::class, 'create'])->name('create');
        Route::post('/', [SparepartBranchController::class, 'store'])->name('store');
        Route::get('/create-existing', [SparepartBranchController::class, 'createExisting'])->name('createExisting');
        Route::get('/lookup/unconfigured', [SparepartBranchController::class, 'lookupUnconfigured'])->name('lookup.unconfigured');
        Route::post('/existing', [SparepartBranchController::class, 'storeExisting'])->name('storeExisting');
        Route::get('/{sparepartBranch}/edit', [SparepartBranchController::class, 'edit'])->name('edit');
        Route::put('/{sparepartBranch}', [SparepartBranchController::class, 'update'])->name('update');
        Route::patch('/{sparepartBranch}/deactivate', [SparepartBranchController::class, 'deactivate'])->name('deactivate');
        Route::patch('/{sparepartBranch}/activate', [SparepartBranchController::class, 'activate'])->name('activate');
    });

    Route::get('/stock-card', [StockCardController::class, 'index'])->name('stock-card.index');

    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

    Route::prefix('lookup')->name('lookup.')->group(function () {
        Route::get('/customers', [LookupController::class, 'customers'])->name('customers');
        Route::get('/mechanics', [LookupController::class, 'mechanics'])->name('mechanics');
        Route::get('/spareparts', [LookupController::class, 'spareparts'])->name('spareparts');
        Route::get('/vehicles', [LookupController::class, 'vehicles'])->name('vehicles');
    });

    Route::prefix('work-orders')->name('work-orders.')->group(function () {
        Route::get('/', [WorkOrderController::class, 'index'])->name('index');
        Route::get('/create', [WorkOrderController::class, 'create'])->name('create');
        Route::post('/', [WorkOrderController::class, 'store'])->name('store');
        Route::get('/{workOrder}', [WorkOrderController::class, 'show'])->name('show');
        Route::get('/{workOrder}/edit', [WorkOrderController::class, 'edit'])->name('edit');
        Route::put('/{workOrder}', [WorkOrderController::class, 'update'])->name('update');
        Route::patch('/{workOrder}/cancel', [WorkOrderController::class, 'cancel'])->name('cancel');
        Route::patch('/{workOrder}/confirm', [WorkOrderController::class, 'confirm'])->name('confirm');
        Route::patch('/{workOrder}/complete', [WorkOrderController::class, 'complete'])->name('complete');
        Route::patch('/{workOrder}/override-shortage', [WorkOrderController::class, 'overrideShortage'])->name('overrideShortage');
        Route::get('/{workOrder}/print', [WorkOrderController::class, 'printPdf'])->name('print');
    });

    Route::prefix('goods-receipts')->name('goods-receipts.')->group(function () {
        Route::get('/', [GoodsReceiptController::class, 'index'])->name('index');
        Route::get('/create', [GoodsReceiptController::class, 'create'])->name('create');
        Route::post('/', [GoodsReceiptController::class, 'store'])->name('store');
        Route::get('/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->name('show');
        Route::get('/{goodsReceipt}/edit', [GoodsReceiptController::class, 'edit'])->name('edit');
        Route::put('/{goodsReceipt}', [GoodsReceiptController::class, 'update'])->name('update');
        Route::patch('/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('post');
        Route::patch('/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->name('cancel');
    });

    Route::prefix('stock-adjustments')->name('stock-adjustments.')->group(function () {
        Route::get('/', [StockAdjustmentController::class, 'index'])->name('index');
        Route::get('/create', [StockAdjustmentController::class, 'create'])->name('create');
        Route::post('/', [StockAdjustmentController::class, 'store'])->name('store');
        Route::get('/{stockAdjustment}', [StockAdjustmentController::class, 'show'])->name('show');
        Route::get('/{stockAdjustment}/edit', [StockAdjustmentController::class, 'edit'])->name('edit');
        Route::put('/{stockAdjustment}', [StockAdjustmentController::class, 'update'])->name('update');
        Route::patch('/{stockAdjustment}/submit', [StockAdjustmentController::class, 'submit'])->name('submit');
        Route::patch('/{stockAdjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('approve');
        Route::patch('/{stockAdjustment}/post', [StockAdjustmentController::class, 'post'])->name('post');
        Route::patch('/{stockAdjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->name('cancel');
    });

    Route::prefix('stock-transfers')->name('stock-transfers.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('index');
        Route::get('/create', [StockTransferController::class, 'create'])->name('create');
        Route::post('/', [StockTransferController::class, 'store'])->name('store');
        Route::get('/{stockTransfer}', [StockTransferController::class, 'show'])->name('show');
        Route::get('/{stockTransfer}/edit', [StockTransferController::class, 'edit'])->name('edit');
        Route::put('/{stockTransfer}', [StockTransferController::class, 'update'])->name('update');
        Route::patch('/{stockTransfer}/approve', [StockTransferController::class, 'approve'])->name('approve');
        Route::patch('/{stockTransfer}/dispatch', [StockTransferController::class, 'dispatchTransfer'])->name('dispatch');
        Route::patch('/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->name('receive');
        Route::patch('/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->name('cancel');
    });

    Route::prefix('invoices')->name('invoices.')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('index');
        Route::get('/direct/create', [InvoiceController::class, 'createDirect'])->name('createDirect');
        Route::post('/direct', [InvoiceController::class, 'storeDirect'])->name('storeDirect');
        Route::post('/', [InvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [InvoiceController::class, 'show'])->name('show');
        Route::get('/{invoice}/edit', [InvoiceController::class, 'edit'])->name('edit');
        Route::put('/{invoice}', [InvoiceController::class, 'update'])->name('update');
        Route::patch('/{invoice}/post', [InvoiceController::class, 'post'])->name('post');
        Route::patch('/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('cancel');
        Route::get('/{invoice}/print', [InvoiceController::class, 'printPdf'])->name('print');
        Route::post('/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])->name('send-email');
    });

    Route::prefix('payment-receipts')->name('payment-receipts.')->group(function () {
        Route::get('/lookup/customers-by-branch/{branchId}', [PaymentLookupController::class, 'customersByBranch'])->name('lookup.customers-by-branch');
        Route::get('/lookup/outstanding-invoices/{customer}', [PaymentLookupController::class, 'outstandingInvoicesByCustomer'])->name('lookup.outstanding-invoices');

        Route::get('/', [PaymentReceiptController::class, 'index'])->name('index');
        Route::get('/create', [PaymentReceiptController::class, 'create'])->name('create');
        Route::post('/', [PaymentReceiptController::class, 'store'])->name('store');
        Route::get('/{paymentReceipt}', [PaymentReceiptController::class, 'show'])->name('show');
        Route::patch('/{paymentReceipt}/void', [PaymentReceiptController::class, 'void'])->name('void');
    });

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/receivables', [ReceivableReportController::class, 'index'])->name('receivables.index');
        Route::get('/receivables/export-excel', [ReceivableReportController::class, 'exportExcel'])->name('receivables.export-excel');
        Route::get('/receivables/pdf-preview', [ReceivableReportController::class, 'previewPdf'])->name('receivables.pdf-preview');
        Route::get('/receivables/pdf-download', [ReceivableReportController::class, 'downloadPdf'])->name('receivables.pdf-download');
        Route::get('/pkb', [PkbReportController::class, 'index'])->name('pkb.index');
        Route::get('/pkb/export-excel', [PkbReportController::class, 'exportExcel'])->name('pkb.export-excel');
        Route::get('/pkb/pdf-preview', [PkbReportController::class, 'previewPdf'])->name('pkb.pdf-preview');
        Route::get('/pkb/pdf-download', [PkbReportController::class, 'downloadPdf'])->name('pkb.pdf-download');
        Route::get('/invoices', [InvoiceReportController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/export-excel', [InvoiceReportController::class, 'exportExcel'])->name('invoices.export-excel');
        Route::get('/invoices/pdf-preview', [InvoiceReportController::class, 'previewPdf'])->name('invoices.pdf-preview');
        Route::get('/invoices/pdf-download', [InvoiceReportController::class, 'downloadPdf'])->name('invoices.pdf-download');
        Route::get('/workshop-performance', [WorkshopPerformanceReportController::class, 'index'])->name('workshop-performance.index');
        Route::get('/workshop-performance/export-excel', [WorkshopPerformanceReportController::class, 'exportExcel'])->name('workshop-performance.export-excel');
        Route::get('/workshop-performance/pdf-preview', [WorkshopPerformanceReportController::class, 'previewPdf'])->name('workshop-performance.pdf-preview');
        Route::get('/workshop-performance/pdf-download', [WorkshopPerformanceReportController::class, 'downloadPdf'])->name('workshop-performance.pdf-download');
        Route::get('/invoice-pkb-gap', [InvoicePkbGapReportController::class, 'index'])->name('invoice-pkb-gap.index');
        Route::get('/invoice-pkb-gap/export-excel', [InvoicePkbGapReportController::class, 'exportExcel'])->name('invoice-pkb-gap.export-excel');
        Route::get('/invoice-pkb-gap/pdf-preview', [InvoicePkbGapReportController::class, 'previewPdf'])->name('invoice-pkb-gap.pdf-preview');
        Route::get('/invoice-pkb-gap/pdf-download', [InvoicePkbGapReportController::class, 'downloadPdf'])->name('invoice-pkb-gap.pdf-download');
        Route::get('/sparepart-stock', [SparepartStockReportController::class, 'index'])->name('sparepart-stock.index');
        Route::get('/sparepart-stock/export-excel', [SparepartStockReportController::class, 'exportExcel'])->name('sparepart-stock.export-excel');
        Route::get('/sparepart-stock/pdf-preview', [SparepartStockReportController::class, 'previewPdf'])->name('sparepart-stock.pdf-preview');
        Route::get('/sparepart-stock/pdf-download', [SparepartStockReportController::class, 'downloadPdf'])->name('sparepart-stock.pdf-download');
    });

    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('/create', [UserController::class, 'create'])->name('create');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/{user}', [UserController::class, 'show'])->name('show');
        Route::put('/{user}', [UserController::class, 'update'])->name('update');

        Route::prefix('{user}/branches')->name('branches.')->group(function () {
            Route::post('/{branch}', [UserBranchAssignmentController::class, 'store'])->name('store');
            Route::delete('/{branch}', [UserBranchAssignmentController::class, 'destroy'])->name('destroy');
            Route::put('/{branch}/default', [UserBranchAssignmentController::class, 'setDefault'])->name('setDefault');
        });

        Route::prefix('{user}/branches/{branch}/permissions')->name('branchPermissions.')->group(function () {
            Route::post('/{permission}', [UserBranchPermissionAssignmentController::class, 'store'])->name('store');
            Route::delete('/{permission}', [UserBranchPermissionAssignmentController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('{user}/permissions')->name('permissions.')->group(function () {
            Route::post('/{permission}', [UserPermissionAssignmentController::class, 'store'])->name('store');
            Route::delete('/{permission}', [UserPermissionAssignmentController::class, 'destroy'])->name('destroy');
        });
    });
});
