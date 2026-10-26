<?php

namespace App\Policies;

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    private function writer(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::WarehouseStaff);
    }

    private function reviewer(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Manager);
    }

    private function inWarehouse(User $user, PurchaseOrder $o): bool
    {
        return $user->canAccessWarehouse($o->warehouse_id);
    }

    public function viewAny(User $user): bool
    {
        return true; // Daftar di-scope ke gudang user di controller.
    }

    public function view(User $user, PurchaseOrder $o): bool
    {
        return $this->inWarehouse($user, $o);
    }

    public function create(User $user): bool
    {
        return $this->writer($user);
    }

    public function update(User $user, PurchaseOrder $o): bool
    {
        return $this->writer($user) && $o->status === PurchaseOrderStatus::Draft && $this->inWarehouse($user, $o);
    }

    public function delete(User $user, PurchaseOrder $o): bool
    {
        return $this->update($user, $o);
    }

    public function submit(User $user, PurchaseOrder $o): bool
    {
        return $this->update($user, $o);
    }

    /** Pemisahan tugas: yang mengajukan tidak boleh menyetujui PO-nya sendiri. */
    public function approve(User $user, PurchaseOrder $o): bool
    {
        return $this->reviewer($user)
            && $o->status === PurchaseOrderStatus::Submitted
            && $this->inWarehouse($user, $o)
            && $o->submitted_by !== $user->id;
    }

    /** Membuat draft Barang Masuk dari PO. */
    public function receive(User $user, PurchaseOrder $o): bool
    {
        return $this->writer($user) && $o->status->canReceive() && $this->inWarehouse($user, $o);
    }

    public function close(User $user, PurchaseOrder $o): bool
    {
        return $this->reviewer($user)
            && $o->status === PurchaseOrderStatus::PartiallyReceived
            && $this->inWarehouse($user, $o);
    }

    public function cancel(User $user, PurchaseOrder $o): bool
    {
        $cancellable = in_array($o->status, [
            PurchaseOrderStatus::Draft,
            PurchaseOrderStatus::Submitted,
            PurchaseOrderStatus::Approved,
        ], true);

        return $cancellable
            && $this->inWarehouse($user, $o)
            && ($this->reviewer($user) || $this->writer($user));
    }
}
