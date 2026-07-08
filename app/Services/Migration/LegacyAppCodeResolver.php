<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;

class LegacyAppCodeResolver
{
    /** @var array<string, string>|null */
    private ?array $intKeyed = null;

    /** @var array<string, string>|null */
    private ?array $charKeyed = null;

    public function __construct(private readonly string $connection = 'legacy') {}

    public function load(): void
    {
        if ($this->intKeyed !== null) {
            return;
        }

        $this->intKeyed = [];
        $this->charKeyed = [];

        foreach (DB::connection($this->connection)->table('AppCodes')->get() as $row) {
            if ($row->valueint !== null) {
                $this->intKeyed["{$row->codetype}:{$row->valueint}"] = $row->description;
            }

            if ($row->valuechar !== null) {
                $this->charKeyed["{$row->codetype}:{$row->valuechar}"] = $row->description;
            }
        }
    }

    public function label(string $codetype, int|string|null $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $this->load();

        if (is_numeric($code)) {
            $key = "{$codetype}:{$code}";

            return $this->intKeyed[$key] ?? $this->charKeyed[$key] ?? null;
        }

        $key = "{$codetype}:{$code}";

        return $this->charKeyed[$key] ?? $this->intKeyed[$key] ?? null;
    }
}
