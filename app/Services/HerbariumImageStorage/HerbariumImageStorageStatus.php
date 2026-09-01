<?php

namespace App\Services\HerbariumImageStorage;

enum HerbariumImageStorageStatus: string
{
    case Imported = 'imported';
    case Duplicate = 'duplicate';
}
