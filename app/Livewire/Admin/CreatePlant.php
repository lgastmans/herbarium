<?php
namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use App\Livewire\Admin\Forms\PlantForm;
use Livewire\Component;
use App\Models\Herbarium;

use Livewire\Attributes\Title;


class CreatePlant extends Component
{
    use RequiresManagementAccess;


    public PlantForm $form;


    public function save() 
    {
        $this->form->store();

        session()->flash('status', 'Plant successfully created');

        return $this->redirect(route('admin.plants'));
    }

    public function cancel()
    {
        return $this->redirect(route('admin.plants'));
    }
    
    #[Title('Create Plant')]
    public function render()
    {
        return view('admin.livewire.create-plant')
            ->layout('admin.layouts.app');
    }
}
