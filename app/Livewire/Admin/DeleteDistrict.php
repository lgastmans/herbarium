<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\District;
use App\Models\Herbarium;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class DeleteDistrict extends ModalComponent
{
    use RequiresManagementAccess;

    public District $district;

    public $id;

    public $original;

    public function delete()
    {
        $herbarium = Herbarium::where('district_id','=',$this->id)->first();

        if ($herbarium) {
            $this->dispatch('district-exists', Model: 'District', ColNum: $herbarium->collection_number);
        }
        else {

            $model = District::findOrFail($this->id);
     
            $original = $model->name;

            $model->delete();
            
            activity()
                ->performedOn($model)
                ->withProperties(['district'=>$original])
                ->log('District deleted.');  

            $this->dispatch('refreshTable');
            $this->closeModal();
        }
    }

    public function render()
    {
        return view('admin.livewire.delete-district');
    }
}
