<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefreshToken extends Model
{
    protected $fillable = ['user_id', 'token', 'revoked', 'expires_at'];


    protected $casts = [
        'revoked' => 'boolean',

    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
