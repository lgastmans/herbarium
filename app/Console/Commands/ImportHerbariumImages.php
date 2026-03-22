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

        $logFile = storage_path('logs/herbarium-import-' . now()->format('Y-m-d_H-i-s') . '.log');

        file_put_contents($logFile, "Herbarium Image Import\n");
        file_put_contents($logFile, "Started: " . now() . "\n\n", FILE_APPEND);
        
        $this->logMessage($logFile, "Import folder ".$sourcePath);

        $this->info("Import report: {$logFile}");

        // Build lookup table of normalized collection numbers
        $herbaria = Herbarium::all();
        $lookup = [];

        foreach ($herbaria as $herbarium) {
            $normalized = $this->normalize($herbarium->collection_number);
            $lookup[$normalized] = $herbarium;
        }

        $files = scandir($sourcePath);

        $imported = 0;

        foreach ($files as $file) {

            if (!preg_match('/^([A-Z]\s\d+|\d+)(?:_\d+)?\.(jpg|jpeg|png)$/i', $file, $matches)) {
                $message = "Invalid filename format: {$file}";
                $this->error($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            $rawCollection = $matches[1];
            $normalizedFileValue = $this->normalize($rawCollection);

            $herbarium = null;
            $updated = false;

            // Try normal lookup
            if (isset($lookup[$normalizedFileValue])) {
                $herbarium = $lookup[$normalizedFileValue];
            } else {
                // Try fallback: prepend "F "
                $fallbackValue = 'F ' . $rawCollection;
                $normalizedFallback = $this->normalize($fallbackValue);

                if (isset($lookup[$normalizedFallback])) {
                    $herbarium = $lookup[$normalizedFallback];
                    $updated = true;
                }
            }

            // If still not found → log and continue
            if (!$herbarium) {
                $message = "No match for: {$file}";
                $this->warn($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            //$herbarium = $lookup[$normalizedFileValue];

            // Avoid duplicates
            if (HerbariumImages::where('herbarium_id', $herbarium->id)
                ->where('filename', $file)
                ->exists()) {

                $message = "Already imported: {$file}";
                $this->info($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            $destination = storage_path("app/public/herbarium/{$file}");

            copy($sourcePath . DIRECTORY_SEPARATOR . $file, $destination);

            HerbariumImages::create([
                'herbarium_id' => $herbarium->id,
                'genus_id'     => $herbarium->genus_id,
                'filename'     => $file,
            ]);

            $imported++;

            if ($updated) {
                $message = "Updated and imported: {$file}";
            } else {
                $message = "Imported: {$file}";
            }

            $this->info($message);
            $this->logMessage($logFile, $message);

        }

        $this->logMessage($logFile, "");
        $this->logMessage($logFile, "Imported ". $imported." images.");
        $this->logMessage($logFile, "Import completed: " . now());

        $this->info("Report saved to:");
        $this->line($logFile);

        $this->info("Import complete.");

        return 0;
    }

    private function logMessage($file, $message)
    {
        file_put_contents($file, $message . PHP_EOL, FILE_APPEND);
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
