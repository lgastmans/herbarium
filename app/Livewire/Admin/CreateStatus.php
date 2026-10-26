<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

//use Livewire\Component;
use App\Models\Status;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class CreateStatus extends ModalComponent
{
    use RequiresManagementAccess;


    public $name;

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('statuses')->ignore($this->name), 
            ],
        ];
    }

    public function save() 
    {
        $this->validate();

        $model = Status::create([
            'name' => $this->name
        ]);
 
        activity()
           ->performedOn($model)
           ->withProperties(['status'=>$this->name])
           ->log('Status added.'); 

        return redirect()->to(route('admin.statuses'))
             ->with('status', 'Status created!');
    }

    public function render()
    {
        return view('admin.livewire.create-status');
    }
}
