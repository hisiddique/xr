<?php

namespace App\Models;

use Database\Factories\LookupCustomerCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LookupCustomerCategory extends Model
{
    /** @use HasFactory<LookupCustomerCategoryFactory> */
    use HasFactory;

    protected $fillable = ['name'];
}
