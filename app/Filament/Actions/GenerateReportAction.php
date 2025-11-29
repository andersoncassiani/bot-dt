<?php

namespace App\Filament\Actions;

use App\Models\Message;
use Filament\Notifications\Notification;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Support\Facades\Storage;

class GenerateReportAction
{
    public static function generate()
    {
        try {
            // 1. Obtener todos los mensajes desde la conexión del bot
            $mensajes = Message::on('bot_mysql')
                ->select('message', 'response', 'timestamp')
                ->whereNotNull('message')
                ->orderBy('timestamp', 'desc')
                ->limit(500)
                ->get();

            if ($mensajes->isEmpty()) {
                Notification::make()
                    ->title('No hay datos')
                    ->body('No hay mensajes para analizar en la DB del bot.')
                    ->warning()
                    ->send();
                return null;
            }

            // 2. Preparar datos para ChatGPT
            $mensajesTexto = $mensajes->pluck('message')->take(200)->implode("\n");

            // 3. Consultar a ChatGPT
            $result = OpenAI::chat()->create([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Eres un analista de datos experto. 
                        Devuelve SIEMPRE un JSON válido (sin texto adicional ni explicaciones). 
                        Formato requerido: 
                        {
                          "solicitudes_frecuentes": [{"solicitud": "texto", "porcentaje": numero, "cantidad": numero}],
                          "tendencias": ["texto"],
                          "recomendaciones": ["texto"],
                          "total_analizado": numero
                        }'
                    ],
                    [
                        'role' => 'user',
                        'content' => "Analiza estos mensajes de clientes:\n\n" . $mensajesTexto
                    ]
                ],
                'temperature' => 0.0,
            ]);

            $analisis = json_decode($result->choices[0]->message->content, true);

            // 4. Estadísticas adicionales (también en bot_mysql)
            $stats = [
                'total_mensajes' => Message::on('bot_mysql')->count(),
                'mensajes_hoy' => Message::on('bot_mysql')->whereDate('timestamp', today())->count(),
                'mensajes_mes' => Message::on('bot_mysql')->whereMonth('timestamp', date('m'))->count(),
                'numeros_unicos' => Message::on('bot_mysql')->distinct('from')->count('from'),
                'sin_respuesta' => Message::on('bot_mysql')->whereNull('response')->count(),
            ];

            // 5. Generar PDF
            $pdf = Pdf::loadView('reports.messages-report', [
                'analisis' => $analisis,
                'stats' => $stats,
               'fecha_generacion' => now()
    ->setTimezone('America/Bogota')
    ->format('d/m/Y h:i A'),

            ])->setPaper('a4', 'portrait');

            // 6. Nombre + Guardar PDF
            $filename = 'reporte-mensajes-' . now()->format('Y-m-d-His') . '.pdf';
            Storage::disk('public')->put('reports/' . $filename, $pdf->output());

            // 7. URL pública del PDF
            $url = Storage::url('reports/' . $filename);

            // 8. Notificación en Filament
            Notification::make()
                ->title('Reporte generado')
                ->body('Tu reporte ha sido generado correctamente con datos de la DB del bot.')
                ->success()
                ->send();

            // 9. Retornar acción para abrir el PDF en nueva pestaña
            return redirect()->away($url)->withHeaders([
                'target' => '_blank'
            ]);

        } catch (\Exception $e) {
            Notification::make()
                ->title('Error al generar reporte')
                ->body('Error: ' . $e->getMessage())
                ->danger()
                ->send();
            return null;
        }
    }
}