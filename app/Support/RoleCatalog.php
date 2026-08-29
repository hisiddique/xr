<?php

namespace App\Support;

final class RoleCatalog
{
    /**
     * The built-in role definitions, in display order, each with its fully
     * computed permission-key set.
     *
     * @return array<int, array{slug: string, name: string, description: string, is_system: bool, permissions: array<int, string>}>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => 'sysadmin',
                'name' => 'System Administrator',
                'description' => 'Full unrestricted access.',
                'is_system' => true,
                'permissions' => [],
            ],
            [
                'slug' => 'admin',
                'name' => 'Administrator',
                'description' => 'Full access to every feature, including user and role management.',
                'is_system' => true,
                'permissions' => self::allKeys(),
            ],
            [
                'slug' => 'staff',
                'name' => 'Staff',
                'description' => 'Day-to-day sales and purchasing operations.',
                'is_system' => true,
                'permissions' => array_merge(
                    self::keysForModules(['customer', 'deliverynote', 'invoice', 'creditnote', 'payment', 'documentsearch']),
                    self::keysForModulesActions(
                        ['supplier', 'supplierinvoice', 'supplierdebitnote', 'supplierpayout', 'overhead'],
                        ['index', 'show'],
                    ),
                    self::keysForModules(['report', 'export']),
                ),
            ],
            [
                'slug' => 'clerk',
                'name' => 'Clerk',
                'description' => 'Read-only access across sales and purchasing, plus delivery-note capture.',
                'is_system' => false,
                'permissions' => array_merge(
                    self::keysForModulesActions(
                        [
                            'customer', 'deliverynote', 'invoice', 'creditnote',
                            'supplier', 'supplierinvoice', 'supplierdebitnote', 'supplierpayout', 'overhead',
                        ],
                        ['index', 'show'],
                    ),
                    ['documentsearch-index', 'deliverynote-create'],
                ),
            ],
        ];
    }

    /**
     * The permission-key set for a single built-in role, or an empty array
     * when the slug is not a built-in role.
     *
     * @return array<int, string>
     */
    public static function permissionsFor(string $slug): array
    {
        foreach (self::definitions() as $definition) {
            if ($definition['slug'] === $slug) {
                return $definition['permissions'];
            }
        }

        return [];
    }

    /**
     * The full flattened permission catalogue as "{module}-{action}" keys.
     *
     * @return array<int, string>
     */
    private static function allKeys(): array
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

    /**
     * Flattened "{module}-{action}" keys for whole permission groups.
     *
     * @param  array<int, string>  $groupKeys
     * @return array<int, string>
     */
    private static function keysForGroups(array $groupKeys): array
    {
        $keys = [];

        foreach (config('permissions') as $groupKey => $group) {
            if (! in_array($groupKey, $groupKeys, true)) {
                continue;
            }

            foreach ($group['functions'] as $module => $function) {
                foreach ($function['actions'] as $action) {
                    $keys[] = "{$module}-{$action}";
                }
            }
        }

        return $keys;
    }

    /**
     * Flattened keys for whole modules, regardless of group.
     *
     * @param  array<int, string>  $modules
     * @return array<int, string>
     */
    private static function keysForModules(array $modules): array
    {
        $keys = [];

        foreach (config('permissions') as $group) {
            foreach ($group['functions'] as $module => $function) {
                if (! in_array($module, $modules, true)) {
                    continue;
                }

                foreach ($function['actions'] as $action) {
                    $keys[] = "{$module}-{$action}";
                }
            }
        }

        return $keys;
    }

    /**
     * Flattened keys restricted to the intersection of the given modules
     * and the given actions.
     *
     * @param  array<int, string>  $modules
     * @param  array<int, string>  $actions
     * @return array<int, string>
     */
    private static function keysForModulesActions(array $modules, array $actions): array
    {
        return array_values(array_filter(
            self::keysForModules($modules),
            function (string $key) use ($actions): bool {
                $action = substr($key, strrpos($key, '-') + 1);

                return in_array($action, $actions, true);
            },
        ));
    }
}
