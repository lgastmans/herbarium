<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use Illuminate\View\View;

class PlantController extends Controller
{
    
    /**
     * Display a listing of the resource. 
     */
    public function index(): View
    {
        return view('admin.plants', [
            //
        ]);
    }

}
