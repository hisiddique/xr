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
        return DB::transaction(function () use ($type) {
            $prefix = $this->prefixFor($type);
            $padding = (int) Setting::get('number_padding', 4);

            /** @var Document|null $last */
            $last = Document::withTrashed()
                ->where('type', $type)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $startFloor = ($type === 'DN') ? (int) Setting::get('dn_start_number', 1) : 1;
            $nextSequence = max($last ? $this->extractSequence($last->doc_number) + 1 : 1, $startFloor);

            return sprintf('%s-%s', $prefix, str_pad((string) $nextSequence, $padding, '0', STR_PAD_LEFT));
        });
    }

    /**
     * Build a doc number for a converted document using a known sequence from the source.
     */
    public function numberForConversion(string $targetType, int $sequence): string
    {
        $prefix = $this->prefixFor($targetType);
        $padding = (int) Setting::get('number_padding', 4);

        return sprintf('%s-%s', $prefix, str_pad((string) $sequence, $padding, '0', STR_PAD_LEFT));
    }

    public function extractSequence(?string $docNumber): int
    {
        if (! $docNumber) {
            return 0;
        }

        $parts = explode('-', $docNumber);

        return (int) end($parts);
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
}
