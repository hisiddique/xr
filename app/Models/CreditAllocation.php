<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CreditAllocation extends Model
{
    use SoftDeletes;

    protected $fillable = ['payment_id', 'credit_note_id', 'invoice_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'credit_note_id')->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'invoice_id')->withTrashed();
    }
}
