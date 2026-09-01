<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! is_string($database) || ! str_ends_with($database, '_testing')) {
            throw new \RuntimeException(
                'Refusing to run database-capable tests: the resolved database name must end in _testing.'
            );
        }

        return $app;
    }
}
