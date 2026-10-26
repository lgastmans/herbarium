<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;

use Livewire\Component;
use App\Models\Place;
use App\Models\Herbarium;

class ReplacePlace extends Component
{
    use RequiresManagementAccess;

    public $from_place_id;
    public $to_place_id;

    public function save() 
    {
        $this->validate([
            'from_place_id' => 'required',
            'to_place_id' => 'required',
        ]);

        $rows = Herbarium::where('place_id', $this->from_place_id)->get();

        if ($rows->count() > 0) {

            $place_from = "undefined";
            $result = Place::find($this->from_place_id);
            if ($result)
                $place_from = $result->name;

            $place_to = "undefined";
            $row = Place::find($this->to_place_id);
            if ($row)
                $place_to = $row->name;

            $affectedRows = Herbarium::where('place_id', $this->from_place_id)
                ->update(['place_id' => $this->to_place_id]);

            activity()
                ->performedOn($row)
                ->withProperties(['place'=>$place_from." > ".$place_to])
                ->log('Place replaced ('.$affectedRows.' entries).'); 

            session()->flash('place-replaced', $affectedRows.' herbarium entries successfully replaced');
        }
        else {

            session()->flash('place-not-found', 'The place specified was not found in the Herbarium');

        }

        return $this->redirect(route('admin.place.replace'));
    }

    public function render()
    {
        return view('admin.livewire.replace-place')->layout('admin.layouts.app');
    }
}
