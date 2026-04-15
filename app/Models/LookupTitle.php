<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LookupTitle extends Model
{
    /** @use HasFactory<\Database\Factories\LookupTitleFactory> */
    use HasFactory;

    protected $fillable = ['name'];
}
