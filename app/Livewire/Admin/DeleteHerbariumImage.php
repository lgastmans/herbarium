<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use App\Models\Genus;
use App\Models\HerbariumImages;
use LivewireUI\Modal\ModalComponent;
use Illuminate\Support\Facades\Storage;

class DeleteHerbariumImage extends ModalComponent
{
    use RequiresManagementAccess;


    public HerbariumImages $HerbariumImages;

    public $id;

    public function delete()
    {
        $herbarium_image = HerbariumImages::findOrFail($this->id);

        //$filename = $genus_image->filename;
 
        Storage::disk('public')->delete('herbarium/'.$herbarium_image->filename);

        $herbarium_image->delete();

        $genus = Genus::findOrFail($herbarium_image->genus_id);

        activity()
            ->performedOn($herbarium_image)
            ->withProperties(['name'=>$genus->name, 'filename'=>$herbarium_image->filename])
            ->log('Image deleted.');
        

        $this->dispatch('refreshHerbariumImageTable');
        $this->closeModal();

    }

    public function render()
    {
        return view('admin.livewire.delete-herbarium-image');
    }
}
