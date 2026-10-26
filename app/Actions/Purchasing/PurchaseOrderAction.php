<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderStateException;
use App\Models\PurchaseOrder;

abstract class PurchaseOrderAction
{
    /** Kunci baris PO. Request kedua menunggu, lalu melihat status yang sudah berubah. */
    protected function lockInStatus(PurchaseOrder $order, PurchaseOrderStatus ...$allowed): PurchaseOrder
    {
        $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (!in_array($locked->status, $allowed, true)) {
            throw new InvalidPurchaseOrderStateException($locked->status->value);
        }

        return $locked;
    }
}
