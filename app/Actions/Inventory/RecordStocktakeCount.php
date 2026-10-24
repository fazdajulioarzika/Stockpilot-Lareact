<?php

namespace App\Actions\Inventory;

use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordStocktakeCount extends StocktakeAction
{
    /**
     * Simpan hasil hitung (boleh sebagian, boleh berkali-kali selama status counting).
     * counted_qty kosong/null berarti "belum dihitung" atau mengosongkan hitungan sebelumnya.
     *
     * @param array<int, array{item_id: int, counted_qty: string|int|float|null, reason?: string|null}> $lines
     */
    public function execute(Stocktake $stocktake, User $user, array $lines): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $lines) {
            $s = $this->lockInStatus($stocktake, StocktakeStatus::Counting);

            $ids = array_map(fn ($l) => (int) $l['item_id'], $lines);

            if (count($ids) !== count(array_unique($ids))) {
                throw new DomainException('Item yang sama tidak boleh muncul dua kali.');
            }

            $items = $s->items()->lockForUpdate()->get()->keyBy('id');

            foreach ($lines as $line) {
                $item = $items->get((int) $line['item_id'])
                    ?? throw new DomainException('Ada item yang bukan bagian dari opname ini.');

                $counted = $line['counted_qty'] ?? null;
                $counted = ($counted === null || $counted === '') ? null : (string) $counted;

                if ($counted !== null) {
                    // Format ketat: angka tak negatif, maksimal 3 desimal (aman untuk bcmath).
                    if (!preg_match('/^\d{1,12}(\.\d{1,3})?$/', $counted)) {
                        throw new DomainException('Kuantitas hitung harus berupa angka tidak negatif (maksimal 3 desimal).');
                    }

                    $counted = bcadd($counted, '0', 3);
                }

                $reason = trim((string) ($line['reason'] ?? ''));

                $item->forceFill([
                    'counted_qty' => $counted,
                    'difference' => $counted === null ? null : bcsub($counted, (string) $item->system_qty, 3),
                    'reason' => $reason === '' ? null : $reason,
                ])->save();
            }

            return $s;
        });
    }
}
