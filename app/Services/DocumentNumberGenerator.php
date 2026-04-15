<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class DocumentNumberGenerator
{
    /**
     * Generate the next document number for the given type.
     * Uses a DB transaction with lockForUpdate for atomicity.
     */
    public function nextFor(string $type): string
    {
        $year = now()->year;

        return DB::transaction(function () use ($type, $year) {
            $prefix = $this->prefixFor($type);
            $padding = (int) Setting::get('number_padding', 4);

            /** @var Document|null $last */
            $last = Document::where('type', $type)
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $nextSequence = $last
                ? $this->extractSequence($last->doc_number) + 1
                : 1;

            return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $nextSequence, $padding, '0', STR_PAD_LEFT));
        });
    }

    private function prefixFor(string $type): string
    {
        $settingKey = match ($type) {
            'DN' => 'dn_prefix',
            'INV' => 'inv_prefix',
            default => strtolower($type).'_prefix',
        };

        return (string) Setting::get($settingKey, $type);
    }

    private function extractSequence(?string $docNumber): int
    {
        if (! $docNumber) {
            return 0;
        }

        $parts = explode('-', $docNumber);

        return (int) end($parts);
    }
}
