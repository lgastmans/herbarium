<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Livewire\Component;
use App\Models\Family;
use App\Models\Herbarium;

class ReplaceFamily extends Component
{
    use RequiresManagementAccess;


    public $from_family_id;
    public $to_family_id;

    public function save() 
    {
        $this->validate([
            'from_family_id' => 'required',
            'to_family_id' => 'required',
        ]);

        $rows = Herbarium::where('family_id', $this->from_family_id)->get();

        if ($rows->count() > 0) {

            $family_from = "undefined";
            $result = Family::find($this->from_family_id);
            if ($result)
                $family_from = $result->family;

            $family_to = "undefined";
            $row = Family::find($this->to_family_id);
            if ($row)
                $family_to = $row->family;

            $affectedRows = Herbarium::where('family_id', $this->from_family_id)
                ->update(['family_id' => $this->to_family_id]);

            activity()
                ->performedOn($row)
                ->withProperties(['family'=>$family_from." > ".$family_to])
                ->log('Family replaced ('.$affectedRows.' entries).'); 

            session()->flash('family-replaced', $affectedRows.' herbarium entries successfully replaced');
        }
        else {

            session()->flash('family-not-found', 'The family specified was not found in the Herbarium');

        }

        return $this->redirect(route('admin.family.replace'));
    }

    public function render()
    {
        return view('admin.livewire.replace-family')
            ->layout('admin.layouts.app');
    }
}
