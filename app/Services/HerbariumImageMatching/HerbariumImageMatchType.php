<?php

namespace App\Services\HerbariumImageMatching;

enum HerbariumImageMatchType: string
{
    case Exact = 'exact';
    case FFallback = 'f_fallback';
}
