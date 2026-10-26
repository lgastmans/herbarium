<?php

namespace App\Livewire\Admin\Concerns;

use Illuminate\Support\Facades\Gate;

trait RequiresManagementAccess
{
    public function bootRequiresManagementAccess(): void
    {
        Gate::authorize('access-management');
    }
}
