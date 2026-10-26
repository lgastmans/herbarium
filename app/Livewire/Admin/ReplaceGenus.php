<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Livewire\Component;
use App\Models\Genus;
use App\Models\Herbarium;

class ReplaceGenus extends Component
{
    use RequiresManagementAccess;

    public $from_genus_id;
    public $to_genus_id;

    public function save() 
    {
        $this->validate([
            'from_genus_id' => 'required',
            'to_genus_id' => 'required',
        ]);

        $model = Herbarium::where('genus_id', $this->from_genus_id)->get();

        if ($model->count() > 0) {

            $genus_from = "undefined";
            $result = Genus::find($this->from_genus_id);
            if ($result)
                $genus_from = $result->name;

            $genus_to = "undefined";
            $row = Genus::find($this->to_genus_id);
            if ($row)
                $genus_to = $row->name;

            $affectedRows = Herbarium::where('genus_id', $this->from_genus_id)
                ->update(['genus_id' => $this->to_genus_id]);
        
            activity()
                ->performedOn($row)
                ->withProperties(['genus'=>$genus_from." > ".$genus_to])
                ->log('Genus replaced ('.$affectedRows.' entries).');   
 
            $this->dispatch('genus-replaced');
            session()->flash('genus-replaced', $affectedRows.' genus(es) successfully replaced');
        }
        else {

            session()->flash('genus-not-found', 'The genus specified was not found in the Herbarium');

        }

        return $this->redirect(route('admin.genus.replace'));
    }

    public function render()
    {
        return view('admin.livewire.replace-genus')
            ->layout('admin.layouts.app');
    }
}
