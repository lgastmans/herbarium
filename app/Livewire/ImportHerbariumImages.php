<?php

namespace App\Livewire;

use App\Exceptions\HerbariumImageImportException;
use App\Models\Herbarium;
use App\Models\HerbariumImages;
use App\Services\HerbariumImageMatching\HerbariumImageMatcher;
use App\Services\HerbariumImageMatching\HerbariumImageMatchStatus;
use App\Services\HerbariumImageMatching\HerbariumImageMatchType;
use App\Services\HerbariumImageStorage\HerbariumImageAssignmentType;
use App\Services\HerbariumImageStorage\HerbariumImageImportSource;
use App\Services\HerbariumImageStorage\HerbariumImageStorageService;
use App\Services\HerbariumImageStorage\HerbariumImageStorageStatus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class ImportHerbariumImages extends Component
{
    use WithFileUploads;

    public const MAX_IMAGES = 100;

    public const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public mixed $incomingFile = null;

    /** @var array<string, array<string, mixed>> */
    public array $stagedImages = [];

    /** @var array<string, int|string|null> */
    public array $selectedHerbaria = [];

    public int $importedCount = 0;

    public int $skippedCount = 0;

    public int $failedCount = 0;

    public int $totalProcessed = 0;

    public ?string $batchCompletedAt = null;

    public ?string $batchMessage = null;

    /** @var array<string, array{key: string, original_filename: string, outcome: string, message: string}> */
    public array $batchResults = [];

    public function mount(): void
    {
        $this->authorizeImport();
    }

    /** @return array{accepted: bool, row_key: string|null, error: string|null, remaining: int, staged_count: int} */
    public function stageIncomingUpload(): array
    {
        $this->authorizeImport();

        if (count($this->stagedImages) >= self::MAX_IMAGES) {
            $this->discardIncomingFile();

            return $this->stagingResponse('This batch already contains the maximum of 100 images.');
        }

        if (! $this->incomingFile instanceof TemporaryUploadedFile) {
            $this->incomingFile = null;

            return $this->stagingResponse('The uploaded file could not be staged. Please try it again.');
        }

        $file = $this->incomingFile;
        $validationError = $this->validateTemporaryImage($file);

        if ($validationError !== null) {
            $this->discardIncomingFile();

            return $this->stagingResponse($validationError);
        }

        $rowKey = (string) Str::uuid();
        $originalFilename = $file->getClientOriginalName();

        $this->stagedImages[$rowKey] = [
            'key' => $rowKey,
            'original_filename' => $originalFilename,
            'temporary_file' => $file,
            'match_status' => 'pending',
            'match_type' => null,
            'candidate_ids' => [],
            'candidate_options' => [],
            'suggested_herbarium_id' => null,
            'suggested_collection_number' => null,
            'suggested_botanical_name' => null,
            'selected_herbarium_id' => null,
            'assignment_type' => null,
            'collection_number' => null,
            'genus' => null,
            'specific_name' => null,
            'botanical_name' => null,
            'duplicate_status' => null,
            'duplicate_message' => null,
            'message' => 'Waiting for filename analysis.',
        ];
        $this->selectedHerbaria[$rowKey] = null;
        $this->incomingFile = null;
        $this->dispatchStagedBatchState();

        return [
            'accepted' => true,
            'row_key' => $rowKey,
            'error' => null,
            'remaining' => $this->remainingCapacity(),
            'staged_count' => $this->stagedCount(),
        ];
    }

    public function analyzePendingRows(HerbariumImageMatcher $matcher): void
    {
        $this->authorizeImport();

        $pendingKeys = array_keys(array_filter(
            $this->stagedImages,
            static fn (mixed $row): bool => is_array($row)
                && ($row['match_status'] ?? null) === 'pending',
        ));

        if ($pendingKeys === []) {
            return;
        }

        $herbaria = Herbarium::query()
            ->select(['id', 'collection_number', 'genus_id', 'specific_id'])
            ->with(['genus:id,name', 'specific:id,name'])
            ->orderBy('collection_number')
            ->orderBy('id')
            ->get();
        $lookup = $matcher->buildLookup($herbaria);
        $automaticDuplicateChecks = [];

        foreach ($pendingKeys as $rowKey) {
            if (! isset($this->stagedImages[$rowKey])) {
                continue;
            }

            $row = $this->stagedImages[$rowKey];

            if (! is_array($row)) {
                continue;
            }

            $result = $matcher->match((string) $row['original_filename'], $lookup);
            $candidateOptions = array_map(
                fn (Herbarium $herbarium): array => $this->herbariumDisplayData($herbarium),
                $result->candidates,
            );
            $matchedHerbarium = $result->matchedHerbarium();

            $row['match_status'] = $result->status->value;
            $row['match_type'] = $result->matchType?->value;
            $row['candidate_ids'] = array_map('intval', $result->candidateIds());
            $row['candidate_options'] = $candidateOptions;
            $row['duplicate_status'] = null;
            $row['duplicate_message'] = null;

            if ($matchedHerbarium !== null) {
                $display = $this->herbariumDisplayData($matchedHerbarium);
                $row['suggested_herbarium_id'] = $display['id'];
                $row['suggested_collection_number'] = $display['collection_number'];
                $row['suggested_botanical_name'] = $display['botanical_name'];
                $row['selected_herbarium_id'] = $display['id'];
                $row['assignment_type'] = HerbariumImageAssignmentType::Automatic->value;
                $row = array_merge($row, $this->selectionDisplayData($display));
                $row['message'] = $result->matchType === HerbariumImageMatchType::FFallback
                    ? 'Matched through the F collection-number fallback. Review the suggested collection.'
                    : 'Exact collection-number match. Review the suggested collection.';
                $this->selectedHerbaria[$rowKey] = $display['id'];

                $checksum = $this->temporaryChecksum($row['temporary_file'] ?? null);

                if ($checksum === null) {
                    $row['duplicate_status'] = 'unavailable';
                    $row['duplicate_message'] = 'The duplicate check is unavailable because the temporary image can no longer be read.';
                } else {
                    $automaticDuplicateChecks[$rowKey] = [
                        'herbarium_id' => $display['id'],
                        'collection_number' => $display['collection_number'],
                        'checksum' => $checksum,
                    ];
                }
            } else {
                $row['message'] = match ($result->status) {
                    HerbariumImageMatchStatus::Ambiguous => 'Several herbarium records match this filename. Select the correct collection.',
                    HerbariumImageMatchStatus::Unmatched => 'No collection-number match was found. Select a collection manually.',
                    HerbariumImageMatchStatus::Invalid => 'The filename does not contain a supported collection number. Select a collection manually.',
                    HerbariumImageMatchStatus::Matched => '',
                };
            }

            $this->stagedImages[$rowKey] = $row;
        }

        $this->applyAutomaticDuplicateIndicators($automaticDuplicateChecks);
    }

    public function updatedSelectedHerbaria(mixed $value, string $rowKey): void
    {
        $this->authorizeImport();

        if (! isset($this->stagedImages[$rowKey]) || ! is_array($this->stagedImages[$rowKey])) {
            unset($this->selectedHerbaria[$rowKey]);

            return;
        }

        if ($value === null || $value === '') {
            $this->selectedHerbaria[$rowKey] = null;
            $this->stagedImages[$rowKey] = array_merge(
                $this->stagedImages[$rowKey],
                [
                    'selected_herbarium_id' => null,
                    'assignment_type' => null,
                    'collection_number' => null,
                    'genus' => null,
                    'specific_name' => null,
                    'botanical_name' => null,
                    'duplicate_status' => null,
                    'duplicate_message' => null,
                    'message' => 'No collection is currently assigned.',
                ],
            );

            return;
        }

        try {
            $validated = validator(
                ['herbarium_id' => $value],
                ['herbarium_id' => ['required', 'integer', 'min:1']],
            )->validate();
        } catch (ValidationException) {
            $this->rejectSelection($rowKey, 'Select a valid herbarium collection.');

            return;
        }

        $herbarium = Herbarium::query()
            ->select(['id', 'collection_number', 'genus_id', 'specific_id'])
            ->with(['genus:id,name', 'specific:id,name'])
            ->find((int) $validated['herbarium_id']);

        if ($herbarium === null) {
            $this->rejectSelection($rowKey, 'The selected herbarium collection no longer exists.');

            return;
        }

        $display = $this->herbariumDisplayData($herbarium);
        $suggestedId = $this->stagedImages[$rowKey]['suggested_herbarium_id'] ?? null;
        $assignmentType = $suggestedId !== null && (int) $suggestedId === $display['id']
            ? HerbariumImageAssignmentType::Automatic->value
            : HerbariumImageAssignmentType::Manual->value;

        $this->resetErrorBag('selectedHerbaria.'.$rowKey);
        $this->selectedHerbaria[$rowKey] = $display['id'];
        $this->stagedImages[$rowKey] = array_merge(
            $this->stagedImages[$rowKey],
            $this->selectionDisplayData($display),
            [
                'selected_herbarium_id' => $display['id'],
                'assignment_type' => $assignmentType,
                'message' => $assignmentType === HerbariumImageAssignmentType::Automatic->value
                    ? 'Automatic filename suggestion selected.'
                    : 'Collection assigned manually.',
            ],
        );
        $this->refreshDuplicateIndicatorForSelection($rowKey, $herbarium);
    }

    /** @return array{remaining: int, staged_count: int} */
    public function removeStagedImage(string $rowKey): array
    {
        $this->authorizeImport();

        $row = $this->stagedImages[$rowKey] ?? null;

        if (! is_array($row)) {
            unset($this->selectedHerbaria[$rowKey]);

            $this->dispatchStagedBatchState();

            return $this->batchClientState();
        }

        $temporaryFile = $row['temporary_file'] ?? null;

        if ($temporaryFile instanceof TemporaryUploadedFile) {
            $this->deleteTemporaryFile($temporaryFile);
        }

        unset($this->stagedImages[$rowKey], $this->selectedHerbaria[$rowKey]);
        $this->resetErrorBag('selectedHerbaria.'.$rowKey);
        $this->dispatchStagedBatchState();

        return $this->batchClientState();
    }

    public function importBatch(
        HerbariumImageMatcher $matcher,
        HerbariumImageStorageService $storageService,
    ): void {
        $this->authorizeImport();

        $administrator = auth()->user();

        if ($administrator === null || ! $administrator->exists) {
            abort(403);
        }

        $administrator->refresh();
        $this->authorizeImport();
        $this->resetBatchResult();

        if ($this->stagedImages === []) {
            $this->rejectBatch('There are no staged images to import.');
            $this->dispatchStagedBatchState();

            return;
        }

        if (count($this->stagedImages) > self::MAX_IMAGES) {
            $this->rejectBatch('A batch cannot contain more than 100 images.');
            $this->dispatchStagedBatchState();

            return;
        }

        $preparedRows = [];
        $preflightFailed = false;

        foreach ($this->stagedImages as $rowKey => $row) {
            $temporaryFile = is_array($row) ? ($row['temporary_file'] ?? null) : null;
            $selectedId = $this->validSelectedHerbariumId($this->selectedHerbaria[$rowKey] ?? null);

            if (! $temporaryFile instanceof TemporaryUploadedFile) {
                $preflightFailed = true;

                if (is_array($row)) {
                    $this->stagedImages[$rowKey]['message'] = 'The temporary upload reference is missing. Remove this row and upload the image again.';
                }
            }

            if ($selectedId === null) {
                $preflightFailed = true;

                if (is_array($row)) {
                    $this->stagedImages[$rowKey]['message'] = 'Assign a valid herbarium collection before importing.';
                }
            }

            if ($temporaryFile instanceof TemporaryUploadedFile && $selectedId !== null) {
                $preparedRows[$rowKey] = [
                    'temporary_file' => $temporaryFile,
                    'selected_herbarium_id' => $selectedId,
                ];
            }
        }

        if ($preflightFailed) {
            $this->rejectBatch('Every staged image must have a temporary file and a valid collection assignment. Nothing was imported.');
            $this->dispatchStagedBatchState();

            return;
        }

        $herbaria = Herbarium::query()
            ->select(['id', 'collection_number', 'genus_id', 'specific_id'])
            ->orderBy('collection_number')
            ->orderBy('id')
            ->get();
        $lookup = $matcher->buildLookup($herbaria);

        foreach ($preparedRows as $rowKey => $preparedRow) {
            /** @var TemporaryUploadedFile $temporaryFile */
            $temporaryFile = $preparedRow['temporary_file'];
            $selectedId = $preparedRow['selected_herbarium_id'];
            $originalFilename = 'Staged image';

            try {
                $originalFilename = $temporaryFile->getClientOriginalName();
                $matchResult = $matcher->match($originalFilename, $lookup);
                $selectedHerbarium = Herbarium::query()->find($selectedId);

                if ($selectedHerbarium === null) {
                    $this->recordFailedOutcome(
                        $rowKey,
                        $temporaryFile,
                        $originalFilename,
                        'The assigned herbarium collection no longer exists. Select another collection and retry.',
                        clearAssignment: true,
                    );

                    continue;
                }

                $suggestedHerbarium = $matchResult->matchedHerbarium();
                $assignmentType = $suggestedHerbarium !== null
                    && (int) $suggestedHerbarium->getKey() === (int) $selectedHerbarium->getKey()
                        ? HerbariumImageAssignmentType::Automatic
                        : HerbariumImageAssignmentType::Manual;
                $storageResult = $storageService->import(
                    $selectedHerbarium,
                    $temporaryFile,
                    $originalFilename,
                    $assignmentType,
                    HerbariumImageImportSource::Batch,
                    $matchResult->matchType,
                    $administrator,
                );

                if ($storageResult->status === HerbariumImageStorageStatus::Duplicate) {
                    $this->recordCompletedOutcome(
                        $rowKey,
                        $temporaryFile,
                        $originalFilename,
                        'skipped',
                        "Skipped {$originalFilename}: the same image already exists for collection {$selectedHerbarium->collection_number}.",
                    );

                    continue;
                }

                $this->recordCompletedOutcome(
                    $rowKey,
                    $temporaryFile,
                    $originalFilename,
                    'imported',
                    "Imported {$originalFilename} for collection {$selectedHerbarium->collection_number}.",
                );
            } catch (HerbariumImageImportException $exception) {
                Log::warning('A staged herbarium image could not be imported.', [
                    'row_key' => $rowKey,
                    'original_filename' => $originalFilename,
                    'selected_herbarium_id' => $selectedId,
                    'exception' => $exception,
                ]);

                $this->recordFailedOutcome(
                    $rowKey,
                    $temporaryFile,
                    $originalFilename,
                    'The image could not be imported safely. Verify the temporary JPEG or PNG and retry.',
                );
            } catch (Throwable $exception) {
                Log::error('An unexpected staged herbarium image import failure occurred.', [
                    'row_key' => $rowKey,
                    'original_filename' => $originalFilename,
                    'selected_herbarium_id' => $selectedId,
                    'exception' => $exception,
                ]);

                $this->recordFailedOutcome(
                    $rowKey,
                    $temporaryFile,
                    $originalFilename,
                    'An unexpected error prevented this image from importing. Please retry.',
                );
            }
        }

        $this->batchCompletedAt = now()->toIso8601String();
        $this->batchMessage = $this->batchOutcomeMessage();
        $this->dispatchStagedBatchState();
        $this->dispatch(
            'batch-import-finished',
            remaining: $this->remainingCapacity(),
            stagedCount: $this->stagedCount(),
        );
    }

    public function stagedCount(): int
    {
        return count($this->stagedImages);
    }

    public function assignedCount(): int
    {
        $assigned = 0;

        foreach ($this->stagedImages as $rowKey => $row) {
            if (is_array($row) && $this->validSelectedHerbariumId($this->selectedHerbaria[$rowKey] ?? null) !== null) {
                $assigned++;
            }
        }

        return $assigned;
    }

    public function unresolvedCount(): int
    {
        return $this->stagedCount() - $this->assignedCount();
    }

    public function remainingCapacity(): int
    {
        return max(0, self::MAX_IMAGES - $this->stagedCount());
    }

    #[Title('Import Herbarium Images')]
    public function render()
    {
        return view('livewire.herbarium.import-images', [
            'stagedCount' => $this->stagedCount(),
            'assignedCount' => $this->assignedCount(),
            'unresolvedCount' => $this->unresolvedCount(),
            'remainingCapacity' => $this->remainingCapacity(),
            'canImport' => $this->stagedCount() > 0 && $this->unresolvedCount() === 0,
            'previewUrls' => $this->temporaryPreviewUrls(),
        ])
            ->layout('layouts.app');
    }

    /** @return array<string, string> */
    private function temporaryPreviewUrls(): array
    {
        $urls = [];

        foreach ($this->stagedImages as $rowKey => $row) {
            $temporaryFile = is_array($row) ? ($row['temporary_file'] ?? null) : null;

            if (! is_string($rowKey)
                || ! Str::isUuid($rowKey)
                || ! $temporaryFile instanceof TemporaryUploadedFile
            ) {
                continue;
            }

            try {
                if (! $temporaryFile->exists()) {
                    continue;
                }

                $urls[$rowKey] = URL::temporarySignedRoute(
                    'herbarium.images.import.preview',
                    now()->addMinutes(10),
                    [
                        'token' => $rowKey,
                        'filename' => $temporaryFile->getFilename(),
                    ],
                );
            } catch (Throwable) {
                // Expired temporary uploads retain the existing preview fallback.
            }
        }

        return $urls;
    }

    private function authorizeImport(): void
    {
        Gate::authorize('import-herbarium-images');
    }

    private function validateTemporaryImage(TemporaryUploadedFile $file): ?string
    {
        $path = $file->getPathname();

        if (! is_file($path) || ! is_readable($path) || ! $file->isValid()) {
            return 'The upload is not a readable file.';
        }

        $size = $file->getSize();

        if ($size === 0) {
            return 'The image is empty.';
        }

        if ($size > self::MAX_FILE_SIZE) {
            return 'The image exceeds the 5 MiB limit.';
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $imageInfo = @getimagesize($path);
        $contents = file_get_contents($path);
        $decodedImage = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if ($decodedImage !== false) {
            imagedestroy($decodedImage);
        }

        $validImage = ($mimeType === 'image/jpeg'
                && is_array($imageInfo)
                && ($imageInfo[2] ?? null) === IMAGETYPE_JPEG
                && $decodedImage !== false)
            || ($mimeType === 'image/png'
                && is_array($imageInfo)
                && ($imageInfo[2] ?? null) === IMAGETYPE_PNG
                && $decodedImage !== false);

        return $validImage ? null : 'Only valid JPEG and PNG image content can be staged.';
    }

    /** @return array{accepted: bool, row_key: null, error: string, remaining: int, staged_count: int} */
    private function stagingResponse(string $error): array
    {
        $this->dispatchStagedBatchState();

        return [
            'accepted' => false,
            'row_key' => null,
            'error' => $error,
            'remaining' => $this->remainingCapacity(),
            'staged_count' => $this->stagedCount(),
        ];
    }

    /** @return array{remaining: int, staged_count: int} */
    private function batchClientState(): array
    {
        return [
            'remaining' => $this->remainingCapacity(),
            'staged_count' => $this->stagedCount(),
        ];
    }

    private function dispatchStagedBatchState(): void
    {
        $this->dispatch(
            'staged-batch-state-updated',
            remainingCapacity: $this->remainingCapacity(),
            stagedCount: $this->stagedCount(),
        );
    }

    private function discardIncomingFile(): void
    {
        if ($this->incomingFile instanceof TemporaryUploadedFile) {
            $this->deleteTemporaryFile($this->incomingFile);
        }

        $this->incomingFile = null;
    }

    private function deleteTemporaryFile(TemporaryUploadedFile $file): void
    {
        try {
            if ($file->exists()) {
                $file->delete();
            }
        } catch (Throwable) {
            // Expired or concurrently cleaned temporary uploads are already gone.
        }
    }

    private function validSelectedHerbariumId(mixed $value): ?int
    {
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        return $validated === false ? null : $validated;
    }

    private function resetBatchResult(): void
    {
        $this->importedCount = 0;
        $this->skippedCount = 0;
        $this->failedCount = 0;
        $this->totalProcessed = 0;
        $this->batchCompletedAt = null;
        $this->batchMessage = null;
        $this->batchResults = [];
        $this->resetErrorBag('batch');
    }

    private function rejectBatch(string $message): void
    {
        $this->batchMessage = $message;
        $this->batchCompletedAt = now()->toIso8601String();
        $this->addError('batch', $message);
    }

    private function recordCompletedOutcome(
        string $rowKey,
        TemporaryUploadedFile $temporaryFile,
        string $originalFilename,
        string $outcome,
        string $message,
    ): void {
        if ($outcome === 'imported') {
            $this->importedCount++;
        } else {
            $this->skippedCount++;
        }

        $this->totalProcessed++;
        $this->batchResults[$rowKey] = $this->batchResultRow(
            $rowKey,
            $originalFilename,
            $outcome,
            $message,
        );
        $this->deleteTemporaryFile($temporaryFile);
        unset($this->stagedImages[$rowKey], $this->selectedHerbaria[$rowKey]);
        $this->resetErrorBag('selectedHerbaria.'.$rowKey);
    }

    private function recordFailedOutcome(
        string $rowKey,
        TemporaryUploadedFile $temporaryFile,
        string $originalFilename,
        string $message,
        bool $clearAssignment = false,
    ): void {
        $this->failedCount++;
        $this->totalProcessed++;
        $this->batchResults[$rowKey] = $this->batchResultRow(
            $rowKey,
            $originalFilename,
            'failed',
            $message,
        );

        if (! $this->temporaryFileExists($temporaryFile) || ! isset($this->stagedImages[$rowKey])) {
            unset($this->stagedImages[$rowKey], $this->selectedHerbaria[$rowKey]);

            return;
        }

        $this->stagedImages[$rowKey]['message'] = $message;

        if ($clearAssignment) {
            $this->selectedHerbaria[$rowKey] = null;
            $this->stagedImages[$rowKey] = array_merge(
                $this->stagedImages[$rowKey],
                [
                    'selected_herbarium_id' => null,
                    'assignment_type' => null,
                    'collection_number' => null,
                    'genus' => null,
                    'specific_name' => null,
                    'botanical_name' => null,
                    'duplicate_status' => null,
                    'duplicate_message' => null,
                ],
            );
        }
    }

    private function temporaryFileExists(TemporaryUploadedFile $file): bool
    {
        try {
            return $file->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, array{herbarium_id: int, collection_number: string, checksum: string}>  $checks
     */
    private function applyAutomaticDuplicateIndicators(array $checks): void
    {
        if ($checks === []) {
            return;
        }

        $checksumsByHerbarium = [];

        foreach ($checks as $check) {
            $checksumsByHerbarium[$check['herbarium_id']][$check['checksum']] = true;
        }

        $existingPairs = HerbariumImages::query()
            ->select(['herbarium_id', 'checksum'])
            ->where(function ($query) use ($checksumsByHerbarium): void {
                foreach ($checksumsByHerbarium as $herbariumId => $checksums) {
                    $query->orWhere(function ($pairQuery) use ($herbariumId, $checksums): void {
                        $pairQuery
                            ->where('herbarium_id', $herbariumId)
                            ->whereIn('checksum', array_keys($checksums));
                    });
                }
            })
            ->get()
            ->mapWithKeys(
                static fn (HerbariumImages $image): array => [
                    $image->herbarium_id.'|'.$image->checksum => true,
                ],
            );

        foreach ($checks as $rowKey => $check) {
            if (! isset($this->stagedImages[$rowKey])) {
                continue;
            }

            $isDuplicate = $existingPairs->has($check['herbarium_id'].'|'.$check['checksum']);
            $this->setDuplicateIndicator($rowKey, $check['collection_number'], $isDuplicate);
        }
    }

    private function refreshDuplicateIndicatorForSelection(string $rowKey, Herbarium $herbarium): void
    {
        $this->stagedImages[$rowKey]['duplicate_status'] = null;
        $this->stagedImages[$rowKey]['duplicate_message'] = null;
        $checksum = $this->temporaryChecksum($this->stagedImages[$rowKey]['temporary_file'] ?? null);

        if ($checksum === null) {
            $this->stagedImages[$rowKey]['duplicate_status'] = 'unavailable';
            $this->stagedImages[$rowKey]['duplicate_message'] = 'The duplicate check is unavailable because the temporary image can no longer be read.';

            return;
        }

        $isDuplicate = HerbariumImages::query()
            ->where('herbarium_id', $herbarium->getKey())
            ->where('checksum', $checksum)
            ->exists();

        $this->setDuplicateIndicator(
            $rowKey,
            (string) $herbarium->collection_number,
            $isDuplicate,
        );
    }

    private function setDuplicateIndicator(string $rowKey, string $collectionNumber, bool $isDuplicate): void
    {
        $this->stagedImages[$rowKey]['duplicate_status'] = $isDuplicate ? 'duplicate' : 'unique';
        $this->stagedImages[$rowKey]['duplicate_message'] = $isDuplicate
            ? "Already imported — this exact image already exists for collection {$collectionNumber} and will be skipped."
            : null;
    }

    private function temporaryChecksum(mixed $temporaryFile): ?string
    {
        if (! $temporaryFile instanceof TemporaryUploadedFile) {
            return null;
        }

        try {
            $path = $temporaryFile->getPathname();

            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $checksum = hash_file('sha256', $path);

            return is_string($checksum) ? $checksum : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{key: string, original_filename: string, outcome: string, message: string} */
    private function batchResultRow(
        string $rowKey,
        string $originalFilename,
        string $outcome,
        string $message,
    ): array {
        return [
            'key' => $rowKey,
            'original_filename' => $originalFilename,
            'outcome' => $outcome,
            'message' => $message,
        ];
    }

    private function batchOutcomeMessage(): string
    {
        if ($this->failedCount === 0 && $this->stagedImages === []) {
            return 'All processed images completed and the staged batch is now empty.';
        }

        if ($this->failedCount > 0) {
            return 'The batch finished with one or more failures. Review the retained rows and retry when ready.';
        }

        return 'The batch confirmation finished.';
    }

    /** @return array{id: int, collection_number: string, genus: string, specific_name: string|null, botanical_name: string, label: string} */
    private function herbariumDisplayData(Herbarium $herbarium): array
    {
        $genus = trim((string) ($herbarium->genus?->name ?? ''));
        $specificName = $herbarium->specific?->name;
        $specificName = $specificName === null ? null : trim((string) $specificName);
        $botanicalName = trim($genus.' '.($specificName ?? ''));
        $collectionNumber = (string) $herbarium->collection_number;

        return [
            'id' => (int) $herbarium->getKey(),
            'collection_number' => $collectionNumber,
            'genus' => $genus,
            'specific_name' => $specificName,
            'botanical_name' => $botanicalName,
            'label' => $botanicalName === ''
                ? $collectionNumber
                : $collectionNumber.' — '.$botanicalName,
        ];
    }

    /** @param array{id: int, collection_number: string, genus: string, specific_name: string|null, botanical_name: string, label: string} $display */
    private function selectionDisplayData(array $display): array
    {
        return [
            'collection_number' => $display['collection_number'],
            'genus' => $display['genus'],
            'specific_name' => $display['specific_name'],
            'botanical_name' => $display['botanical_name'],
        ];
    }

    private function rejectSelection(string $rowKey, string $message): void
    {
        $currentSelection = $this->stagedImages[$rowKey]['selected_herbarium_id'] ?? null;
        $this->selectedHerbaria[$rowKey] = $currentSelection;
        $this->stagedImages[$rowKey]['message'] = $message;
        $this->addError('selectedHerbaria.'.$rowKey, $message);
    }
}
