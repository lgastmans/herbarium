<?php

namespace App\Services\HerbariumImageStorage;

use App\Models\HerbariumImages;

final class HerbariumImageStorageResult
{
    private function __construct(
        public readonly HerbariumImageStorageStatus $status,
        public readonly HerbariumImages $image,
        public readonly string $checksum,
    ) {
    }

    public static function imported(HerbariumImages $image, string $checksum): self
    {
        return new self(HerbariumImageStorageStatus::Imported, $image, $checksum);
    }

    public static function duplicate(HerbariumImages $image, string $checksum): self
    {
        return new self(HerbariumImageStorageStatus::Duplicate, $image, $checksum);
    }
}
