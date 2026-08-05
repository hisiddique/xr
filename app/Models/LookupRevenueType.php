<?php

namespace App\Models;

use Database\Factories\LookupRevenueTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LookupRevenueType extends Model
{
    /** @use HasFactory<LookupRevenueTypeFactory> */
    use HasFactory;

    protected $fillable = ['name'];
}
