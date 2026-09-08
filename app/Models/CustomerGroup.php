<?php

namespace App\Models;

use Database\Factories\CustomerGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerGroup extends Model
{
    /** @use HasFactory<CustomerGroupFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description'];

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_group_members');
    }
}
