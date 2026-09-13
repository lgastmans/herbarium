<section
    class="px-4 py-8 sm:px-8 lg:px-12"
    aria-labelledby="import-herbarium-images-heading"
    x-data="{
        queue: [],
        uploading: false,
        waitingBetweenFiles: false,
        analyzing: false,
        groupStarted: false,
        paceTimer: null,
        currentFilename: '',
        progress: 0,
        completed: 0,
        stagedCount: @js($stagedCount),
        failures: [],

        hasDiscardableWork() {
            return this.uploading || this.waitingBetweenFiles || this.analyzing || this.queue.length > 0 || this.stagedCount > 0;
        },

        confirmNavigation(event) {
            if (this.hasDiscardableWork() && !window.confirm('Leave this page and discard the staged image batch?')) {
                event.preventDefault();
            }
        },

        // Alpine's destroy hook can run during a Livewire morph while this
        // component's queue state is preserved, so cancel only on navigation.
        cancelPaceTimer() {
            if (this.paceTimer !== null) {
                window.clearTimeout(this.paceTimer);
                this.paceTimer = null;
            }

            this.waitingBetweenFiles = false;
        },

        async addFiles(fileList) {
            const files = Array.from(fileList || []);
            this.$refs.fileInput.value = '';

            if (files.length === 0) return;

            if (this.uploading || this.waitingBetweenFiles || this.analyzing) {
                this.failures.push({
                    filename: 'Selection',
                    message: 'Wait for the current upload group to finish before adding more images.',
                });
                return;
            }

            const queueWasEmpty = this.queue.length === 0;

            files.forEach((file) => {
                if (file.size > 5 * 1024 * 1024) {
                    this.failures.push({ filename: file.name, message: 'The image exceeds the 5 MiB limit.' });
                    return;
                }

                if (!['image/jpeg', 'image/png'].includes(file.type)) {
                    this.failures.push({ filename: file.name, message: 'Choose a JPEG or PNG image.' });
                    return;
                }

                this.queue.push(file);
            });

            if (this.queue.length === 0) return;

            if (!this.groupStarted && !this.uploading && !this.analyzing && queueWasEmpty) {
                this.completed = 0;
            }

            this.groupStarted = true;
            this.processNext();
        },

        processNext() {
            if (this.uploading || this.waitingBetweenFiles || this.analyzing) return;

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
            if (!this.uploading) return;

            this.completed += 1;
            this.queue.shift();
            this.uploading = false;
            this.currentFilename = '';
            this.progress = 0;

            if (this.queue.length === 0) {
                this.processNext();
                return;
            }

            this.waitingBetweenFiles = true;

            if (this.paceTimer !== null) return;

            this.paceTimer = window.setTimeout(() => {
                this.paceTimer = null;
                this.waitingBetweenFiles = false;
                this.processNext();
            }, 250);
        },

        async finishGroup() {
            if (!this.groupStarted || this.analyzing) return;

            this.analyzing = true;

            try {
                await $wire.analyzePendingRows();
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
    x-on:staged-batch-state-updated.window="stagedCount = Number($event.detail.stagedCount)"
    x-on:batch-import-finished.window="stagedCount = Number($event.detail.stagedCount)"
    x-on:beforeunload.window="if (hasDiscardableWork()) { $event.preventDefault(); $event.returnValue = '' }"
    x-on:livewire:navigate.window="confirmNavigation($event)"
    x-on:livewire:navigating.window="cancelPaceTimer()"
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
                Add JPEG or PNG images. Filenames are checked against collection numbers, then every suggestion can be reviewed or changed.
            </p>
        </header>

        <div
            class="rounded-xl border-2 border-dashed border-gray-300 bg-white p-6 text-center shadow-sm transition dark:border-gray-600 dark:bg-gray-900"
            x-bind:class="{ 'pointer-events-none opacity-60': uploading || waitingBetweenFiles || analyzing }"
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
                x-bind:disabled="uploading || waitingBetweenFiles || analyzing"
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
            x-show="uploading || waitingBetweenFiles || analyzing || queue.length > 0"
            x-cloak
            aria-live="polite"
        >
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <p class="font-medium text-blue-950 dark:text-blue-100">
                    <span x-show="uploading">Uploading <span x-text="currentFilename"></span></span>
                    <span x-show="waitingBetweenFiles">Preparing the next file…</span>
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
            wire:ignore
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
                        class="grid gap-5 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]"
                    >
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
                            @if (($row['match_status'] ?? null) === 'pending')
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Herbarium collection</label>
                                <p class="mt-1 rounded-md border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-400">
                                    Available after filename analysis…
                                </p>
                            @else
                                @php
                                    $selectedLabel = ($row['selected_herbarium_id'] ?? null) === null
                                        ? ''
                                        : trim((string) ($row['collection_number'] ?? '').(($row['botanical_name'] ?? '') === '' ? '' : ' — '.$row['botanical_name']));
                                @endphp
                                <div
                                    wire:ignore
                                    class="relative"
                                    x-data="{
                                        rowKey: @js($rowKey),
                                        endpoint: @js(route('ajax.herbaria')),
                                        query: @js($selectedLabel),
                                        selectedId: @js($row['selected_herbarium_id'] ?? null),
                                        options: [],
                                        open: false,
                                        loading: false,
                                        message: '',
                                        activeIndex: -1,
                                        requestNumber: 0,

                                        async search() {
                                            const term = this.query.trim();
                                            const currentRequest = ++this.requestNumber;
                                            this.message = '';

                                            if (term === '') {
                                                this.options = [];
                                                this.open = false;
                                                this.loading = false;
                                                return;
                                            }

                                            this.loading = true;

                                            try {
                                                const url = new URL(this.endpoint, window.location.origin);
                                                url.searchParams.set('search', term);
                                                const response = await fetch(url, {
                                                    credentials: 'same-origin',
                                                    headers: { 'Accept': 'application/json' },
                                                });

                                                if (!response.ok) throw new Error('search-failed');

                                                const results = await response.json();

                                                if (currentRequest !== this.requestNumber) return;

                                                this.options = Array.isArray(results) ? results : [];
                                                this.activeIndex = this.options.length > 0 ? 0 : -1;
                                                this.open = true;
                                                this.message = this.options.length === 0 ? 'No matching collection found.' : '';
                                            } catch (error) {
                                                if (currentRequest !== this.requestNumber) return;

                                                this.options = [];
                                                this.activeIndex = -1;
                                                this.open = true;
                                                this.message = 'Enter a valid collection number and try again.';
                                            } finally {
                                                if (currentRequest === this.requestNumber) this.loading = false;
                                            }
                                        },

                                        choose(option) {
                                            this.requestNumber += 1;
                                            this.selectedId = option.id;
                                            this.query = option.label;
                                            this.options = [];
                                            this.open = false;
                                            this.message = '';
                                            $wire.set(`selectedHerbaria.${this.rowKey}`, option.id);
                                        },

                                        clear() {
                                            this.requestNumber += 1;
                                            this.selectedId = null;
                                            this.query = '';
                                            this.options = [];
                                            this.open = false;
                                            this.loading = false;
                                            this.message = '';
                                            $wire.set(`selectedHerbaria.${this.rowKey}`, null);
                                            this.$nextTick(() => this.$refs.searchInput.focus());
                                        },

                                        move(step) {
                                            if (!this.open || this.options.length === 0) return;
                                            this.activeIndex = (this.activeIndex + step + this.options.length) % this.options.length;
                                        },

                                        chooseActive() {
                                            if (this.open && this.activeIndex >= 0) this.choose(this.options[this.activeIndex]);
                                        },
                                    }"
                                    x-on:click.outside="open = false"
                                >
                                    <label for="herbarium-collection-{{ $rowKey }}" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Herbarium collection
                                    </label>
                                    <div class="relative mt-1">
                                        <input
                                            id="herbarium-collection-{{ $rowKey }}"
                                            x-ref="searchInput"
                                            x-model="query"
                                            x-on:focus="$event.target.select()"
                                            x-on:input.debounce.300ms="search()"
                                            x-on:keydown.arrow-down.prevent="move(1)"
                                            x-on:keydown.arrow-up.prevent="move(-1)"
                                            x-on:keydown.enter.prevent="chooseActive()"
                                            x-on:keydown.escape="open = false"
                                            type="search"
                                            role="combobox"
                                            autocomplete="off"
                                            placeholder="Search collection number"
                                            x-bind:aria-expanded="open"
                                            aria-autocomplete="list"
                                            class="block w-full rounded-md border-gray-300 pr-20 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                                        >
                                        <div class="absolute inset-y-0 right-2 flex items-center gap-2">
                                            <span x-show="loading" x-cloak class="text-xs text-gray-500 dark:text-gray-400">Searching…</span>
                                            <button
                                                x-show="selectedId !== null || query !== ''"
                                                x-cloak
                                                type="button"
                                                class="text-sm font-medium text-gray-600 underline hover:text-gray-900 dark:text-gray-300 dark:hover:text-white"
                                                x-on:click="clear()"
                                            >
                                                Clear
                                            </button>
                                        </div>
                                    </div>

                                    <div
                                        x-show="open"
                                        x-cloak
                                        class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-800"
                                        role="listbox"
                                    >
                                        <template x-for="(option, index) in options" :key="option.id">
                                            <button
                                                type="button"
                                                role="option"
                                                class="block w-full px-3 py-2 text-left text-sm text-gray-900 hover:bg-emerald-50 dark:text-gray-100 dark:hover:bg-gray-700"
                                                x-bind:class="{ 'bg-emerald-50 dark:bg-gray-700': activeIndex === index }"
                                                x-bind:aria-selected="selectedId === option.id"
                                                x-on:mouseenter="activeIndex = index"
                                                x-on:click="choose(option)"
                                                x-text="option.label"
                                            ></button>
                                        </template>
                                        <p x-show="message !== ''" class="px-3 py-2 text-sm text-gray-600 dark:text-gray-300" x-text="message"></p>
                                    </div>
                                </div>
                            @endif
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
                x-bind:disabled="uploading || waitingBetweenFiles || analyzing || !@js($canImport)"
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
