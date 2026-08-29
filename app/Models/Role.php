<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['slug', 'name', 'description', 'is_system'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The flat list of permission keys granted to this role.
     *
     * @return Collection<int, string>
     */
    public function permissionKeys(): Collection
    {
        return DB::table('role_permission')
            ->where('role_id', $this->id)
            ->pluck('permission_key');
    }

    public function hasPermissionKey(string $key): bool
    {
        return $this->permissionKeys()->contains($key);
    }

    /**
     * Replace this role's permissions with the given keys, pruning any
     * that are not present in the permission catalogue.
     *
     * @param  array<int, string>  $keys
     */
    public function syncPermissions(array $keys): void
    {
        $valid = array_values(array_intersect($keys, static::allPermissionKeys()));

        DB::transaction(function () use ($valid): void {
            DB::table('role_permission')->where('role_id', $this->id)->delete();

            if ($valid === []) {
                return;
            }

            DB::table('role_permission')->insert(array_map(fn (string $key): array => [
                'role_id' => $this->id,
                'permission_key' => $key,
            ], $valid));
        });
    }

    /**
     * The full flattened permission catalogue as "{module}-{action}" keys.
     *
     * @return array<int, string>
     */
    public static function allPermissionKeys(): array
    {
        $keys = [];

        foreach (config('permissions') as $group) {
            foreach ($group['functions'] as $module => $function) {
                foreach ($function['actions'] as $action) {
                    $keys[] = "{$module}-{$action}";
                }
            }
        }

        return $keys;
    }
}
