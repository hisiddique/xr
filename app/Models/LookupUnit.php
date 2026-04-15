<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LookupUnit extends Model
{
    /** @use HasFactory<\Database\Factories\LookupUnitFactory> */
    use HasFactory;

    protected $fillable = ['name'];
}
