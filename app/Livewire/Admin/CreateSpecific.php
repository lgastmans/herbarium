<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

//use Livewire\Component;
use App\Models\Specific;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class CreateSpecific extends ModalComponent
{
    use RequiresManagementAccess;

    public $name;

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('specifics')->ignore($this->name), 
            ],
        ];
    }

    public function save() 
    {
        $this->validate();

        $model = Specific::create([
            'name' => $this->name
        ]);
 
        activity()
           ->performedOn($model)
           ->withProperties(['specific'=>$this->name])
           ->log('Specific added.'); 

        return redirect()->to(route('admin.specifics'))
             ->with('status', 'Specific created!');
    }

    public function render()
    {
        return view('admin.livewire.create-specific');
    }
}
