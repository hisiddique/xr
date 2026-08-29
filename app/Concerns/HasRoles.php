<?php

namespace App\Concerns;

use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait HasRoles
{
    /**
     * The permission keys granted to the user across all roles, memoized per request.
     *
     * @var array<int, string>|null
     */
    protected ?array $permissionKeysMemo = null;

    /**
     * The roles assigned to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Determine whether the user has a role with the given slug.
     */
    public function hasRole(string $slug): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains('slug', $slug);
    }

    /**
     * The de-duplicated union of every permission key across the user's roles.
     *
     * @return array<int, string>
     */
    public function permissionKeys(): array
    {
        if ($this->permissionKeysMemo !== null) {
            return $this->permissionKeysMemo;
        }

        $this->loadMissing('roles');

        return $this->permissionKeysMemo = DB::table('role_permission')
            ->whereIn('role_id', $this->roles->pluck('id'))
            ->pluck('permission_key')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Determine whether the user holds the given permission key, either directly
     * or via a "{module}-*" wildcard grant covering the key's module segment.
     */
    public function hasPermission(string $key): bool
    {
        $keys = $this->permissionKeys();

        if (in_array($key, $keys, true)) {
            return true;
        }

        $module = Str::beforeLast($key, '-');

        foreach ($keys as $granted) {
            if (Str::endsWith($granted, '-*') && Str::beforeLast($granted, '-') === $module) {
                return true;
            }
        }

        return false;
    }
}
