<div>
    <form wire:submit="save" class="space-y-3">
        <div>
            <label for="single-herbarium-image-{{ $herbariumId }}" class="mb-2 block text-sm font-medium text-gray-900 dark:text-gray-100">
                Add herbarium image
            </label>
            <input
                id="single-herbarium-image-{{ $herbariumId }}"
                type="file"
                wire:model="photo"
                accept="image/jpeg,image/png,.jpg,.jpeg,.png"
                class="block w-full cursor-pointer rounded-lg border border-gray-300 bg-gray-50 text-sm text-gray-900 focus:outline-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300"
            >
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">JPEG or PNG, maximum 5 MiB.</p>

            @error('photo')
                <p class="mt-2 text-sm text-red-700 dark:text-red-300" role="alert">{{ $message }}</p>
            @enderror
        </div>

        @if ($statusMessage !== null)
            <p
                @class([
                    'rounded-md p-3 text-sm',
                    'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200' => $statusType === 'success',
                    'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200' => $statusType === 'duplicate',
                ])
                role="status"
                aria-live="polite"
            >
                {{ $statusMessage }}
            </p>
        @endif

        <x-button
            label="Upload"
            positive
            md
            blue
            icon="save"
            type="submit"
            wire:loading.attr="disabled"
            wire:target="photo,save"
        />
    </form>
</div>
