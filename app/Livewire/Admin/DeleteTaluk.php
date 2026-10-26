<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\Taluk;
use App\Models\Herbarium;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class DeleteTaluk extends ModalComponent
{
    use RequiresManagementAccess;

    public Taluk $taluk;

    public $id;

    public $original;

    public function delete()
    {
        $herbarium = Herbarium::where('taluk_id','=',$this->id)->first();

        if ($herbarium) {
            $this->dispatch('taluk-exists', Model: 'Taluk', ColNum: $herbarium->collection_number);
        }
        else {

            $model = Taluk::findOrFail($this->id);
     
            $original = $model->name;

            $model->delete();
            
            activity()
                ->performedOn($model)
                ->withProperties(['taluk'=>$original])
                ->log('Taluk deleted.');  

            $this->dispatch('refreshTable');
            $this->closeModal();
        }
    }

    public function render()
    {
        return view('admin.livewire.delete-taluk');
    }
}
