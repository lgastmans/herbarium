<?php

namespace App\Livewire;

use App\Models\Family;
use App\Models\Herbarium;
use LivewireUI\Modal\ModalComponent;

class DeleteFamily extends ModalComponent
{
    public $id;

    public ?string $errorMessage = null;

    public function delete()
    {
        $herbarium = Herbarium::where('family_id','=',$this->id)->first();

        if ($herbarium) {
            $this->errorMessage = 'This family cannot be deleted because it is present in herbarium collection number '.$herbarium->collection_number.'.';

            return;
        }

        $model = Family::findOrFail($this->id);

        $family = $model->family;

        $model->delete();

        activity()
            ->performedOn($model)
            ->withProperties(['family'=>$family])
            ->log('Family deleted.');

        $this->dispatch('refreshTable');
        $this->closeModal();
    }

    public function render()
    {
        return view('livewire.delete-family');
    }
}
