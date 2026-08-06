<?php

namespace App\Support;

class PaymentMethod
{
    const CASH = 'cash';
    const TRANSFER = 'transfer';
    const QRIS = 'qris';
    const DEBIT_CARD = 'debit_card';
    const CREDIT_CARD = 'credit_card';
    const OTHER = 'other';

    const ALL = [self::CASH, self::TRANSFER, self::QRIS, self::DEBIT_CARD, self::CREDIT_CARD, self::OTHER];

    const LABELS = [
        self::CASH => 'Tunai',
        self::TRANSFER => 'Transfer Bank',
        self::QRIS => 'QRIS',
        self::DEBIT_CARD => 'Kartu Debit',
        self::CREDIT_CARD => 'Kartu Kredit',
        self::OTHER => 'Lainnya',
    ];
}
