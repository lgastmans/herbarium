<?php

namespace App\Livewire;

//use Livewire\Component;
use App\Models\State;
use App\Models\Herbarium;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class DeleteState extends ModalComponent
{
    public State $state;

    public $id;

    public $original;

    public function delete()
    {
        $herbarium = Herbarium::where('state_id','=',$this->id)->first();

        if ($herbarium) {
            $this->dispatch('state-exists', Model: 'State', ColNum: $herbarium->collection_number);
        }
        else {

            $model = State::findOrFail($this->id);
     
            $original = $model->name;

            $model->delete();
            
            activity()
                ->performedOn($model)
                ->withProperties(['state'=>$original])
                ->log('State deleted.');  

            $this->dispatch('refreshTable');
            $this->closeModal();
        }
    }

    public function render()
    {
        return view('livewire.delete-state');
    }
}
