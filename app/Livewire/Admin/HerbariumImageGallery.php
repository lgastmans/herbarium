<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Livewire\Component;
use LivewireUI\Modal\ModalComponent;

class HerbariumImageGallery extends ModalComponent
{
    use RequiresManagementAccess;

    public $selectedImage = null; // Stores the selected image URL

    public function selectImage($image)
    {
        $this->selectedImage = $image;
    }

    public function closeModal()
    {
        $this->selectedImage = null;
    }
    
    public function render()
    {
        return view('admin.livewire.herbarium-image-gallery');
    }
}
