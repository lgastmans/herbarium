<?php

namespace App\Livewire;

//use Livewire\Component;
use App\Models\State;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class EditState extends ModalComponent
{
    public $id = null;
    public $name = '';

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('states')->ignore($this->name), 
            ],
        ];
    }

    public function mount(State $state)
    {
        $this->id = $state->id;
        $this->name = $state->name;
    }

    public function save()
    {
        $this->validate();

        $model = State::find($this->id);

        $original = $model->name;

        $model->update([
            'name'=>$this->name,
        ]);

        activity()
            ->performedOn($model)
            ->withProperties(['state'=>$original." > ".$this->name])
            ->log('State updated.');   
 
        $this->dispatch('refreshTable');
        $this->closeModal();
    }

    public function render()
    {
        return view('livewire.edit-state');
    }
}
