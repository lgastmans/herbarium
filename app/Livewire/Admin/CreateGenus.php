<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

//use Livewire\Component;
use App\Models\Genus;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class CreateGenus extends ModalComponent
{
    use RequiresManagementAccess;

    public $name;

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('genus')->ignore($this->name), 
            ],
        ];
    }

    public function save() 
    {
        $this->validate();

        $model = Genus::create([
            'name' => $this->name
        ]);
 
        activity()
           ->performedOn($model)
           ->withProperties(['name'=>$this->name])
           ->log('Genus added.'); 

        return redirect()->to(route('admin.genus'))
             ->with('status', 'Genus created!');
    }   

    public function render()
    {
        return view('admin.livewire.create-genus');
    }
}
