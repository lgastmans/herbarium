<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HerbariumImages;
use Illuminate\View\View;

class HerbariumImageController extends Controller
{
    public function __invoke(HerbariumImages $image): View
    {
        $image->load('herbarium.genus');
        $images = $image->herbarium->images()->orderBy('id')->get();

        return view('admin.herbarium.image-viewer', [
            'image' => $image,
            'previous' => $images->firstWhere('id', '<', $image->id),
            'next' => $images->firstWhere('id', '>', $image->id),
            'currentIndex' => $images->search(fn ($item) => $item->id === $image->id) + 1,
            'total' => $images->count(),
        ]);
    }
}
