<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use LivewireUI\Modal\ModalComponent;

class HelloWorld extends ModalComponent
{
    use RequiresManagementAccess;

    public function render()
    {
        return view('admin.livewire.hello-world');
    }
}
