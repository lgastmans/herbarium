<?php

namespace App\Livewire;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

//use Livewire\Component;
use App\Models\State;
use Illuminate\Validation\Rule;
use LivewireUI\Modal\ModalComponent;

class CreateState extends ModalComponent
{
    public $name;

    public function rules()
    {
        return [
            'name' => [
                'required',
                Rule::unique('states')->ignore($this->name), 
            ],
        ];
    }

    public function save() 
    {
        $this->validate();

        $model = State::create([
            'name' => $this->name
        ]);
 
        activity()
           ->performedOn($model)
           ->withProperties(['state'=>$this->name])
           ->log('State added.'); 

        return redirect()->to('/states')
             ->with('status', 'State created!');
    } 

    public function render()
    {
        return view('livewire.create-state');
    }
}
