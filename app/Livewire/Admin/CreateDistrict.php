<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

//use Livewire\Component;
use App\Models\District;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class CreateDistrict extends ModalComponent
{
    use RequiresManagementAccess;


    public $name;

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('districts')->ignore($this->name), 
            ],
        ];
    }

    public function save() 
    {
        $this->validate();

        $model = District::create([
            'name' => $this->name
        ]);
 
        activity()
           ->performedOn($model)
           ->withProperties(['district'=>$this->name])
           ->log('District added.'); 

        return redirect()->to(route('admin.districts'))
             ->with('status', 'District created!');
    }

    public function render()
    {
        return view('admin.livewire.create-district');
    }
}
