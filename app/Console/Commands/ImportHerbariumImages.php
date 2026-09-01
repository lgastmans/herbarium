<?php

namespace App\Console\Commands;

use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Exceptions\HerbariumImageImportException;
use App\Services\HerbariumImageMatching\HerbariumImageMatcher;
use App\Services\HerbariumImageMatching\HerbariumImageMatchStatus;
use App\Services\HerbariumImageMatching\HerbariumImageMatchType;
use App\Services\HerbariumImageStorage\HerbariumImageAssignmentType;
use App\Services\HerbariumImageStorage\HerbariumImageImportSource;
use App\Services\HerbariumImageStorage\HerbariumImageStorageService;
use App\Services\HerbariumImageStorage\HerbariumImageStorageStatus;
use Illuminate\Console\Command;
use Symfony\Component\HttpFoundation\File\File;
use Throwable;

class ImportHerbariumImages extends Command
{
    protected $signature = 'herbarium:import-images {path}';
    protected $description = 'Import herbarium images based on collection number filename';

    public function handle(
        HerbariumImageMatcher $matcher,
        HerbariumImageStorageService $storageService,
    )
    {
        $sourcePath = $this->argument('path');

        if (! is_dir($sourcePath)) {
            $this->error("Directory not found: {$sourcePath}");

            return 1;
        }

        $logFile = storage_path('logs/herbarium-import-'.now()->format('Y-m-d_H-i-s').'.log');

        file_put_contents($logFile, "Herbarium Image Import\n");
        file_put_contents($logFile, 'Started: '.now()."\n\n", FILE_APPEND);

        $this->logMessage($logFile, 'Import folder '.$sourcePath);

        $this->info("Import report: {$logFile}");

        $lookup = $matcher->buildLookup($this->herbariumCandidates());

        $files = scandir($sourcePath);

        $imported = 0;

        foreach ($files as $file) {
            $result = $matcher->match($file, $lookup);

            if ($result->status === HerbariumImageMatchStatus::Invalid) {
                $message = "Invalid filename format: {$file}";
                $this->error($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            if ($result->status === HerbariumImageMatchStatus::Ambiguous) {
                $message = "Ambiguous match for: {$file} (herbarium IDs: ".implode(', ', $result->candidateIds()).')';
                $this->warn($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            if ($result->status === HerbariumImageMatchStatus::Unmatched) {
                $message = "No match for: {$file}";
                $this->warn($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            $herbarium = $result->matchedHerbarium();
            $updated = $result->matchType === HerbariumImageMatchType::FFallback;

            // Preserve duplicate detection for legacy records whose database
            // filename still equals the source filename.
            if (HerbariumImages::where('herbarium_id', $herbarium->id)
                ->where('filename', $file)
                ->exists()) {
                $message = "Already imported: {$file}";
                $this->info($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            try {
                $storageResult = $storageService->import(
                    $herbarium,
                    new File($sourcePath.DIRECTORY_SEPARATOR.$file),
                    $file,
                    HerbariumImageAssignmentType::Automatic,
                    HerbariumImageImportSource::Cli,
                    $result->matchType,
                );
            } catch (HerbariumImageImportException $exception) {
                $message = "Failed to import: {$file} ({$exception->getMessage()})";
                $this->error($message);
                $this->logMessage($logFile, $message);

                continue;
            } catch (Throwable $exception) {
                $message = "Failed to import: {$file} ({$exception->getMessage()})";
                $this->error($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            if ($storageResult->status === HerbariumImageStorageStatus::Duplicate) {
                $message = "Already imported: {$file}";
                $this->info($message);
                $this->logMessage($logFile, $message);

                continue;
            }

            $imported++;

            if ($updated) {
                $message = "Updated and imported: {$file}";
            } else {
                $message = "Imported: {$file}";
            }

            $this->info($message);
            $this->logMessage($logFile, $message);
        }

        $this->logMessage($logFile, '');
        $this->logMessage($logFile, 'Imported '.$imported.' images.');
        $this->logMessage($logFile, 'Import completed: '.now());

        $this->info('Report saved to:');
        $this->line($logFile);

        $this->info('Import complete.');

        return 0;
    }

    private function logMessage($file, $message)
    {
        file_put_contents($file, $message.PHP_EOL, FILE_APPEND);
    }

    protected function herbariumCandidates(): iterable
    {
        return Herbarium::all();
    }
}
