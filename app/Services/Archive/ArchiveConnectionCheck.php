<?php

namespace App\Services\Archive;

readonly class ArchiveConnectionCheck
{
    public function __construct(
        public bool $ok,
        public string $message,
        public bool $schemaReady = false,
    ) {}
}
