<?php

namespace App\Actions\Inventory;

use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\DocumentReversal;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReverseStockDocument
{
    public function __construct(private StockService $stock)
    {
    }

    /** Membalik Barang Masuk atau Barang Keluar yang sudah diposting. Otorisasi diperiksa policy. */
    public function execute(Model $document, User $user, string $reason): Model
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Alasan pembalikan wajib diisi.');
        }

        if (mb_strlen($reason) > 255) {
            throw new DomainException('Alasan pembalikan maksimal 255 karakter.');
        }

        return DB::transaction(function () use ($document, $user, $reason) {
            // Kunci dokumen. Request kedua menunggu, lalu melihat pembalikan yang sudah ada.
            $locked = $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== DocumentStatus::Posted) {
                throw new DomainException('Hanya dokumen yang sudah diposting yang dapat dibalik.');
            }

            $already = DocumentReversal::query()
                ->where('document_type', $locked->getMorphClass())
                ->where('document_id', $locked->getKey())
                ->lockForUpdate()
                ->first(['id']);

            if ($already) {
                throw new DomainException('Dokumen ini sudah dibalik.');
            }

            // Dasar pembalikan adalah mutasi yang benar-benar tercatat, bukan item dokumen.
            $movements = StockMovement::query()
                ->where('reference_type', $locked->getMorphClass())
                ->where('reference_id', $locked->getKey())
                ->whereIn('movement_type', [MovementType::Receipt->value, MovementType::Issue->value])
                ->orderBy('product_id') // urutan lock konsisten (mencegah deadlock)
                ->get();

            if ($movements->isEmpty()) {
                throw new DomainException('Tidak ada mutasi stok yang dapat dibalik.');
            }

            // PO dikunci sebelum saldo stok, sama dengan urutan saat posting (mencegah deadlock).
            $order = ($locked instanceof GoodsReceipt && $locked->purchase_order_id)
                ? $this->releaseOrder($locked)
                : null;

            foreach ($movements as $movement) {
                $delta = bcmul((string) $movement->quantity_delta, '-1', 3);

                // Pembalikan barang keluar mengembalikan nilai aslinya; pembalikan barang masuk keluar pada rata-rata.
                $inflow = bccomp($delta, '0', 3) > 0
                    ? Money::normalize(bcmul((string) $movement->value_delta, '-1', 4))
                    : null;

                try {
                    $this->stock->apply(
                        $movement->warehouse_id,
                        $movement->product_id,
                        $delta,
                        MovementType::Reversal,
                        $locked,
                        $user->id,
                        $inflow,
                    );
                } catch (InsufficientStockException $e) {
                    throw new DomainException(
                        'Dokumen ini tidak dapat dibalik karena sebagian stoknya sudah terpakai. ' . $e->getMessage()
                    );
                }
            }

            DocumentReversal::create([
                'document_type' => $locked->getMorphClass(),
                'document_id' => $locked->getKey(),
                'reason' => $reason,
                'reversed_by' => $user->id,
            ]);

            AuditLog::record(
                $locked instanceof GoodsReceipt ? 'goods_receipt.reversed' : 'stock_issue.reversed',
                $locked,
                $user,
                [
                    'reason' => $reason,
                    'movements' => $movements->count(),
                    ...($order ? [
                        'purchase_order' => $order->number,
                        'purchase_order_status' => $order->status->value,
                    ] : []),
                ],
            );

            return $locked;
        });
    }

    /** Kurangi jumlah diterima di PO dan hitung ulang statusnya. PO yang sempat ditutup dibuka kembali. */
    private function releaseOrder(GoodsReceipt $receipt): PurchaseOrder
    {
        $order = PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();
        $orderItems = $order->items()->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');

        foreach ($receipt->items()->orderBy('product_id')->get() as $item) {
            $line = $orderItems->get($item->product_id);

            if (!$line || bccomp((string) $line->qty_received, (string) $item->quantity, 3) < 0) {
                throw new DomainException('Data penerimaan PO tidak konsisten, pembalikan dibatalkan.');
            }

            $line->forceFill(['qty_received' => bcsub((string) $line->qty_received, (string) $item->quantity, 3)])->save();
        }

        $anyReceived = $orderItems->contains(fn ($i) => bccomp((string) $i->qty_received, '0', 3) > 0);

        $order->forceFill([
            'status' => $anyReceived ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Approved,
            'completed_at' => null,
            'closed_at' => null,
            'closed_by' => null,
            'close_reason' => null,
        ])->save();

        return $order;
    }
}
