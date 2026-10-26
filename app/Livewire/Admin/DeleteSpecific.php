<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\Specific;
use App\Models\Herbarium;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class DeleteSpecific extends ModalComponent
{
    use RequiresManagementAccess;

    public Specific $specific;

    public $id;

    public $original;

    public function delete()
    {
        $herbarium = Herbarium::where('specific_id','=',$this->id)->first();

        if ($herbarium) {
            $this->dispatch('specific-exists', Model: 'Specific', ColNum: $herbarium->collection_number);
        }
        else {

            $model = Specific::findOrFail($this->id);
     
            $original = $model->name;

            $model->delete();
            
            activity()
                ->performedOn($model)
                ->withProperties(['specific'=>$original])
                ->log('Specific deleted.');  

            $this->dispatch('refreshTable');
            $this->closeModal();
        }
    }

    public function render()
    {
        return view('admin.livewire.delete-specific');
    }
}
