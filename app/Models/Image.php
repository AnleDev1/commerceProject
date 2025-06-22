<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $fillable = [
        'public_id',
        'url'
    ];

    public function imageable(){
        return $this->morphTo();
    }
}
