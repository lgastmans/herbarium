<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

//use Livewire\Component;
use App\Models\Taluk;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class CreateTaluk extends ModalComponent
{
    use RequiresManagementAccess;

    public $name;

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('taluks')->ignore($this->name), 
            ],
        ];
    }

    public function save() 
    {
        $this->validate();

        $model = Taluk::create([
            'name' => $this->name
        ]);
 
        activity()
           ->performedOn($model)
           ->withProperties(['taluk'=>$this->name])
           ->log('Taluk added.'); 

        return redirect()->to(route('admin.taluks'))
             ->with('status', 'Taluk created!');
    }

    public function render()
    {
        return view('admin.livewire.create-taluk');
    }
}
