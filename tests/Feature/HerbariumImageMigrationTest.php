<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HerbariumImageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_import_columns_and_named_unique_index_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('herbarium_images', [
            'filename',
            'original_filename',
            'checksum',
        ]));

        $filenameColumn = DB::selectOne(<<<'SQL'
            select character_maximum_length as maximum_length
            from information_schema.columns
            where table_schema = database()
              and table_name = 'herbarium_images'
              and column_name = 'filename'
        SQL);

        $checksumColumn = DB::selectOne(<<<'SQL'
            select character_maximum_length as maximum_length, is_nullable as nullable_flag
            from information_schema.columns
            where table_schema = database()
              and table_name = 'herbarium_images'
              and column_name = 'checksum'
        SQL);

        $originalFilenameColumn = DB::selectOne(<<<'SQL'
            select character_maximum_length as maximum_length, is_nullable as nullable_flag
            from information_schema.columns
            where table_schema = database()
              and table_name = 'herbarium_images'
              and column_name = 'original_filename'
        SQL);

        $index = DB::selectOne(<<<'SQL'
            select index_name as resolved_index_name, non_unique as is_non_unique
            from information_schema.statistics
            where table_schema = database()
              and table_name = 'herbarium_images'
              and index_name = 'herbarium_images_herbarium_checksum_unique'
            limit 1
        SQL);

        $this->assertSame(255, (int) $filenameColumn->maximum_length);
        $this->assertSame(255, (int) $originalFilenameColumn->maximum_length);
        $this->assertSame('YES', $originalFilenameColumn->nullable_flag);
        $this->assertSame(64, (int) $checksumColumn->maximum_length);
        $this->assertSame('YES', $checksumColumn->nullable_flag);
        $this->assertSame('herbarium_images_herbarium_checksum_unique', $index->resolved_index_name);
        $this->assertSame(0, (int) $index->is_non_unique);
    }

    public function test_multiple_legacy_null_checksums_do_not_conflict(): void
    {
        DB::table('herbarium_images')->insert([
            [
                'herbarium_id' => 1,
                'genus_id' => 1,
                'filename' => 'legacy-one.jpg',
                'original_filename' => null,
                'checksum' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'herbarium_id' => 1,
                'genus_id' => 1,
                'filename' => 'legacy-two.jpg',
                'original_filename' => null,
                'checksum' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(2, DB::table('herbarium_images')->count());
    }

    public function test_named_constraint_rejects_duplicate_checksum_for_same_herbarium(): void
    {
        $checksum = str_repeat('a', 64);

        DB::table('herbarium_images')->insert([
            'herbarium_id' => 1,
            'genus_id' => 1,
            'filename' => 'first.jpg',
            'checksum' => $checksum,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('herbarium_images_herbarium_checksum_unique');

        DB::table('herbarium_images')->insert([
            'herbarium_id' => 1,
            'genus_id' => 1,
            'filename' => 'second.jpg',
            'checksum' => $checksum,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
