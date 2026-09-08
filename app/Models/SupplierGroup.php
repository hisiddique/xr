<?php

namespace App\Models;

use Database\Factories\SupplierGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupplierGroup extends Model
{
    /** @use HasFactory<SupplierGroupFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description'];

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'supplier_group_members');
    }
}
