<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\Status;
use App\Models\Herbarium;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class DeleteStatus extends ModalComponent
{
    use RequiresManagementAccess;

    public Status $status;

    public $id;

    public $original;

    public function delete()
    {
        $herbarium = Herbarium::where('status_id','=',$this->id)->first();

        if ($herbarium) {
            $this->dispatch('status-exists', Model: 'Status', ColNum: $herbarium->collection_number);
        }
        else {

            $model = Status::findOrFail($this->id);
     
            $original = $model->name;

            $model->delete();
            
            activity()
                ->performedOn($model)
                ->withProperties(['status'=>$original])
                ->log('Status deleted.');  

            $this->dispatch('refreshTable');
            $this->closeModal();
        }
    }

    public function render()
    {
        return view('admin.livewire.delete-status');
    }
}
