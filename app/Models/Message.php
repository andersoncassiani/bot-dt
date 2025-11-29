<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    //
     protected $connection = 'bot_mysql'; // 👈 usa la conexión del bot
    protected $table = 'messages';
    
    // Si tu tabla NO tiene created_at y updated_at, descomenta esta línea:
    // public $timestamps = false;
    
    // Si solo tienes timestamp y no created_at/updated_at:
    const CREATED_AT = 'timestamp';
    const UPDATED_AT = null;
    
    protected $fillable = [
        'from',
        'to',
        'message',
        'response',
        'timestamp',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];
}