<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use App\Models\User;

class TransferPolicy
{
    private function writer(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::WarehouseStaff);
    }

    public function viewAny(User $user): bool
    {
        return true; // Daftar di-scope ke gudang user di controller.
    }

    /** Boleh melihat jika terlibat di gudang sumber atau tujuan. */
    public function view(User $user, StockTransfer $t): bool
    {
        return $user->canAccessWarehouse($t->source_warehouse_id)
            || $user->canAccessWarehouse($t->destination_warehouse_id);
    }

    public function create(User $user): bool
    {
        return $this->writer($user);
    }

    public function update(User $user, StockTransfer $t): bool
    {
        return $this->writer($user)
            && $t->status === TransferStatus::Draft
            && $user->canAccessWarehouse($t->source_warehouse_id);
    }

    public function delete(User $user, StockTransfer $t): bool
    {
        return $this->update($user, $t);
    }

    public function submit(User $user, StockTransfer $t): bool
    {
        return $this->update($user, $t);
    }

    public function approve(User $user, StockTransfer $t): bool
    {
        return $user->hasRole(Role::Admin, Role::Manager)
            && $t->status === TransferStatus::PendingApproval;
    }

    /** Pengiriman dilakukan staff gudang sumber. */
    public function dispatch(User $user, StockTransfer $t): bool
    {
        return $this->writer($user)
            && $t->status === TransferStatus::Approved
            && $user->canAccessWarehouse($t->source_warehouse_id);
    }

    /** Penerimaan dilakukan staff gudang tujuan. */
    public function receive(User $user, StockTransfer $t): bool
    {
        return $this->writer($user)
            && $t->status->hasStockInTransit()
            && $user->canAccessWarehouse($t->destination_warehouse_id);
    }

    /** Retur sisa dilakukan gudang sumber, karena stoknya kembali ke sana. */
    public function returnToSource(User $user, StockTransfer $t): bool
    {
        return $this->writer($user)
            && $t->status->hasStockInTransit()
            && $user->canAccessWarehouse($t->source_warehouse_id);
    }

    public function cancel(User $user, StockTransfer $t): bool
    {
        $cancellable = in_array($t->status, [
            TransferStatus::Draft,
            TransferStatus::PendingApproval,
            TransferStatus::Approved,
        ], true);

        return $cancellable
            && ($user->hasRole(Role::Admin, Role::Manager)
                || ($this->writer($user) && $user->canAccessWarehouse($t->source_warehouse_id)));
    }
}
