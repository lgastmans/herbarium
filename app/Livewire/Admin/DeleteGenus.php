<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\Genus;
use App\Models\Herbarium;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class DeleteGenus extends ModalComponent
{
    use RequiresManagementAccess;

    public Genus $genus;

    public $id;

    public $original;

    public function delete()
    {
        $herbarium = Herbarium::where('genus_id','=',$this->id)->first();

        if ($herbarium) {
            $this->dispatch('genus-exists', Model: 'Genus', ColNum: $herbarium->collection_number);
        }
        else {

            $model = Genus::findOrFail($this->id);
     
            $original = $model->name;

            $model->delete();
            
            activity()
                ->performedOn($model)
                ->withProperties(['genus'=>$original])
                ->log('Genus deleted.');  

            $this->dispatch('refreshTable');
            $this->closeModal();
        }
    }

    public function render()
    {
        return view('admin.livewire.delete-genus');
    }
}
