<?php

namespace App\Services\HerbariumImageStorage;

enum HerbariumImageAssignmentType: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
}
