<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Livewire\Component;
use App\Models\Place;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class EditPlace extends ModalComponent
{
    use RequiresManagementAccess;

    public $id = null;
    public $name = '';

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('places')->ignore($this->name), 
            ],
        ];
    }

    public function mount(Place $place)
    {
        $this->id = $place->id;
        $this->name = $place->name;
    }

    public function save()
    {
        $this->validate();

        $model = Place::find($this->id);

        $original = $model->name;

        $model->update([
            'name'=>$this->name,
        ]);

        activity()
            ->performedOn($model)
            ->withProperties(['place'=>$original." > ".$this->name])
            ->log('Place updated.');   
 
        //return $this->redirect(route('admin.genus'));
        $this->dispatch('refreshTable');
        $this->closeModal();
    }

    public function render()
    {
        return view('admin.livewire.edit-place');
    }
}
