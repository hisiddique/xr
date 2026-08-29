<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WriteOff extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'document_id',
        'amount',
        'reason',
        'written_off_at',
        'written_off_by',
        'legacy_uid',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'written_off_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function writtenOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'written_off_by');
    }
}
