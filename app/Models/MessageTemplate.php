<?php

namespace App\Models;

use App\Enums\MessageTemplateKey;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Texto de un aviso de WhatsApp con variables como {nombre} o {enlace}. */
#[Fillable(['key', 'name', 'body', 'api_template_name', 'api_language', 'is_active'])]
class MessageTemplate extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'key' => MessageTemplateKey::class,
            'is_active' => 'boolean',
        ];
    }
}
