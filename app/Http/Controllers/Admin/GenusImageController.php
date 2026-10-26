<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Genus;
use App\Models\GenusImage;
use Illuminate\Http\Request;

class GenusImageController extends Controller
{
    public function index()
    {
        return view('admin.genus-images', [
            'images' => GenusImage::all(),
            'genera' => Genus::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'genus_id' => 'required|integer|exists:genus,id',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        // The legacy filename column is limited to 32 characters.
        $file = $request->file('image');
        $filename = bin2hex(random_bytes(12)).'.'.$file->extension();
        $file->storeAs('photos', $filename, 'public');
        GenusImage::create(['genus_id' => $validated['genus_id'], 'filename' => $filename]);

        return redirect()->route('admin.genus-images.index');
    }
}
