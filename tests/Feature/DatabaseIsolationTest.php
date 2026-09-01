<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_test_environment_resolves_to_an_isolated_database(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        $this->assertSame('mysql', $connection);
        $this->assertIsString($database);
        $this->assertStringEndsWith('_testing', $database);
    }

    public function test_database_server_connects_to_the_guarded_database(): void
    {
        $result = DB::selectOne('select database() as database_name');

        $this->assertSame('dryherbarium_testing', $result->database_name);
    }
}
