<?php

use App\Services\Archive\ArchiveConnectionValidator;

it('rejects blank connection fields', function () {
    $check = app(ArchiveConnectionValidator::class)->check([
        'host' => '',
        'port' => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
    ]);

    expect($check->ok)->toBeFalse();
    expect($check->message)->toContain('host');
});

it('rejects a target equal to the main database', function () {
    $default = config('database.default');
    $original = config("database.connections.{$default}");

    config([
        "database.connections.{$default}.database" => 'main_db',
        "database.connections.{$default}.host" => '127.0.0.1',
        "database.connections.{$default}.port" => '3306',
    ]);

    try {
        $check = app(ArchiveConnectionValidator::class)->check([
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'main_db',
            'username' => 'u',
            'password' => 'p',
        ]);
    } finally {
        config(["database.connections.{$default}" => $original]);
    }

    expect($check->ok)->toBeFalse();
    expect($check->message)->toContain('different');
});

it('rejects a non-MySQL target', function () {
    config(['database.connections.archive' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    $check = app(ArchiveConnectionValidator::class)->check([
        'host' => 'x',
        'port' => 3306,
        'database' => ':memory:',
        'username' => 'u',
        'password' => 'p',
    ]);

    expect($check->ok)->toBeFalse();
    expect($check->message)->toContain('MySQL');
});
