<?php

namespace App\Actions\Inventory;

use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use App\Models\AuditLog;

class RejectStocktake extends StocktakeAction
{
    /** Kembalikan ke counting untuk dihitung ulang. Gudang tetap terkunci. */
    public function execute(Stocktake $stocktake, User $user, string $note): Stocktake
    {
        $note = trim($note);

        if ($note === '') {
            throw new DomainException('Catatan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($stocktake, $note, $user) {
            $s = $this->lockInStatus($stocktake, StocktakeStatus::PendingApproval);

            $s->forceFill([
                'status' => StocktakeStatus::Counting,
                'rejection_note' => $note,
                'submitted_at' => null,
                'submitted_by' => null,
            ])->save();
            AuditLog::record('stocktake.rejected', $s, $user, ['note' => $note]);
            return $s;
        });
    }
}
