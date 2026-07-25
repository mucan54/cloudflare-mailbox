<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = ['mailbox_id', 'title', 'done', 'due_on', 'notes', 'position'];

    protected function casts(): array
    {
        return ['done' => 'boolean', 'due_on' => 'date'];
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
