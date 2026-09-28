<?php

namespace App\Support;

use Illuminate\Support\Collection;

class WarehouseIds
{
    /**
     * Parse the comma-separated value returned by V_TO_ONLINE_USERS_WS.
     * Only positive integer IDs are accepted before they reach SQL bindings.
     */
    public static function parse($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif ($value === null || trim((string) $value) === '') {
            $parts = [];
        } else {
            $parts = preg_split('/[,;\s]+/', trim((string) $value));
        }

        $ids = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Read the new list column, falling back to the legacy single-ID column. */
    public static function fromUser($user): array
    {
        $ids = self::parse(data_get($user, 'warehouse_ids'));

        return $ids ?: self::parse(data_get($user, 'warehouse_id'));
    }

    /**
     * Preserve the existing behavior: missing stock rows don't block a product;
     * otherwise it is available if any configured warehouse reports in-stock.
     */
    public static function isAvailableInAnyWarehouse(Collection $stockRows, array $warehouseIds): bool
    {
        if (!$warehouseIds) {
            return true;
        }

        foreach ($warehouseIds as $warehouseId) {
            $rowsForWarehouse = $stockRows->filter(function ($stock) use ($warehouseId) {
                return (int) $stock->warehouse_id === (int) $warehouseId;
            });

            if ($rowsForWarehouse->isEmpty()
                || $rowsForWarehouse->contains(fn ($stock) => (bool) $stock->in_stock)) {
                return true;
            }
        }

        return false;
    }
}
