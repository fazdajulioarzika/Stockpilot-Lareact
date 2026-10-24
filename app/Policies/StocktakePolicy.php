<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use App\Models\User;

class StocktakePolicy
{
    private function writer(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::WarehouseStaff);
    }

    private function reviewer(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Manager);
    }

    private function inWarehouse(User $user, Stocktake $s): bool
    {
        return $user->canAccessWarehouse($s->warehouse_id);
    }

    public function viewAny(User $user): bool
    {
        return true; // Daftar di-scope ke gudang user di controller.
    }

    public function view(User $user, Stocktake $s): bool
    {
        return $this->inWarehouse($user, $s);
    }

    public function create(User $user): bool
    {
        return $this->writer($user);
    }

    public function update(User $user, Stocktake $s): bool
    {
        return $this->writer($user) && $s->status === StocktakeStatus::Draft && $this->inWarehouse($user, $s);
    }

    public function delete(User $user, Stocktake $s): bool
    {
        return $this->update($user, $s);
    }

    public function start(User $user, Stocktake $s): bool
    {
        return $this->update($user, $s);
    }

    /** Mengisi hasil hitung. */
    public function count(User $user, Stocktake $s): bool
    {
        return $this->writer($user) && $s->status === StocktakeStatus::Counting && $this->inWarehouse($user, $s);
    }

    public function submit(User $user, Stocktake $s): bool
    {
        return $this->count($user, $s);
    }

    /** Pemisahan tugas: yang mengajukan tidak boleh menyetujui hasilnya sendiri. */
    public function approve(User $user, Stocktake $s): bool
    {
        return $this->reviewer($user)
            && $s->status === StocktakeStatus::PendingApproval
            && $this->inWarehouse($user, $s)
            && $s->submitted_by !== $user->id;
    }

    public function reject(User $user, Stocktake $s): bool
    {
        return $this->reviewer($user)
            && $s->status === StocktakeStatus::PendingApproval
            && $this->inWarehouse($user, $s);
    }

    public function cancel(User $user, Stocktake $s): bool
    {
        $cancellable = in_array($s->status, [
            StocktakeStatus::Draft,
            StocktakeStatus::Counting,
            StocktakeStatus::PendingApproval,
        ], true);

        return $cancellable
            && $this->inWarehouse($user, $s)
            && ($this->reviewer($user) || $this->writer($user));
    }
}
