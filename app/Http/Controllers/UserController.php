<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\UserRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /** @return array<int> */
    private function warehouseIds(array $data): array
    {
        return collect($data['warehouse_ids'] ?? [])->map(fn ($i) => (int) $i)->unique()->sort()->values()->all();
    }

    private function revokeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $role = Role::tryFrom((string) $request->query('role'))?->value ?? 'all';
        $selfId = $request->user()->id;

        $users = User::query()
            ->with('warehouses:id,code')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($role !== 'all', fn ($q) => $q->where('role', $role))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'is_active' => (bool) $u->is_active,
                'is_self' => $u->id === $selfId,
                'warehouses' => $u->warehouses->pluck('code')->sort()->values()->all(),
                'warehouse_ids' => $u->warehouses->pluck('id')->map(fn ($i) => (int) $i)->all(),
            ]);

        return Inertia::render('users/index', [
            'users' => $users,
            'warehouses' => Warehouse::orderBy('code')->get(['id', 'code', 'name', 'is_active']),
            'roles' => array_map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label()], Role::cases()),
            'filters' => ['search' => $search, 'role' => $role],
        ]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $ids = $this->warehouseIds($data);

        DB::transaction(function () use ($request, $data, $ids) {
            $user = new User();
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
                'is_active' => (bool) $data['is_active'],
                'email_verified_at' => now(), // dibuat Admin, tidak perlu verifikasi email
            ])->save();

            $user->warehouses()->sync($ids);

            AuditLog::record('user.created', $user, $request->user(), [
                'role' => $data['role'],
                'is_active' => (bool) $data['is_active'],
                'warehouses' => $ids,
            ]);
        });

        return back()->with('success', 'Pengguna berhasil dibuat.');
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user();
        $isSelf = $actor->is($user);
        $newRole = Role::from($data['role']);
        $newActive = (bool) $data['is_active'];
        $ids = $this->warehouseIds($data);

        // Mencegah Admin mengunci dirinya sendiri.
        if ($isSelf && ($newRole !== $user->role || !$newActive)) {
            return back()->with('error', 'Anda tidak dapat mengubah role atau menonaktifkan akun Anda sendiri.');
        }

        try {
            DB::transaction(function () use ($user, $data, $actor, $isSelf, $newRole, $newActive, $ids) {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                $wasActiveAdmin = $locked->role === Role::Admin && (bool) $locked->is_active;
                $willBeActiveAdmin = $newRole === Role::Admin && $newActive;

                // Pengaman untuk dua Admin yang saling menurunkan secara bersamaan.
                if ($wasActiveAdmin && !$willBeActiveAdmin) {
                    $others = User::query()
                        ->where('role', Role::Admin->value)
                        ->where('is_active', true)
                        ->whereKeyNot($locked->id)
                        ->lockForUpdate()
                        ->pluck('id');

                    if ($others->isEmpty()) {
                        throw new DomainException('Harus ada minimal satu Admin aktif.');
                    }
                }

                $beforeRole = $locked->role->value;
                $beforeActive = (bool) $locked->is_active;
                $beforeIds = $locked->warehouses()->pluck('warehouses.id')->map(fn ($i) => (int) $i)->sort()->values()->all();

                $locked->forceFill([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'role' => $newRole,
                    'is_active' => $newActive,
                ]);

                if (filled($data['password'] ?? null)) {
                    $locked->forceFill(['password' => Hash::make($data['password'])]);
                }

                $locked->save();
                $locked->warehouses()->sync($ids);

                // Hanya perubahan yang dicatat, dan tidak pernah isi password.
                $meta = array_filter([
                    'role' => $beforeRole !== $newRole->value ? [$beforeRole, $newRole->value] : null,
                    'is_active' => $beforeActive !== $newActive ? [$beforeActive, $newActive] : null,
                    'warehouses' => $beforeIds !== $ids
                        ? ['from' => $beforeIds === [] ? 'none' : $beforeIds, 'to' => $ids === [] ? 'none' : $ids]
                        : null,
                    'profile_changed' => ($locked->wasChanged('name') || $locked->wasChanged('email')) ? true : null,
                    'password_reset' => $locked->wasChanged('password') ? true : null,
                ], fn ($v) => $v !== null);

                if ($meta !== []) {
                    AuditLog::record('user.updated', $locked, $actor, $meta);
                }

                // Akun nonaktif atau password direset: paksa keluar dari semua perangkat.
                if (!$isSelf && (!$newActive || $locked->wasChanged('password'))) {
                    $this->revokeSessions($locked);
                }
            });
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Pengguna berhasil diperbarui.');
    }
}
