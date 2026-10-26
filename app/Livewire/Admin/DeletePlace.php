<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\Place;
use App\Models\Herbarium;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class DeletePlace extends ModalComponent
{
    use RequiresManagementAccess;

    public Place $place;

    public $id;

    public $original;

    public function delete()
    {
        $herbarium = Herbarium::where('place_id','=',$this->id)->first();

        if ($herbarium) {
            $this->dispatch('place-exists', Model: 'Place', ColNum: $herbarium->collection_number);
        }
        else {

            $model = Place::findOrFail($this->id);
     
            $original = $model->name;

            $model->delete();
            
            activity()
                ->performedOn($model)
                ->withProperties(['place'=>$original])
                ->log('Place deleted.');  

            $this->dispatch('refreshTable');
            $this->closeModal();
        }
    }

    public function render()
    {
        return view('admin.livewire.delete-place');
    }
}
