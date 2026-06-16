<?php

namespace App\Models;

use Database\Factories\LookupPaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LookupPaymentMethod extends Model
{
    /** @use HasFactory<LookupPaymentMethodFactory> */
    use HasFactory;

    protected $fillable = ['name'];
}
