<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LookupCreditLimit extends Model
{
    /** @use HasFactory<\Database\Factories\LookupCreditLimitFactory> */
    use HasFactory;

    protected $fillable = ['amount'];
}
