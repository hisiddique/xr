<?php

namespace App\Livewire\Concerns;

trait ValidatesDocumentItems
{
    /**
     * Per-row validation rules for the `items` array.
     *
     * Non-note rows always require a quantity. Price and per (unit) are
     * required together on every document: entering a price obliges a unit,
     * and entering a unit obliges a price (> 0). A line with neither a price
     * nor a unit is fine. Note rows (is_note) are skipped.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function documentItemRules(): array
    {
        $rules = [
            'items' => ['required', 'array', 'min:1'],
            'items.*.details' => ['required', 'string', 'max:500'],
            'items.*.is_note' => ['boolean'],
        ];

        foreach ($this->items as $i => $item) {
            if (! empty($item['is_note'])) {
                continue;
            }

            $rules["items.{$i}.quantity"] = ['required', 'numeric', 'min:0.01'];
            $rules["items.{$i}.price"] = ['nullable', 'numeric', 'min:0'];
            $rules["items.{$i}.per"] = ['nullable', 'string', 'max:20'];

            $hasPrice = (float) ($item['price'] ?? 0) > 0;
            $hasPer = trim((string) ($item['per'] ?? '')) !== '';

            if ($hasPrice) {
                $rules["items.{$i}.per"] = ['required', 'string', 'max:20'];
            }

            if ($hasPer) {
                $rules["items.{$i}.price"] = ['required', 'numeric', 'gt:0'];
            }

            $rules["items.{$i}.discount_percent"] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    /**
     * Validation messages for the item rules above.
     *
     * @return array<string, string>
     */
    protected function documentItemMessages(): array
    {
        return [
            'items.*.quantity.required' => __('Quantity is required.'),
            'items.*.quantity.min' => __('Quantity is required.'),
            'items.*.per.required' => __('Per is required when a price is set.'),
            'items.*.price.required' => __('Price is required when a unit is set.'),
            'items.*.price.gt' => __('Price is required when a unit is set.'),
        ];
    }
}
