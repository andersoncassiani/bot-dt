<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskNotification extends Model
{
    protected $fillable = [
        'task_id',
        'titulo',
        'descripcion',
        'prioridad',
        'asignado',
        'creador',
        'proyecto',
        'fecha_limite',
        'enviado_a',
        'twilio_sid',
        'status',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'fecha_limite' => 'date',
        'sent_at' => 'datetime',
    ];
}