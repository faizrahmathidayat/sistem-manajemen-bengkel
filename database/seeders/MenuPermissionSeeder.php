<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class MenuPermissionSeeder extends Seeder
{
    public function run()
    {
        foreach ($this->definitions() as $sortOrder => $menuDefinition) {
            $menu = Menu::updateOrCreate(
                ['code' => $menuDefinition['code']],
                [
                    'name' => $menuDefinition['name'],
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'is_branch_scoped' => $menuDefinition['is_branch_scoped'],
                ]
            );

            foreach ($menuDefinition['permissions'] as $permission) {
                Permission::updateOrCreate(
                    ['code' => $permission['code']],
                    [
                        'menu_id' => $menu->id,
                        'resource' => $permission['resource'],
                        'action' => $permission['action'],
                        'description' => $permission['description'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    protected function definitions(): array
    {
        return [
            [
                'code' => 'umum.dashboard',
                'name' => 'Dashboard',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'dashboard.view', 'resource' => 'dashboard', 'action' => 'view', 'description' => 'Melihat dashboard'],
                ],
            ],
            [
                'code' => 'operasional.pkb',
                'name' => 'Perintah Kerja Bengkel',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB'],
                    ['code' => 'pkb.create', 'resource' => 'pkb', 'action' => 'create', 'description' => 'Membuat PKB'],
                    ['code' => 'pkb.edit', 'resource' => 'pkb', 'action' => 'edit', 'description' => 'Mengubah PKB'],
                    ['code' => 'pkb.confirm', 'resource' => 'pkb', 'action' => 'confirm', 'description' => 'Mengonfirmasi PKB'],
                    ['code' => 'pkb.complete', 'resource' => 'pkb', 'action' => 'complete', 'description' => 'Menandai PKB selesai dikerjakan'],
                    ['code' => 'pkb.cancel', 'resource' => 'pkb', 'action' => 'cancel', 'description' => 'Membatalkan PKB'],
                    ['code' => 'pkb.override_stock_shortage', 'resource' => 'pkb', 'action' => 'override_stock_shortage', 'description' => 'Override kekurangan stok pada PKB'],
                    ['code' => 'pkb.print', 'resource' => 'pkb', 'action' => 'print', 'description' => 'Cetak PKB'],
                ],
            ],
            [
                'code' => 'operasional.invoice',
                'name' => 'Invoice',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'invoice.view', 'resource' => 'invoice', 'action' => 'view', 'description' => 'Melihat invoice'],
                    ['code' => 'invoice.create', 'resource' => 'invoice', 'action' => 'create', 'description' => 'Membuat invoice'],
                    ['code' => 'invoice.edit', 'resource' => 'invoice', 'action' => 'edit', 'description' => 'Mengubah invoice draft'],
                    ['code' => 'invoice.post', 'resource' => 'invoice', 'action' => 'post', 'description' => 'Posting invoice'],
                    ['code' => 'invoice.void', 'resource' => 'invoice', 'action' => 'void', 'description' => 'Void invoice'],
                    ['code' => 'invoice.print', 'resource' => 'invoice', 'action' => 'print', 'description' => 'Cetak invoice'],
                    ['code' => 'invoice.email', 'resource' => 'invoice', 'action' => 'email', 'description' => 'Kirim invoice via email'],
                    ['code' => 'invoice.share_whatsapp', 'resource' => 'invoice', 'action' => 'share_whatsapp', 'description' => 'Kirim invoice via WhatsApp'],
                ],
            ],
            [
                'code' => 'operasional.payment',
                'name' => 'Penerimaan Pembayaran',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'payment.view', 'resource' => 'payment', 'action' => 'view', 'description' => 'Melihat pembayaran'],
                    ['code' => 'payment.create', 'resource' => 'payment', 'action' => 'create', 'description' => 'Mencatat pembayaran'],
                    ['code' => 'payment.void', 'resource' => 'payment', 'action' => 'void', 'description' => 'Void pembayaran'],
                    ['code' => 'payment.print', 'resource' => 'payment', 'action' => 'print', 'description' => 'Cetak bukti pembayaran'],
                ],
            ],
            [
                'code' => 'persediaan.sparepart',
                'name' => 'Master Sparepart',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'sparepart.view', 'resource' => 'sparepart', 'action' => 'view', 'description' => 'Melihat sparepart'],
                    ['code' => 'sparepart.create', 'resource' => 'sparepart', 'action' => 'create', 'description' => 'Membuat sparepart'],
                    ['code' => 'sparepart.edit', 'resource' => 'sparepart', 'action' => 'edit', 'description' => 'Mengubah sparepart'],
                    ['code' => 'sparepart.delete', 'resource' => 'sparepart', 'action' => 'delete', 'description' => 'Menonaktifkan sparepart'],
                ],
            ],
            [
                'code' => 'persediaan.receipt',
                'name' => 'Penerimaan Barang',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'receipt.view', 'resource' => 'receipt', 'action' => 'view', 'description' => 'Melihat penerimaan barang'],
                    ['code' => 'receipt.create', 'resource' => 'receipt', 'action' => 'create', 'description' => 'Membuat penerimaan barang'],
                    ['code' => 'receipt.post', 'resource' => 'receipt', 'action' => 'post', 'description' => 'Posting penerimaan barang'],
                    ['code' => 'receipt.cancel', 'resource' => 'receipt', 'action' => 'cancel', 'description' => 'Membatalkan penerimaan barang'],
                ],
            ],
            [
                'code' => 'persediaan.stock_adjustment',
                'name' => 'Stock Adjustment',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'stock_adjustment.view', 'resource' => 'stock_adjustment', 'action' => 'view', 'description' => 'Melihat stock adjustment'],
                    ['code' => 'stock_adjustment.create', 'resource' => 'stock_adjustment', 'action' => 'create', 'description' => 'Membuat stock adjustment'],
                    ['code' => 'stock_adjustment.approve', 'resource' => 'stock_adjustment', 'action' => 'approve', 'description' => 'Menyetujui stock adjustment'],
                    ['code' => 'stock_adjustment.post', 'resource' => 'stock_adjustment', 'action' => 'post', 'description' => 'Posting stock adjustment'],
                    ['code' => 'stock_adjustment.cancel', 'resource' => 'stock_adjustment', 'action' => 'cancel', 'description' => 'Membatalkan stock adjustment'],
                ],
            ],
            [
                'code' => 'persediaan.stock_transfer',
                'name' => 'Transfer Stock',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'stock_transfer.view', 'resource' => 'stock_transfer', 'action' => 'view', 'description' => 'Melihat transfer stock'],
                    ['code' => 'stock_transfer.create', 'resource' => 'stock_transfer', 'action' => 'create', 'description' => 'Membuat transfer stock'],
                    ['code' => 'stock_transfer.approve', 'resource' => 'stock_transfer', 'action' => 'approve', 'description' => 'Menyetujui transfer stock'],
                    ['code' => 'stock_transfer.dispatch', 'resource' => 'stock_transfer', 'action' => 'dispatch', 'description' => 'Mengirim transfer stock'],
                    ['code' => 'stock_transfer.receive', 'resource' => 'stock_transfer', 'action' => 'receive', 'description' => 'Menerima transfer stock'],
                    ['code' => 'stock_transfer.cancel', 'resource' => 'stock_transfer', 'action' => 'cancel', 'description' => 'Membatalkan transfer stock'],
                ],
            ],
            [
                'code' => 'master.branch',
                'name' => 'Cabang',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'branch.view', 'resource' => 'branch', 'action' => 'view', 'description' => 'Melihat cabang'],
                    ['code' => 'branch.create', 'resource' => 'branch', 'action' => 'create', 'description' => 'Membuat cabang'],
                    ['code' => 'branch.edit', 'resource' => 'branch', 'action' => 'edit', 'description' => 'Mengubah cabang'],
                ],
            ],
            [
                'code' => 'master.customer',
                'name' => 'Customer',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'customer.view', 'resource' => 'customer', 'action' => 'view', 'description' => 'Melihat customer'],
                    ['code' => 'customer.create', 'resource' => 'customer', 'action' => 'create', 'description' => 'Membuat customer'],
                    ['code' => 'customer.edit', 'resource' => 'customer', 'action' => 'edit', 'description' => 'Mengubah customer'],
                ],
            ],
            [
                'code' => 'master.vehicle',
                'name' => 'Kendaraan',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'vehicle.view', 'resource' => 'vehicle', 'action' => 'view', 'description' => 'Melihat kendaraan'],
                    ['code' => 'vehicle.create', 'resource' => 'vehicle', 'action' => 'create', 'description' => 'Membuat kendaraan'],
                    ['code' => 'vehicle.edit', 'resource' => 'vehicle', 'action' => 'edit', 'description' => 'Mengubah kendaraan'],
                ],
            ],
            [
                'code' => 'master.vehicle_reference',
                'name' => 'Referensi Kendaraan',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'vehicle_reference.view', 'resource' => 'vehicle_reference', 'action' => 'view', 'description' => 'Melihat referensi kendaraan'],
                    ['code' => 'vehicle_reference.manage', 'resource' => 'vehicle_reference', 'action' => 'manage', 'description' => 'Mengelola referensi kendaraan'],
                ],
            ],
            [
                'code' => 'master.mechanic',
                'name' => 'Mekanik',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'mechanic.view', 'resource' => 'mechanic', 'action' => 'view', 'description' => 'Melihat mekanik'],
                    ['code' => 'mechanic.create', 'resource' => 'mechanic', 'action' => 'create', 'description' => 'Membuat mekanik'],
                    ['code' => 'mechanic.edit', 'resource' => 'mechanic', 'action' => 'edit', 'description' => 'Mengubah mekanik'],
                ],
            ],
            [
                'code' => 'master.service',
                'name' => 'Jasa Service',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'service.view', 'resource' => 'service', 'action' => 'view', 'description' => 'Melihat jasa service'],
                    ['code' => 'service.create', 'resource' => 'service', 'action' => 'create', 'description' => 'Membuat jasa service'],
                    ['code' => 'service.edit', 'resource' => 'service', 'action' => 'edit', 'description' => 'Mengubah jasa service'],
                ],
            ],
            [
                'code' => 'master.rack',
                'name' => 'Rack',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'rack.view', 'resource' => 'rack', 'action' => 'view', 'description' => 'Melihat rack'],
                    ['code' => 'rack.create', 'resource' => 'rack', 'action' => 'create', 'description' => 'Membuat rack'],
                    ['code' => 'rack.edit', 'resource' => 'rack', 'action' => 'edit', 'description' => 'Mengubah rack'],
                ],
            ],
            [
                'code' => 'administrasi.users',
                'name' => 'Users',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'user.view', 'resource' => 'user', 'action' => 'view', 'description' => 'Melihat user'],
                    ['code' => 'user.create', 'resource' => 'user', 'action' => 'create', 'description' => 'Membuat user'],
                    ['code' => 'user.edit', 'resource' => 'user', 'action' => 'edit', 'description' => 'Mengubah user'],
                ],
            ],
            [
                'code' => 'administrasi.user_branches',
                'name' => 'User Branches',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'user_branch.manage', 'resource' => 'user_branch', 'action' => 'manage', 'description' => 'Mengelola cabang milik user'],
                ],
            ],
            [
                'code' => 'administrasi.user_permissions',
                'name' => 'User Permissions',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'user_permission.manage', 'resource' => 'user_permission', 'action' => 'manage', 'description' => 'Mengelola permission milik user'],
                ],
            ],
            [
                'code' => 'administrasi.audit_log',
                'name' => 'Audit Log',
                'is_branch_scoped' => false,
                'permissions' => [
                    ['code' => 'audit_log.view', 'resource' => 'audit_log', 'action' => 'view', 'description' => 'Melihat audit log'],
                ],
            ],
            [
                'code' => 'reporting.pkb',
                'name' => 'Laporan PKB',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.pkb.view', 'resource' => 'report', 'action' => 'pkb.view', 'description' => 'Melihat laporan PKB'],
                ],
            ],
            [
                'code' => 'reporting.invoice',
                'name' => 'Laporan Invoice',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.invoice.view', 'resource' => 'report', 'action' => 'invoice.view', 'description' => 'Melihat laporan invoice'],
                ],
            ],
            [
                'code' => 'reporting.workshop_performance',
                'name' => 'Laporan Performance Bengkel',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.workshop_performance.view', 'resource' => 'report', 'action' => 'workshop_performance.view', 'description' => 'Melihat laporan performance bengkel'],
                ],
            ],
            [
                'code' => 'reporting.receivable',
                'name' => 'Laporan Piutang',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.receivable.view', 'resource' => 'report', 'action' => 'receivable.view', 'description' => 'Melihat laporan piutang'],
                ],
            ],
            [
                'code' => 'reporting.pkb_invoice_gap',
                'name' => 'PKB vs Invoice',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.invoice_pkb_gap.view', 'resource' => 'report', 'action' => 'invoice_pkb_gap.view', 'description' => 'Melihat laporan selisih PKB vs invoice'],
                ],
            ],
            [
                'code' => 'reporting.sparepart',
                'name' => 'Laporan Sparepart',
                'is_branch_scoped' => true,
                'permissions' => [
                    ['code' => 'report.sparepart.view', 'resource' => 'report', 'action' => 'sparepart.view', 'description' => 'Melihat laporan sparepart'],
                    ['code' => 'report.export', 'resource' => 'report', 'action' => 'export', 'description' => 'Mengekspor laporan'],
                ],
            ],
        ];
    }
}
