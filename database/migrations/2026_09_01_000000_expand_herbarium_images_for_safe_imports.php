<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECKSUM_UNIQUE_INDEX = 'herbarium_images_herbarium_checksum_unique';

    public function up(): void
    {
        Schema::table('herbarium_images', function (Blueprint $table) {
            $table->string('filename', 255)->change();
            $table->string('original_filename', 255)->nullable()->after('filename');
            $table->char('checksum', 64)->nullable()->after('original_filename');
            $table->unique(
                ['herbarium_id', 'checksum'],
                self::CHECKSUM_UNIQUE_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('herbarium_images', function (Blueprint $table) {
            $table->dropUnique(self::CHECKSUM_UNIQUE_INDEX);
            $table->dropColumn(['original_filename', 'checksum']);

            // This change can fail if filenames longer than 32 characters were
            // imported after up() ran. Shorten those values before rolling back.
            $table->string('filename', 32)->change();
        });
    }
};
