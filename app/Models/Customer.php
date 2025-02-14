<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'custom_id',
        'name',
        'email',
        'company',
        'city',
        'country',
        'birthday',
    ];

    protected $casts = [
        'birthday' => 'date',
    ];
}
