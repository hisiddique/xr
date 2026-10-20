<?php

use App\Models\Document;

it('orders documents numerically by the part after the prefix', function () {
    foreach (['INV-1441-2', 'INV-0009', 'INV-1441', 'INV-0002', 'INV-1441-1', 'INV-0010'] as $number) {
        Document::factory()->create(['doc_number' => $number]);
    }

    expect(Document::orderByNumberPart('asc')->pluck('doc_number')->all())
        ->toBe(['INV-0002', 'INV-0009', 'INV-0010', 'INV-1441', 'INV-1441-1', 'INV-1441-2']);

    expect(Document::orderByNumberPart('desc')->pluck('doc_number')->all())
        ->toBe(['INV-1441-2', 'INV-1441-1', 'INV-1441', 'INV-0010', 'INV-0009', 'INV-0002']);
});
