<section
    class="px-4 py-8 sm:px-8 lg:px-12"
    aria-labelledby="import-herbarium-images-heading"
    x-data="{
        queue: [],
        uploading: false,
        analyzing: false,
        groupStarted: false,
        currentFilename: '',
        progress: 0,
        completed: 0,
        remainingCapacity: @js($remainingCapacity),
        stagedCount: @js($stagedCount),
        failures: [],

        hasDiscardableWork() {
            return this.uploading || this.analyzing || this.queue.length > 0 || this.stagedCount > 0;
        },

        confirmNavigation(event) {
            if (this.hasDiscardableWork() && !window.confirm('Leave this page and discard the staged image batch?')) {
                event.preventDefault();
            }
        },

        async addFiles(fileList) {
            const files = Array.from(fileList || []);
            this.$refs.fileInput.value = '';

            if (files.length === 0) return;

            if (this.uploading || this.analyzing) {
                this.failures.push({
                    filename: 'Selection',
                    message: 'Wait for the current upload group to finish before adding more images.',
                });
                return;
            }

            let serverRemaining;

            try {
                serverRemaining = await $wire.remainingCapacity();
                this.remainingCapacity = serverRemaining;
            } catch (error) {
                this.failures.push({ filename: 'Selection', message: 'Could not check the remaining batch capacity.' });
                return;
            }

            const advisoryAccepted = [];

            files.forEach((file) => {
                if (file.size > 5 * 1024 * 1024) {
                    this.failures.push({ filename: file.name, message: 'The image exceeds the 5 MiB limit.' });
                    return;
                }

                if (!['image/jpeg', 'image/png'].includes(file.type)) {
                    this.failures.push({ filename: file.name, message: 'Choose a JPEG or PNG image.' });
                    return;
                }

                advisoryAccepted.push(file);
            });

            const available = Math.max(0, serverRemaining - this.queue.length);
            const accepted = advisoryAccepted.slice(0, available);
            const rejected = advisoryAccepted.slice(available);

            rejected.forEach((file) => this.failures.push({
                filename: file.name,
                message: 'The batch can contain at most 100 staged images.',
            }));

            if (accepted.length === 0) return;

            if (!this.groupStarted && !this.uploading && !this.analyzing && this.queue.length === 0) {
                this.completed = 0;
            }

            this.queue.push(...accepted);
            this.groupStarted = true;
            this.processNext();
        },

        processNext() {
            if (this.uploading || this.analyzing) return;

            if (this.queue.length === 0) {
                this.finishGroup();
                return;
            }

            const file = this.queue[0];
            this.uploading = true;
            this.currentFilename = file.name;
            this.progress = 0;

            $wire.upload(
                'incomingFile',
                file,
                async () => {
                    try {
                        const result = await $wire.stageIncomingUpload();
                        this.remainingCapacity = result.remaining;
                        this.stagedCount = result.staged_count;

                        if (!result.accepted) {
                            this.failures.push({ filename: file.name, message: result.error });
                        }
                    } catch (error) {
                        this.failures.push({ filename: file.name, message: 'The uploaded file could not be staged.' });
                    } finally {
                        this.completeCurrent();
                    }
                },
                () => {
                    this.failures.push({ filename: file.name, message: 'The file failed during temporary upload.' });
                    this.completeCurrent();
                },
                (event) => {
                    this.progress = event.detail.progress;
                },
                () => {
                    this.failures.push({ filename: file.name, message: 'The temporary upload was cancelled.' });
                    this.completeCurrent();
                },
            );
        },

        completeCurrent() {
            this.completed += 1;
            this.queue.shift();
            this.uploading = false;
            this.currentFilename = '';
            this.progress = 0;
            this.processNext();
        },

        async finishGroup() {
            if (!this.groupStarted || this.analyzing) return;

            this.analyzing = true;

            try {
                await $wire.analyzePendingRows();
                this.remainingCapacity = await $wire.remainingCapacity();
            } catch (error) {
                this.failures.push({ filename: 'Analysis', message: 'Filename analysis could not be completed.' });
            } finally {
                this.groupStarted = false;
                this.analyzing = false;
            }
        },
    }"
    x-on:drop.prevent="addFiles($event.dataTransfer.files)"
    x-on:dragover.prevent
    x-on:staged-batch-state-updated.window="remainingCapacity = Number($event.detail.remainingCapacity); stagedCount = Number($event.detail.stagedCount)"
    x-on:batch-import-finished.window="remainingCapacity = Number($event.detail.remaining); stagedCount = Number($event.detail.stagedCount)"
    x-on:beforeunload.window="if (hasDiscardableWork()) { $event.preventDefault(); $event.returnValue = '' }"
    x-on:livewire:navigate.window="confirmNavigation($event)"
>
    <div class="mx-auto max-w-7xl space-y-6">
        <header>
            <h1
                id="import-herbarium-images-heading"
                class="text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-4xl"
            >
                Import Herbarium Images
            </h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-300 sm:text-base">
                Add up to 100 JPEG or PNG images. Filenames are checked against collection numbers, then every suggestion can be reviewed or changed.
            </p>
        </header>

        <div
            class="rounded-xl border-2 border-dashed border-gray-300 bg-white p-6 text-center shadow-sm transition dark:border-gray-600 dark:bg-gray-900"
            x-bind:class="{ 'pointer-events-none opacity-60': uploading || analyzing || remainingCapacity === 0 }"
        >
            <svg class="mx-auto h-10 w-10 text-gray-400" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5V18a2.25 2.25 0 0 0 2.25 2.25h13.5A2.25 2.25 0 0 0 21 18v-1.5M16.5 8.25 12 3.75m0 0-4.5 4.5M12 3.75V15" />
            </svg>
            <p class="mt-3 font-semibold text-gray-900 dark:text-white">Drop images anywhere in this panel</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">or choose files from your device</p>

            <label
                for="herbarium-image-chooser"
                class="mt-4 inline-flex cursor-pointer items-center rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-600 focus-within:outline-none focus-within:ring-2 focus-within:ring-emerald-500 focus-within:ring-offset-2"
            >
                Choose images
            </label>
            <input
                id="herbarium-image-chooser"
                x-ref="fileInput"
                type="file"
                multiple
                accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                class="sr-only"
                x-on:change="addFiles($event.target.files)"
                x-bind:disabled="uploading || analyzing || remainingCapacity === 0"
            >
            <noscript>
                <p class="mt-3 text-sm text-red-700">JavaScript is required for safe one-file-at-a-time temporary uploads.</p>
            </noscript>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Maximum 5 MiB per image. Client checks are advisory; every image is validated again by the server.
            </p>
        </div>

        <div
            class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40"
            x-show="uploading || analyzing || queue.length > 0"
            x-cloak
            aria-live="polite"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <p class="font-medium text-blue-950 dark:text-blue-100">
                    <span x-show="uploading">Uploading <span x-text="currentFilename"></span></span>
                    <span x-show="analyzing">Analyzing the newly staged filenames…</span>
                </p>
                <p class="text-blue-800 dark:text-blue-200">
                    Completed: <span x-text="completed"></span>
                    <span aria-hidden="true">·</span>
                    Remaining: <span x-text="queue.length"></span>
                </p>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-blue-100 dark:bg-blue-900" x-show="uploading">
                <div class="h-full rounded-full bg-blue-600 transition-all" x-bind:style="`width: ${progress}%`"></div>
            </div>
            <p class="mt-2 text-xs text-blue-800 dark:text-blue-200" x-show="uploading">
                <span x-text="progress"></span>% complete for the current file
            </p>
        </div>

        <div
            class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/40"
            x-show="failures.length > 0"
            x-cloak
            aria-live="polite"
        >
            <div class="flex items-center justify-between gap-4">
                <h2 class="font-semibold text-red-900 dark:text-red-100">Upload failures</h2>
                <button type="button" class="text-sm font-medium text-red-800 underline dark:text-red-200" x-on:click="failures = []">Clear</button>
            </div>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-800 dark:text-red-200">
                <template x-for="(failure, index) in failures" :key="`${failure.filename}-${index}`">
                    <li><span class="font-medium" x-text="failure.filename"></span>: <span x-text="failure.message"></span></li>
                </template>
            </ul>
        </div>

        <div class="grid gap-4 sm:grid-cols-3" aria-label="Batch assignment summary">
            <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">Staged</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $stagedCount }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">Assigned</p>
                <p class="mt-1 text-2xl font-bold text-emerald-700 dark:text-emerald-400">{{ $assignedCount }}</p>
            </div>
            <div class="rounded-lg bg-white p-4 shadow-sm dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">Needs assignment</p>
                <p class="mt-1 text-2xl font-bold text-amber-700 dark:text-amber-400">{{ $unresolvedCount }}</p>
            </div>
        </div>

        @if ($stagedImages === [])
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">No images are staged</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    Choose or drop collection images above. Valid images remain temporary until you leave or refresh this page.
                </p>
            </div>
        @else
            <div class="space-y-4" aria-label="Staged herbarium images">
                @foreach ($stagedImages as $rowKey => $row)
                    <article
                        wire:key="herbarium-image-row-{{ $rowKey }}"
                        data-row-key="{{ $rowKey }}"
                        class="grid gap-5 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 lg:grid-cols-[10rem_minmax(0,1fr)_minmax(18rem,0.8fr)]"
                    >
                        <div>
                            @if (isset($previewUrls[$rowKey]))
                                <img
                                    src="{{ $previewUrls[$rowKey] }}"
                                    alt="Temporary preview of {{ $row['original_filename'] }}"
                                    class="h-40 w-full rounded-lg bg-gray-100 object-contain dark:bg-gray-800"
                                >
                            @else
                                <div class="flex h-40 items-center justify-center rounded-lg bg-gray-100 p-3 text-center text-sm text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                    Temporary preview expired
                                </div>
                            @endif
                        </div>

                        <div class="min-w-0 space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h2 class="break-all font-semibold text-gray-900 dark:text-white">{{ $row['original_filename'] }}</h2>
                                    <span @class([
                                        'mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' => ($row['match_status'] ?? null) === 'matched',
                                        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200' => in_array(($row['match_status'] ?? null), ['unmatched', 'ambiguous'], true),
                                        'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200' => ($row['match_status'] ?? null) === 'invalid',
                                        'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => ($row['match_status'] ?? null) === 'pending',
                                    ])>
                                        {{ ucfirst((string) ($row['match_status'] ?? 'pending')) }}
                                    </span>
                                    @if (($row['match_type'] ?? null) === 'f_fallback')
                                        <span class="ml-2 inline-flex rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800 dark:bg-blue-950 dark:text-blue-200">
                                            F fallback
                                        </span>
                                    @elseif (($row['match_type'] ?? null) === 'exact')
                                        <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">Exact filename match</span>
                                    @endif
                                </div>

                                <button
                                    type="button"
                                    wire:click="removeStagedImage('{{ $rowKey }}')"
                                    wire:loading.attr="disabled"
                                    class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950"
                                    aria-label="Remove {{ $row['original_filename'] }}"
                                >
                                    Remove
                                </button>
                            </div>

                            @if (($row['suggested_herbarium_id'] ?? null) !== null)
                                <div class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                                    <span class="font-semibold">Suggested:</span>
                                    {{ $row['suggested_collection_number'] }}
                                    @if (($row['suggested_botanical_name'] ?? '') !== '')
                                        — {{ $row['suggested_botanical_name'] }}
                                    @endif
                                </div>
                            @endif

                            @if (($row['duplicate_status'] ?? null) === 'duplicate')
                                <div
                                    class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                                    role="status"
                                    data-duplicate-indicator
                                >
                                    <span class="inline-flex rounded-full bg-amber-200 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-amber-900 dark:bg-amber-900 dark:text-amber-100">
                                        Already imported
                                    </span>
                                    <p class="mt-2">{{ $row['duplicate_message'] }}</p>
                                </div>
                            @elseif (($row['duplicate_status'] ?? null) === 'unavailable')
                                <p class="rounded-md bg-gray-100 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-300" role="status">
                                    {{ $row['duplicate_message'] }}
                                </p>
                            @endif

                            @if (($row['match_status'] ?? null) === 'ambiguous' && ($row['candidate_options'] ?? []) !== [])
                                <div class="rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                                    <p class="font-semibold">Ambiguous candidates:</p>
                                    <ul class="mt-1 list-disc pl-5">
                                        @foreach ($row['candidate_options'] as $candidate)
                                            <li>{{ $candidate['label'] }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <p class="text-sm text-gray-600 dark:text-gray-300" aria-live="polite">{{ $row['message'] }}</p>

                            @if (($row['selected_herbarium_id'] ?? null) !== null)
                                <p class="text-sm text-gray-700 dark:text-gray-200">
                                    <span class="font-semibold">Current assignment:</span>
                                    {{ $row['collection_number'] }}
                                    @if (($row['botanical_name'] ?? '') !== '')
                                        — {{ $row['botanical_name'] }}
                                    @endif
                                    <span class="ml-1 text-xs uppercase tracking-wide text-gray-500">({{ $row['assignment_type'] }})</span>
                                </p>
                            @endif
                        </div>

                        <div class="self-center" wire:key="herbarium-selector-{{ $rowKey }}">
                            <x-select
                                label="Herbarium collection"
                                placeholder="Search collection number"
                                wire:model.live="selectedHerbaria.{{ $rowKey }}"
                                :async-data="route('ajax.herbaria')"
                                option-label="label"
                                option-value="id"
                            />
                            @error('selectedHerbaria.'.$rowKey)
                                <p class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $message }}</p>
                            @enderror
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        @if ($batchCompletedAt !== null)
            <section
                @class([
                    'rounded-xl border p-5 shadow-sm',
                    'border-red-300 bg-red-50 text-red-950 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100' => $failedCount > 0 || $errors->has('batch'),
                    'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100' => $failedCount === 0 && ! $errors->has('batch') && $skippedCount > 0,
                    'border-emerald-300 bg-emerald-50 text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100' => $failedCount === 0 && ! $errors->has('batch') && $skippedCount === 0,
                ])
                aria-labelledby="batch-import-result-heading"
                aria-live="polite"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="batch-import-result-heading" class="text-lg font-bold">Batch import result</h2>
                        <p class="mt-1 text-sm">{{ $batchMessage }}</p>
                    </div>
                    <p class="text-xs opacity-80">Completed {{ $batchCompletedAt }}</p>
                </div>

                <dl class="mt-4 grid gap-3 sm:grid-cols-4">
                    <div class="rounded-lg bg-white/70 p-3 dark:bg-black/20">
                        <dt class="text-xs font-semibold uppercase tracking-wide">Imported</dt>
                        <dd class="mt-1 text-2xl font-bold">{{ $importedCount }}</dd>
                    </div>
                    <div class="rounded-lg bg-white/70 p-3 dark:bg-black/20">
                        <dt class="text-xs font-semibold uppercase tracking-wide">Skipped</dt>
                        <dd class="mt-1 text-2xl font-bold">{{ $skippedCount }}</dd>
                    </div>
                    <div class="rounded-lg bg-white/70 p-3 dark:bg-black/20">
                        <dt class="text-xs font-semibold uppercase tracking-wide">Failed</dt>
                        <dd class="mt-1 text-2xl font-bold">{{ $failedCount }}</dd>
                    </div>
                    <div class="rounded-lg bg-white/70 p-3 dark:bg-black/20">
                        <dt class="text-xs font-semibold uppercase tracking-wide">Processed</dt>
                        <dd class="mt-1 text-2xl font-bold">{{ $totalProcessed }}</dd>
                    </div>
                </dl>

                @if ($batchResults !== [])
                    <ul class="mt-4 space-y-2" aria-label="Image import outcomes">
                        @foreach ($batchResults as $resultKey => $result)
                            <li
                                wire:key="batch-result-{{ $resultKey }}"
                                @class([
                                    'rounded-lg border bg-white/70 p-3 text-sm dark:bg-black/20',
                                    'border-emerald-300 dark:border-emerald-800' => $result['outcome'] === 'imported',
                                    'border-amber-300 dark:border-amber-800' => $result['outcome'] === 'skipped',
                                    'border-red-300 dark:border-red-800' => $result['outcome'] === 'failed',
                                ])
                            >
                                <span class="font-bold uppercase tracking-wide">{{ $result['outcome'] }}</span>
                                <span class="ml-1">{{ $result['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <footer class="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="font-semibold text-gray-900 dark:text-white">{{ $assignedCount }} of {{ $stagedCount }} images assigned</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Refreshing or leaving this page discards the staged batch. Abandoned temporary files may remain until Livewire's normal cleanup cycle.
                </p>
                @if ($stagedCount === 0)
                    <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">Stage at least one image before importing.</p>
                @elseif ($unresolvedCount > 0)
                    <p class="mt-2 text-sm font-medium text-amber-700 dark:text-amber-300">
                        Assign a herbarium collection to every staged image to enable Import.
                    </p>
                @endif
            </div>
            <button
                type="button"
                wire:click="importBatch"
                wire:confirm="Import every assigned staged image now? Each image will be processed independently."
                wire:loading.attr="disabled"
                wire:target="importBatch"
                x-bind:disabled="uploading || analyzing || !@js($canImport)"
                @disabled(! $canImport)
                aria-describedby="batch-import-button-help"
                class="inline-flex items-center justify-center rounded-md bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-600 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600 dark:disabled:bg-gray-700 dark:disabled:text-gray-300"
            >
                <span wire:loading.remove wire:target="importBatch">Import assigned images</span>
                <span wire:loading.delay.short wire:target="importBatch">Importing…</span>
            </button>
            <span id="batch-import-button-help" class="sr-only">
                Import is available only when at least one image is staged and every image has a collection assignment.
            </span>
        </footer>
    </div>
</section>
