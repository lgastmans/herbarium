<?php
namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\RequiresManagementAccess;


use App\Livewire\Admin\Forms\PlantForm;
use Livewire\Component;
use App\Models\Herbarium;

use Livewire\Attributes\Title;
use Barryvdh\DomPDF\Facade\Pdf;


class UpdatePlant extends Component
{
    use RequiresManagementAccess;


    public PlantForm $form;
    
    public $id;

    public function mount(Herbarium $herbarium)
    {
        $this->form->setPlant($herbarium);

        $this->id = $herbarium->id;
    }


    public function save()
    {
        $this->form->update();

        return $this->redirect(route('admin.plants'));
    }

    public function cancel()
    {
        return $this->redirect(route('admin.plants'));
    }


    public function label()
    {

        $herbarium = Herbarium::find($this->id);

        $data = [
            'title' => 'Herbarium',
            'address' => 'Auroville, Tamil Nadu, India',
            'herbarium' => $herbarium,
        ];

        $pdf = Pdf::loadView('admin.herbarium-label', $data);
        // return $pdf->download('herbarium-label.pdf');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->stream();
        }, 'herbarium-label-'.$herbarium->collection_number.'.pdf');
    }    


    #[Title('Update Plant')]
    public function render()
    {
        return view('admin.livewire.create-plant', [$this->form])
            ->layout('admin.layouts.app');
    }

}
