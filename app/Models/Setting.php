<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /** @use HasFactory<\Database\Factories\SettingFactory> */
    use HasFactory;

    protected $fillable = ['key', 'value', 'type'];

    /** @var array<string, mixed> */
    protected static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (isset(static::$cache[$key])) {
            return static::$cache[$key];
        }

        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        $value = static::castValue($setting->value, $setting->type);
        static::$cache[$key] = $value;

        return $value;
    }

    public static function set(string $key, mixed $value, string $type = 'string'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'type' => $type],
        );

        static::$cache[$key] = static::castValue((string) $value, $type);
    }

    public static function flushCache(): void
    {
        static::$cache = [];
    }

    protected static function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer', 'int' => (int) $value,
            'float', 'decimal' => (float) $value,
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
