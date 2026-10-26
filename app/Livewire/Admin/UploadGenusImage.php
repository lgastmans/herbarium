<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;
use App\Models\Genus;
use App\Models\GenusImage;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class UploadGenusImage extends Component
{
    use RequiresManagementAccess;
    use WithFileUploads;

    #[Validate('image|mimes:jpeg,png,jpg,gif,svg|max:5000')]
    public $photo;

    public $genus_id = null;

    public function mount($genus_id = null)
    {
        $this->genus_id = $genus_id;
    }

    public function save()
    {
        $this->validate([
            'genus_id' => 'required|integer|exists:genus,id',
            'photo' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:5000',
        ]);

        $filename = bin2hex(random_bytes(12)).'.'.$this->photo->extension();

        $this->photo->storeAs('photos', $filename, 'public');

        $model = GenusImage::create(['genus_id' => $this->genus_id, 'filename' => $filename]);

        $genus = Genus::findOrFail($this->genus_id);

        activity()
            ->performedOn($model)
            ->withProperties(['name' => $genus->name])
            ->log('Image added.');

        $this->dispatch('refreshGenusImageTable');
    }

    public function render()
    {
        return view('admin.livewire.upload-genus-image')->layout('admin.layouts.app');
    }
}
