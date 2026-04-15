<?php

namespace App\Livewire;

use Spatie\Activitylog\Models\Activity;
use Livewire\Attributes\On; 
use Illuminate\Support\Carbon;

use Illuminate\Database\Eloquent\Builder;
use PowerComponents\LivewirePowerGrid\Button;
use PowerComponents\LivewirePowerGrid\Column;
use PowerComponents\LivewirePowerGrid\Facades\Filter;
use PowerComponents\LivewirePowerGrid\Facades\PowerGrid;
use PowerComponents\LivewirePowerGrid\PowerGridFields;
use PowerComponents\LivewirePowerGrid\PowerGridComponent;
use PowerComponents\LivewirePowerGrid\Components\SetUp\Exportable;
use PowerComponents\LivewirePowerGrid\Traits\WithExport;

final class ActivityLogTable extends PowerGridComponent
{
    use WithExport;
    
    public string $tableName = 'activity';
    
    public string $sortField = 'created_at';
    public string $sortDirection = 'desc';    

    public function setUp(): array
    {
        $this->showCheckBox();

        return [
            (new Exportable('export'))
                ->striped()
                ->type(Exportable::TYPE_XLS, Exportable::TYPE_CSV),

            PowerGrid::header()
                ->showSearchInput(),

            PowerGrid::footer()
                ->showPerPage()
                ->showRecordCount(),
        ];
    }

    public function datasource(): Builder
    {
        return Activity::query()
            ->join('users', function ($users) {
                $users->on('causer_id', '=', 'users.id');
            })
            ->select([
                'activity_log.*',
                'users.name',
            ]);
    }

    public function relationSearch(): array
    {
        return [
            'users' => [ // relationship on family model
                'name', // column enabled to search
            ],
        ];
    }

    public function fields(): PowerGridFields
    {
        return PowerGrid::fields()
            ->add('id')
            ->add('created_at_formatted', fn (Activity $model) => Carbon::parse($model->created_at)->format('d M Y')) //->format('d/m/Y H:i:s'));
            ->add('description')
            ->add('properties')
            ->add('name_lower', fn (Activity $model) => strtolower(e($model->causer->name)))
            ->add('created_at');
    }

    public function columns(): array
    {
        return [
            Column::make('ID', 'id')
                ->searchable()
                ->sortable()
                ->hidden(),

            Column::make('Created at', 'created_at_formatted', 'created_at')
                ->searchable()
                ->sortable(),

            Column::make('Description', 'description')
                ->searchable()
                ->sortable(),

            Column::make('Properties', 'properties')
                ->searchable()
                ->sortable(),

            Column::make('Name', 'name_lower')
                ->searchable(),

            Column::make('Created at', 'created_at')
                ->hidden(),


            Column::action('Action')
                ->hidden(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::inputText('description'),
            Filter::inputText('properties'),
            Filter::inputText('name_lower', 'users.name'),
            Filter::datepicker('created_at_formatted', 'activity_log.created_at'),
        ];
    }

    #[\Livewire\Attributes\On('edit')]
    public function edit($rowId): void
    {
        $this->js('alert('.$rowId.')');
    }

    public function actions(Activity $row): array
    {
        return [
            Button::add('edit')
                ->slot('Edit: '.$row->id)
                ->id()
                ->class('pg-btn-white dark:ring-pg-primary-600 dark:border-pg-primary-600 dark:hover:bg-pg-primary-700 dark:ring-offset-pg-primary-800 dark:text-pg-primary-300 dark:bg-pg-primary-700')
                ->dispatch('edit', ['rowId' => $row->id])
        ];
    }

    /*
    public function actionRules(Activity $row): array
    {
       return [
            // Hide button edit for ID 1
            Rule::button('edit')
                ->when(fn($row) => $row->id === 1)
                ->hide(),
        ];
    }
    */
}
