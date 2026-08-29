<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use App\Support\RoleCatalog;
use App\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'role' => UserRole::Admin,
        ];
    }

    /**
     * Attach the role matching the user's final `role` enum value, but only
     * when nothing else has already granted the user a role.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->roles()->exists()) {
                return;
            }

            $this->attachRole($user, $user->role->value);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function admin(): static
    {
        return $this->withRole('admin', UserRole::Admin);
    }

    public function staff(): static
    {
        return $this->withRole('staff', UserRole::Staff);
    }

    /**
     * Create the user without any roles attached.
     */
    public function noRoles(): static
    {
        return $this->afterCreating(fn (User $user) => $user->roles()->detach());
    }

    protected function withRole(string $slug, UserRole $enum): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $enum,
        ])->afterMaking(function (User $user) use ($slug) {
            if ($user->relationLoaded('roles')) {
                return;
            }

            $roles = Role::whereSlug($slug)->get();

            if ($roles->isEmpty()) {
                $roles = collect([new Role(['slug' => $slug, 'name' => ucfirst($slug)])]);
            }

            $user->setRelation('roles', $roles);
        })->afterCreating(function (User $user) use ($slug) {
            $this->attachRole($user, $slug);
        });
    }

    /**
     * Ensure the built-in role for the given slug exists with its full
     * permission set, then attach it to the user.
     */
    private function attachRole(User $user, string $slug): void
    {
        $definition = collect(RoleCatalog::definitions())->firstWhere('slug', $slug) ?? [
            'name' => ucfirst($slug),
            'description' => '',
            'is_system' => true,
        ];

        $role = Role::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'is_system' => $definition['is_system'],
            ],
        );

        $role->syncPermissions(RoleCatalog::permissionsFor($slug));

        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->unsetRelation('roles');
    }
}
