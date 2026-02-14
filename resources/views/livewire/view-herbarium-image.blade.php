<div class="relative w-full max-w-7xl max-h-full p-6">

    <div class="flex flex-row items-start p-4">
        <!-- Title on the left -->
        <div class="w-1/4 pr-4">
            <h1 class="text-2xl font-bold text-center">{{ $herbarium->genus->name }}</h1>
            <!-- <h5 class="mb-2 text-4xl font-bold tracking-tight text-gray-900 dark:text-white text-center">{{ $herbarium->genus->name }}</h5> -->
            <p class="mb-3 text-xl font-normal text-gray-700 dark:text-gray-400 text-center">{{ $herbarium->family->family }}</p>
            <p class="mb-3 text-xl font-normal text-gray-700 dark:text-gray-400 text-center">Collection Number: {{ $herbarium->collection_number }}</p>

            <hr class="h-px my-8 bg-gray-200 border-0 dark:bg-gray-700">
        
            <livewire:upload-herbarium-image :herbarium_id="$herbarium->id" :genus_id="$herbarium->genus_id"/>
        </div>

        <!-- Images on the right -->
        <div class="w-3/4 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 p-4">
            @forelse ($herbarium->images as $image)
                <div class="relative border rounded-lg overflow-hidden shadow-sm hover:shadow-md transition">

                    <!-- Delete button -->
                    <button
                        wire:click="deleteImage({{ $image->id }})"
                        wire:confirm="Are you sure you want to delete this image?"
                        class="absolute top-1 right-1 bg-white bg-opacity-80 rounded-full p-1 text-red-600 hover:bg-red-100 z-10"
                        title="Delete image"
                    >
                        ✕
                    </button>

                    <!-- Image -->
                    <a
                        href="{{ route('herbarium.image.view', $image) }}"
                        target="_blank"
                        rel="noopener"
                        class="block"
                    >
                        <img
                            src="{{ asset('storage/herbarium/' . $image->filename) }}"
                            alt="Herbarium image"
                            class="w-full h-48 object-cover cursor-zoom-in"
                        />
                    </a>

                </div>
            @empty
                <p class="text-gray-500 col-span-full text-center">
                    No images available for this specimen.
                </p>
            @endforelse
        </div>

    </div>

</div>


