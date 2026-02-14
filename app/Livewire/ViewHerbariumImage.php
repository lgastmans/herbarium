<?php

namespace App\Livewire;

use App\Models\Herbarium;
use App\Models\HerbariumImages;

use Illuminate\Support\Facades\Storage;

use LivewireUI\Modal\ModalComponent;

class ViewHerbariumImage extends ModalComponent
{
    public $herbarium = null;
    public $id = null;
    public $name = '';
    public $images;

    protected $listeners = [
        'refreshHerbariumImageTable' => 'refreshImages',
    ];

    public function mount(Herbarium $herbarium)
    {
        $this->herbarium = $herbarium;
        
        $this->id = $herbarium->id;
        $this->name = $herbarium->name;
        $this->images = $herbarium->images;
    }

    public function refreshImages()
    {
        $this->herbarium->load('images');
    }

    public function render()
    {
        return view('livewire.view-herbarium-image');
    }

    public static function modalMaxWidth(): string
    {
        return '7xl';
    }

    public function deleteImage(int $imageId)
    {
        $image = HerbariumImages::findOrFail($imageId);

        // Delete file
        Storage::disk('public')->delete('herbarium/' . $image->filename);

        // Delete DB row
        $image->delete();

        // Reload images
        $this->herbarium->load('images');
    }

}
