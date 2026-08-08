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
        self::USER_BRANCH_PERMISSION_GRANTED => 'HIGH',
        self::USER_BRANCH_PERMISSION_REVOKED => 'HIGH',
    ];
}
