<?php

use Illuminate\Support\Facades\Log;
use Livewire\Exceptions\PropertyNotFoundException;

it('logs the raw request when a PropertyNotFoundException is reported, without changing the response', function () {
    Route::post('/__test-property-not-found', function (): void {
        throw new PropertyNotFoundException('customerId', 'pages::reports.customer-outstanding-payments');
    });

    Log::spy();

    $response = $this->postJson('/__test-property-not-found', ['probe' => 'value']);

    $response->assertStatus(500);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'PropertyNotFoundException diagnostics'
            && $context['raw_body'] === json_encode(['probe' => 'value'])
        );
});
