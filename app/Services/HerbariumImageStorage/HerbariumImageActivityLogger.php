<?php

namespace App\Services\HerbariumImageStorage;

use App\Models\HerbariumImages;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class HerbariumImageActivityLogger
{
    /** @param  array<string, string|null>  $properties */
    public function log(HerbariumImages $image, array $properties, ?Model $causer): void
    {
        $logger = activity()
            ->performedOn($image)
            ->withProperties($properties);

        if ($causer !== null) {
            $logger->causedBy($causer);
        } else {
            $logger->causedByAnonymous();
        }

        if ($logger->log('Herbarium image imported.') === null) {
            throw new RuntimeException('Mandatory herbarium image activity logging is disabled.');
        }
    }
}
