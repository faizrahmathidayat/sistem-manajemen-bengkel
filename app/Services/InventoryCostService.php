<?php

namespace App\Services;

class InventoryCostService
{
    /**
     * Rumus weighted average cost standar. $qtyBefore/$avgCostBefore adalah kondisi
     * SEBELUM barang masuk ini; $qtyIn/$unitCost adalah barang yang masuk. Caller
     * bertanggung jawab lockForUpdate() baris stock-nya sendiri — service ini murni
     * kalkulasi, tidak melakukan query atau mutasi apa pun.
     */
    public function recalculateOnReceipt(float $qtyBefore, float $avgCostBefore, float $qtyIn, float $unitCost): float
    {
        $qtyAfter = $qtyBefore + $qtyIn;

        if ($qtyAfter <= 0.0) {
            return $unitCost;
        }

        return (($qtyBefore * $avgCostBefore) + ($qtyIn * $unitCost)) / $qtyAfter;
    }
}
