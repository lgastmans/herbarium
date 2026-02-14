<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HerbariumImages extends Model
{
    use HasFactory;

    protected $table = 'herbarium_images';

    protected $fillable = ['herbarium_id', 'genus_id', 'filename'];

    public function herbarium()
    {
        return $this->belongsTo(Herbarium::class);
    }

    public function genus()
    {
        return $this->belongsTo(Genus::class);
    }    

    // App\Models\HerbariumImages.php
    public function getUrlAttribute(): string
    {
        return asset('storage/herbarium/' . $this->filename);
    }

}
