<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use App\Models\Genus;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class EditGenus extends ModalComponent
{
    use RequiresManagementAccess;

    public $id = null;
    public $name = '';

    public $images;

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('genus')->ignore($this->name), 
            ],
        ];

        // return [
        //     'name' => [
        //         'required',
        //         'string',
        //         new validateName($this->id), // Pass the current ID for comparison
        //     ],
        // ];
    }

    // public function validateName(string $attribute, $value, $parameters, Validator $validator)
    // {
    //     $id = $parameters[0];
    //     // Query the database excluding the current record
    //     $record = Genus::where('name', $value)
    //         ->whereNot('id', $id)
    //         ->first();

    //     return is_null($record);
    // }

    public function mount(Genus $genus)
    {
        $this->id = $genus->id;
        $this->name = $genus->name;
        $this->images = $genus->images;
    }

    public function save()
    {
        $this->validate();

        $model = Genus::find($this->id);

        $original = $model->name;

        $model->update([
            'name'=>$this->name,
        ]);

        activity()
            ->performedOn($model)
            ->withProperties(['genus'=>$original." > ".$this->name])
            ->log('Genus updated.');   
 
        //return $this->redirect(route('admin.genus'));
        $this->dispatch('refreshTable');
        $this->closeModal();
    }

    public function render()
    {
        return view('admin.livewire.edit-genus');
    }
    
}
