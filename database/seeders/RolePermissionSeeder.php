<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\RoleCatalog;
use App\UserRole;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Create the system roles, grant each its permission set, and backfill
     * the role_user pivot from the legacy users.role enum. Safe to re-run.
     */
    public function run(): void
    {
        $rolesBySlug = [];

        foreach (RoleCatalog::definitions() as $definition) {
            $role = Role::updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => $definition['is_system'],
                ],
            );

            $role->syncPermissions($definition['permissions']);

            $rolesBySlug[$definition['slug']] = $role;
        }

        $this->backfillPivot($rolesBySlug['admin'], $rolesBySlug['staff']);
    }

    /**
     * Attach the admin or staff role to every existing user based on the
     * legacy users.role enum, without detaching manually-assigned roles.
     */
    protected function backfillPivot(Role $adminRole, Role $staffRole): void
    {
        User::query()->eachById(function (User $user) use ($adminRole, $staffRole): void {
            $isAdmin = $user->role === UserRole::Admin;

            $user->roles()->syncWithoutDetaching([
                $isAdmin ? $adminRole->id : $staffRole->id,
            ]);
        });
    }
}
