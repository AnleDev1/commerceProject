<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    protected $fillable = [
        'name',
        'email',
        'telephone',
        'address',
        'status',
    ];

    public function products(){
        return $this->hasMany(Product::class);
    }
}
