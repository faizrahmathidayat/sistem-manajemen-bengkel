<?php

namespace App\Support;

/**
 * Purchase requests are only for items that ran low: AVAILABLE stock (on hand minus what PKBs
 * reserved) must be below the branch's minimum stock. Shared by the form validation and the
 * re-check inside the saving transaction so both give the same answer and message.
 */
class PurchaseRequestStockRule
{
    /** @return string|null the refusal message, or null when the item may be requested */
    public static function violation(float $available, float $minimum): ?string
    {
        if ($available < $minimum) {
            return null;
        }

        return 'Stok tersedia ' . self::plain($available) . ' belum di bawah stok minimal ' . self::plain($minimum)
            . '. Permintaan hanya untuk barang yang stok tersedianya di bawah stok minimal.';
    }

    private static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
