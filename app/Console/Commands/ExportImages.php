<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

use App\Models\Genus;
use App\Models\HerbariumImages;


class ExportImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:export-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export images imported from DBISAM database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $images = HerbariumImages::all();
        //$images = HerbariumImages::limit(10)->get();

        foreach ($images as $image)
        {
            $genus = Genus::find($image->genus_id);

            $arr = explode(' ', $genus->name);
            $filename = implode(' ', array_slice($arr, 0, 2));
            $filename .= " ".$image->id;
            $filename = $filename.".jpg";

            $this->line(">".$image->filename);

            if (file_exists('public/Images/'.$image->filename)) {
                copy('public/Images/'.$image->filename, 'public/Images/renamed/'.$filename);
    
                $this->line("copied ".$filename);
            }
            else
                $this->line('not found');


        }
        

    }
}
