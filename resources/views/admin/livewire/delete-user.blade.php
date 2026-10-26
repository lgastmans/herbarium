<div>
    <x-card title="Delete user">

        <p class="text-sm text-gray-500">Are you sure you want to delete this user?</p>
        <p class="text-sm text-gray-500">This action cannot be undone.</p>

        @if ($errorMessage)
            <p class="mt-4 text-sm text-red-600">{{ $errorMessage }}</p>
        @endif

        <x-slot name="footer">
            <div class="flex justify-between items-center">
                <x-button label="Cancel" secondary wire:click="$dispatch('closeModal')"/>
                <x-button label="Delete" negative icon="document-remove" wire:click="delete" />
            </div>
        </x-slot>
    </x-card>
</div>
