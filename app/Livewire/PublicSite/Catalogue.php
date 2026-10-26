<?php

namespace App\Livewire\PublicSite;

use App\Models\Herbarium;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Catalogue extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $search = mb_substr(trim($this->search), 0, 200);

        $specimens = Herbarium::query()
            ->with(['genus', 'family', 'place'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('collection_number', 'like', '%'.$search.'%')
                        ->orWhere('vernacular_name', 'like', '%'.$search.'%')
                        ->orWhereHas('genus', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('family', fn ($query) => $query->where('family', 'like', '%'.$search.'%'));
                });
            })
            ->orderBy('id')
            ->paginate(25);

        return view('public.livewire.catalogue', compact('specimens'));
    }
}
