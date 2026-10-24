<?php

namespace App\Models\Concerns;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait GuardsPostedDocument
{
    abstract public function document(): BelongsTo;

    protected static function bootGuardsPostedDocument(): void
    {
        $guard = function ($item) {
            if ($item->document()->first()?->status === DocumentStatus::Posted) {
                throw new LogicException('Item dokumen yang sudah diposting tidak boleh diubah.');
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }
}
