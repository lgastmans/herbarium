<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use LivewireUI\Modal\ModalComponent;
use Spatie\Activitylog\Models\Activity;

class DeleteUser extends ModalComponent
{
    public $id;

    public ?string $errorMessage = null;

    public function delete()
    {
        if (!auth()->user()?->is_admin) {
            abort(403, 'Unauthorized');
        }

        $user = User::findOrFail($this->id);

        if (Auth::id() === $user->id) {
            $this->errorMessage = 'You cannot delete the user you are currently signed in as.';

            return;
        }

        $hasActivity = Activity::query()
            ->where(function ($query) use ($user) {
                $query
                    ->where(function ($query) use ($user) {
                        $query->where('causer_type', User::class)
                            ->where('causer_id', $user->id);
                    })
                    ->orWhere(function ($query) use ($user) {
                        $query->where('subject_type', User::class)
                            ->where('subject_id', $user->id);
                    });
            })
            ->exists();

        if ($hasActivity) {
            $this->errorMessage = 'This user cannot be deleted because activity log entries reference them.';

            return;
        }

        $name = $user->name;
        $email = $user->email;

        $user->delete();

        activity()
            ->withProperties([
                'name' => $name,
                'email' => $email,
            ])
            ->log('User deleted.');

        $this->dispatch('refreshTable');
        $this->closeModal();
    }

    public function render()
    {
        return view('livewire.delete-user');
    }
}
