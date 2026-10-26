<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class InventoryDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Daftar tetap di-scope ke gudang user di controller.
    }

    public function view(User $user, Model $doc): bool
    {
        return $user->canAccessWarehouse($doc->warehouse_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::WarehouseStaff);
    }

    public function update(User $user, Model $doc): bool
    {
        return $this->create($user)
            && $doc->status === DocumentStatus::Draft
            && $user->canAccessWarehouse($doc->warehouse_id);
    }

    public function post(User $user, Model $doc): bool
    {
        return $this->update($user, $doc);
    }
    public function delete(User $user, Model $doc): bool
    {
        return $this->update($user, $doc);
    }
    public function reverse(User $user, Model $doc): bool
    {
        return $user->hasRole(Role::Admin, Role::Manager)
            && $doc->status === DocumentStatus::Posted
            && $user->canAccessWarehouse($doc->warehouse_id)
            && !$doc->reversal()->exists();
    }
}
