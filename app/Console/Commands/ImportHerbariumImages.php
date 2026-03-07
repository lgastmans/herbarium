<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Herbarium;
use App\Models\HerbariumImages;

class ImportHerbariumImages extends Command
{
    protected $signature = 'herbarium:import-images {path}';
    protected $description = 'Import herbarium images based on collection number filename';

    public function handle()
    {
        $sourcePath = $this->argument('path');

        if (!is_dir($sourcePath)) {
            $this->error("Directory not found: {$sourcePath}");
            return 1;
        }

        // Build lookup table of normalized collection numbers
        $herbaria = Herbarium::all();
        $lookup = [];

        foreach ($herbaria as $herbarium) {
            $normalized = $this->normalize($herbarium->collection_number);
            $lookup[$normalized] = $herbarium;
        }

        $files = scandir($sourcePath);

        foreach ($files as $file) {

            if (!preg_match('/^([A-Z]\s\d+|\d+)(?:_\d+)?\.(jpg|jpeg|png)$/i', $file, $matches)) {
                $this->error("Invalid filename format: {$file}");
                continue;
            }

            $rawCollection = $matches[1];
            $normalizedFileValue = $this->normalize($rawCollection);

            if (!isset($lookup[$normalizedFileValue])) {
                $this->warn("No match for: {$file}");
                continue;
            }

            $herbarium = $lookup[$normalizedFileValue];

            // Avoid duplicates
            if (HerbariumImages::where('herbarium_id', $herbarium->id)
                ->where('filename', $file)
                ->exists()) {
                $this->line("Already imported: {$file}");
                continue;
            }

            $destination = storage_path("app/public/herbarium/{$file}");

            copy($sourcePath . DIRECTORY_SEPARATOR . $file, $destination);

            HerbariumImages::create([
                'herbarium_id' => $herbarium->id,
                'genus_id'     => $herbarium->genus_id,
                'filename'     => $file,
            ]);

            $this->info("Imported: {$file}");
        }

        $this->info("Import complete.");
        return 0;
    }

    private function normalize($value)
    {
        $value = strtoupper($value);
        $value = preg_replace('/\s+/', '', $value);

        // If purely numeric, remove leading zeros
        if (ctype_digit($value)) {
            $value = ltrim($value, '0');
            if ($value === '') {
                $value = '0';
            }
        }

        return $value;
    }
}
