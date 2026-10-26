<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

//use Livewire\Component;
use LivewireUI\Modal\ModalComponent;

class AlertHerbarium extends ModalComponent
{
    use RequiresManagementAccess;


    public $Model = '';
    public $ColNum = '';

    public function mount()
    {
        //$this->Model = 'test this stuff out'; //$Model;
    }

    public function render()
    {
        return view('admin.livewire.alert-herbarium');
    }
}
