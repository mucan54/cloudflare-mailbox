<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $fillable = ['mailbox_id', 'name', 'email', 'phone', 'company', 'title', 'notes', 'favorite'];

    protected function casts(): array
    {
        return ['favorite' => 'boolean'];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
