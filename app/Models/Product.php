<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'title',
        'description',
        'short_description',
        'price',
        'stock',
        'provider_id',
        'brand_id',
        'category_id',
        'status'
    ];
    
    public function images(){
        return $this->morphMany(Image::class, 'imageable');
    }

    public function provider(){
        return $this->belongsTo(Provider::class);
    }

    public function brand(){
        return $this->belongsTo(Brand::class);
    }

    public function category(){
        return $this->belongsTo(Category::class);
    }

}
