<x-admin.app-layout>

    <div class="py-12 px-24">

        <form action="{{ route('admin.genus-images.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <label for="genus-image-genus">Genus</label>
            <select id="genus-image-genus" name="genus_id" required>
                <option value="">Select a genus</option>
                @foreach ($genera as $genus)
                    <option value="{{ $genus->id }}">{{ $genus->name }}</option>
                @endforeach
            </select>
            <x-errors />
            <input type="file" name="image" required>
            <button type="submit">Upload</button>
        </form>

        <div class="grid">
            @foreach ($images as $image)
                <img src="{{ asset('photos/' . $image->filename) }}" alt="Image">
            @endforeach
        </div>

    </div>

</x-admin.app-layout>