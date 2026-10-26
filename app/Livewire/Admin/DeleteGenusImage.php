<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use App\Models\GenusImage;
use LivewireUI\Modal\ModalComponent;
use Illuminate\Support\Facades\Storage;

class DeleteGenusImage extends ModalComponent
{
    use RequiresManagementAccess;

    public GenusImage $GenusImage;

    public $id;

    public function delete()
    {
        $genus_image = GenusImage::findOrFail($this->id);

        //$filename = $genus_image->filename;
 
        Storage::disk('public')->delete('photos/'.$genus_image->filename);

        $genus_image->delete();

        /*
        activity()
            ->performedOn($genus_image)
            ->withProperties(['filename'=>$filename])
            ->log('Genus image deleted.');
        */

        $this->dispatch('refreshGenusImageTable');
        $this->closeModal();

    }

    public function render()
    {
        return view('admin.livewire.delete-genus-image');
    }
}
