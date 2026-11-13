<?php

namespace App\Support;

class AuditEvent
{
    const INVOICE_POSTED = 'invoice.posted';
    const INVOICE_CANCELLED = 'invoice.cancelled';
    const PAYMENT_RECEIPT_CREATED = 'payment_receipt.created';
    const PAYMENT_RECEIPT_VOIDED = 'payment_receipt.voided';
    const STOCK_ADJUSTMENT_POSTED = 'stock_adjustment.posted';
    const STOCK_TRANSFER_DISPATCHED = 'stock_transfer.dispatched';
    const STOCK_TRANSFER_RECEIVED = 'stock_transfer.received';
    const STOCK_TRANSFER_VOIDED = 'stock_transfer.voided';
    const PURCHASE_REQUEST_SUBMITTED = 'purchase_request.submitted';
    const PURCHASE_REQUEST_APPROVED = 'purchase_request.approved';
    const PURCHASE_REQUEST_REJECTED = 'purchase_request.rejected';
    const PURCHASE_REQUEST_CANCELLED = 'purchase_request.cancelled';
    const PURCHASE_ORDER_ISSUED = 'purchase_order.issued';
    const PURCHASE_ORDER_CANCELLED = 'purchase_order.cancelled';
    const APPROVAL_SETTING_CHANGED = 'approval_setting.changed';
    const USER_BRANCH_PERMISSION_GRANTED = 'user_branch_permission.granted';
    const USER_BRANCH_PERMISSION_REVOKED = 'user_branch_permission.revoked';

    const LABELS = [
        self::INVOICE_POSTED => 'Invoice Diposting',
        self::INVOICE_CANCELLED => 'Invoice Dibatalkan',
        self::PAYMENT_RECEIPT_CREATED => 'Pembayaran Dicatat',
        self::PAYMENT_RECEIPT_VOIDED => 'Pembayaran Di-void',
        self::STOCK_ADJUSTMENT_POSTED => 'Stock Adjustment Diposting',
        self::STOCK_TRANSFER_DISPATCHED => 'Transfer Stock Dikirim',
        self::STOCK_TRANSFER_RECEIVED => 'Transfer Stock Diterima',
        self::STOCK_TRANSFER_VOIDED => 'Transfer Stock Dibatalkan',
        self::PURCHASE_REQUEST_SUBMITTED => 'Permintaan Barang Diajukan',
        self::PURCHASE_REQUEST_APPROVED => 'Permintaan Barang Disetujui',
        self::PURCHASE_REQUEST_REJECTED => 'Permintaan Barang Ditolak',
        self::PURCHASE_REQUEST_CANCELLED => 'Permintaan Barang Dibatalkan',
        self::PURCHASE_ORDER_ISSUED => 'Pembelian Barang Diterbitkan',
        self::PURCHASE_ORDER_CANCELLED => 'Pembelian Barang Dibatalkan',
        self::APPROVAL_SETTING_CHANGED => 'Pengaturan Approval Diubah',
        self::USER_BRANCH_PERMISSION_GRANTED => 'Permission Cabang Diberikan',
        self::USER_BRANCH_PERMISSION_REVOKED => 'Permission Cabang Dicabut',
    ];

    const SEVERITIES = [
        self::INVOICE_POSTED => 'LOW',
        self::INVOICE_CANCELLED => 'MEDIUM',
        self::PAYMENT_RECEIPT_CREATED => 'LOW',
        self::PAYMENT_RECEIPT_VOIDED => 'MEDIUM',
        self::STOCK_ADJUSTMENT_POSTED => 'MEDIUM',
        self::STOCK_TRANSFER_DISPATCHED => 'LOW',
        self::STOCK_TRANSFER_RECEIVED => 'LOW',
        self::STOCK_TRANSFER_VOIDED => 'MEDIUM',
        self::PURCHASE_REQUEST_SUBMITTED => 'MEDIUM',
        self::PURCHASE_REQUEST_APPROVED => 'MEDIUM',
        self::PURCHASE_REQUEST_REJECTED => 'MEDIUM',
        self::PURCHASE_REQUEST_CANCELLED => 'MEDIUM',
        self::PURCHASE_ORDER_ISSUED => 'MEDIUM',
        self::PURCHASE_ORDER_CANCELLED => 'MEDIUM',
        self::APPROVAL_SETTING_CHANGED => 'HIGH',
        self::USER_BRANCH_PERMISSION_GRANTED => 'HIGH',
        self::USER_BRANCH_PERMISSION_REVOKED => 'HIGH',
    ];
}
