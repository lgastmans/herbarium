<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\Family;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class EditFamily extends ModalComponent
{
    use RequiresManagementAccess;


//    public Family $family;
    public $id = null;
    public $family = '';

    public function rules()
    {
        return [
            'family' => [
                'required',
                Rule::unique('families')->ignore($this->family), 
            ],
        ];
    }

    public function mount(Family $family)
    {
        $this->id = $family->id;
        $this->family = $family->family;
    }

    public function save()
    {
        $this->validate();

        $model = Family::find($this->id);

        $family_original = $model->family;

        $model->update([
            'family'=>$this->family,
        ]);

        activity()
            ->performedOn($model)
            ->withProperties(['family'=>$family_original." > ".$this->family])
            ->log('Family updated.');   
 
        return $this->redirect(route('admin.families'));
    }

    public function render()
    {
        return view('admin.livewire.edit-family');
    }
}
