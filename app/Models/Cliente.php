<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    // ✅ Usa la conexión secundaria
    protected $connection = 'clientes_mysql';

    // ✅ Tabla
    protected $table = 'cliente';

    // Si tu tabla NO tiene created_at/updated_at, pon false
    public $timestamps = true;

    protected $fillable = [
        'phone',
    ];

    /**
     * Normaliza un número a formato estándar.
     * - Acepta: +57300..., 57300..., 300...
     * - Devuelve: +57XXXXXXXXXX (si es celular CO)
     */
    public static function normalizePhone(string $raw): ?string
    {
        $v = trim($raw);
        if ($v === '') return null;

        $v = preg_replace('/\s+/', '', $v);
        $v = str_replace(['-','(',')'], '', $v);

        // ya viene +57
        if (preg_match('/^\+57\d{10}$/', $v)) {
            return $v;
        }

        // viene 57XXXXXXXXXX
        if (preg_match('/^57\d{10}$/', $v)) {
            return '+'.$v;
        }

        // viene XXXXXXXXXX (celular CO)
        if (preg_match('/^3\d{9}$/', $v)) {
            return '+57'.$v;
        }

        return null;
    }
}