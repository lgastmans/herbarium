<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use App\Models\Herbarium;
use LivewireUI\Modal\ModalComponent;


class DeletePlant extends ModalComponent
{
    use RequiresManagementAccess;


    public Herbarium $herbarium;

    public $id;


    public function delete()
    {
        $plant = Herbarium::findOrFail($this->id);
 
        $collection_number = $plant->collection_number;

        $plant->delete();
        
        activity()
            ->performedOn($plant)
            ->withProperties(['collection_number'=>$collection_number])
            ->log('Plant deleted.');  

        $this->dispatch('refreshTable');
        $this->closeModal();

    }
    

    public function render()
    {
        return view('admin.livewire.delete-plant');
    }

}
