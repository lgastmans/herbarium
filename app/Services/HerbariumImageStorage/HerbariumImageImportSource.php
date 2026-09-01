<?php

namespace App\Services\HerbariumImageStorage;

enum HerbariumImageImportSource: string
{
    case Cli = 'cli';
    case Batch = 'batch';
    case SingleUploader = 'single_uploader';
}
