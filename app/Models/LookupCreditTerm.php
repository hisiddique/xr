<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LookupCreditTerm extends Model
{
    /** @use HasFactory<\Database\Factories\LookupCreditTermFactory> */
    use HasFactory;

    protected $fillable = ['name'];
}
