<div>
    <label for="catalogue-search" class="mb-2 block">Search by genus, family, collection number or local name</label>
    <input id="catalogue-search" type="search" wire:model.live.debounce.300ms="search"
           class="mb-6 w-full max-w-xl rounded border-stone-300" placeholder="Search the collection">
    <div class="overflow-x-auto rounded border border-stone-200 bg-white">
        <table class="w-full text-left text-sm">
            <thead class="bg-stone-100"><tr>
                <th class="p-3">Collection</th><th class="p-3">Genus</th><th class="p-3">Family</th>
                <th class="p-3">Local name</th><th class="p-3">Location</th>
            </tr></thead>
            <tbody>
            @forelse ($specimens as $specimen)
                <tr wire:key="specimen-{{ $specimen->id }}" class="border-t border-stone-100">
                    <td class="p-3">{{ $specimen->collection_number }}</td>
                    <td class="p-3 italic">{{ $specimen->genus?->name }}</td>
                    <td class="p-3">{{ $specimen->family?->family }}</td>
                    <td class="p-3">{{ $specimen->vernacular_name }}</td>
                    <td class="p-3">{{ $specimen->place?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-6">No specimens found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $specimens->links() }}</div>
</div>
