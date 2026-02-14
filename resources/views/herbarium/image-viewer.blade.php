<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>
        {{ $image->herbarium->genus->name }}
        – {{ $image->herbarium->collection_number }}
    </title>

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-black min-h-screen flex flex-col text-gray-200">

    <!-- Header -->
    <div class="flex justify-between items-start px-6 py-3 bg-black bg-opacity-70">
        <div>
            <h1 class="text-xl font-semibold">
                {{ $image->herbarium->genus->name }}
            </h1>
    
            <p class="text-lg text-gray-400">
                Collection number: {{ $image->herbarium->collection_number }}
            </p>
    
            <p class="text-lg text-gray-500 mt-1">
                ← / → browse images &nbsp;&nbsp;·&nbsp;&nbsp; ESC closes tab
            </p>
        </div>
    
        <div class="text-4xl text-gray-500">
            {{ $currentIndex }} of {{ $total }}
        </div>
    </div>

    <!-- Image + Navigation -->
    <div class="flex-1 flex items-center justify-center relative px-12">

        {{-- Previous --}}
        @if ($previous)
            <a
                href="{{ route('herbarium.image.view', $previous) }}"
                class="absolute left-4 text-white text-4xl hover:text-gray-300 select-none"
                title="Previous image"
            >
                ‹
            </a>
        @endif

        {{-- Image --}}
        <img
            src="{{ asset('storage/herbarium/' . $image->filename) }}"
            alt="Herbarium image"
            class="max-w-full max-h-full object-contain"
        />

        {{-- Next --}}
        @if ($next)
            <a
                href="{{ route('herbarium.image.view', $next) }}"
                class="absolute right-4 text-white text-4xl hover:text-gray-300 select-none"
                title="Next image"
            >
                ›
            </a>
        @endif

    </div>
    
    <script>
        document.addEventListener('keydown', function (event) {
            switch (event.key) {
                case 'ArrowLeft':
                    @if ($previous)
                        window.location.href = "{{ route('herbarium.image.view', $previous) }}";
                    @endif
                    break;
    
                case 'ArrowRight':
                    @if ($next)
                        window.location.href = "{{ route('herbarium.image.view', $next) }}";
                    @endif
                    break;
    
                case 'Escape':
                    window.close();
                    break;
            }
        });
    </script>

</body>
</html>
