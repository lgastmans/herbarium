<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\Status;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class EditStatus extends ModalComponent
{
    use RequiresManagementAccess;

    public $id = null;
    public $name = '';

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('statuses')->ignore($this->name), 
            ],
        ];
    }

    public function mount(Status $status)
    {
        $this->id = $status->id;
        $this->name = $status->name;
    }

    public function save()
    {
        $this->validate();

        $model = Status::find($this->id);

        $original = $model->name;

        $model->update([
            'name'=>$this->name,
        ]);

        activity()
            ->performedOn($model)
            ->withProperties(['status'=>$original." > ".$this->name])
            ->log('Status updated.');   
 
        $this->dispatch('refreshTable');
        $this->closeModal();
    }

    public function render()
    {
        return view('admin.livewire.edit-status');
    }
}
