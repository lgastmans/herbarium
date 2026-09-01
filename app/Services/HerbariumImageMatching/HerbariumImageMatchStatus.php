<?php

namespace App\Services\HerbariumImageMatching;

enum HerbariumImageMatchStatus: string
{
    case Matched = 'matched';
    case Unmatched = 'unmatched';
    case Ambiguous = 'ambiguous';
    case Invalid = 'invalid';
}
