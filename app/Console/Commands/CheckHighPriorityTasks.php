<?php

namespace App\Console\Commands;

use App\Models\TaskNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client as TwilioClient;

class CheckHighPriorityTasks extends Command
{
    protected $signature = 'tasks:check-priority 
                            {--dry-run : Solo mostrar tareas sin enviar}
                            {--peek : Ver sin consumir de la cola}';

    protected $description = 'Consulta tareas de alta prioridad de DT-OS y envía notificaciones por WhatsApp';

    // ✅ Cambiar por tu SID real de la plantilla
    private const TASK_TEMPLATE_SID = 'HX6e82ab1fb0e94f61c49d226abd927670';

    // ✅ MAPEO DE USUARIOS → NÚMEROS DE WHATSAPP
    private const USER_PHONE_MAP = [
        'edgardo'  => '573116123189',
        'dairo'    => '573007189383',
        'stiven'   => '573026444564',
    ];

    public function handle(): int
    {
        $this->info('🔍 Consultando tareas de alta prioridad...');

        $isDryRun = $this->option('dry-run');
        $isPeek = $this->option('peek');

        // Consultar API
        $result = $this->fetchTasks(consume: !$isPeek);

        if (!$result['success']) {
            $this->error('❌ Error: ' . $result['error']);
            Log::error('CheckHighPriorityTasks: ' . $result['error']);
            return Command::FAILURE;
        }

        if ($result['count'] === 0) {
            $this->info('✅ No hay tareas pendientes');
            return Command::SUCCESS;
        }

        $this->info("📋 {$result['count']} tarea(s) encontrada(s)");

        if ($isPeek) {
            $this->table(
                ['ID', 'Título', 'Asignado', 'Enviará a', 'Creador'],
                collect($result['tasks'])->map(fn($t) => [
                    $t['id'] ?? '-',
                    substr($t['titulo'] ?? '-', 0, 30),
                    $t['asignado'] ?? '-',
                    $this->getPhoneByName($t['asignado'] ?? '') ?? '⚠️ Sin número',
                    $t['creador'] ?? '-',
                ])
            );
            return Command::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn('🧪 Modo dry-run: No se enviarán mensajes reales');
        }

        $totalSent = 0;
        $totalFailed = 0;
        $totalSkipped = 0;

        foreach ($result['tasks'] as $task) {
            $asignado = $task['asignado'] ?? 'Sin asignar';
            $phone = $this->getPhoneByName($asignado);

            $this->line("  → {$task['titulo']} (Asignado: {$asignado})");

            if (!$phone) {
                $this->warn("    ⚠️ '{$asignado}' no tiene número configurado - OMITIDO");
                $totalSkipped++;
                continue;
            }

            if ($isDryRun) {
                $this->info("    [DRY-RUN] Se enviaría a {$phone}");
                $totalSent++;
                continue;
            }

            // Crear registro
            $notification = TaskNotification::create([
                'task_id' => $task['id'] ?? null,
                'titulo' => $task['titulo'] ?? 'Sin título',
                'descripcion' => $task['descripcion'] ?? null,
                'prioridad' => $task['prioridad'] ?? 'Alta',
                'asignado' => $asignado,
                'creador' => $task['creador'] ?? 'Sistema',
                'proyecto' => $task['proyecto'] ?? null,
                'fecha_limite' => $task['fechaLimite'] ?? null,
                'enviado_a' => $phone,
                'status' => 'pending',
            ]);

            // Enviar
            $sendResult = $this->sendNotification($task, $phone);

            if ($sendResult['success']) {
                $notification->update([
                    'status' => 'sent',
                    'twilio_sid' => $sendResult['sid'],
                    'sent_at' => now(),
                ]);
                $this->info("    ✅ Enviado a {$phone}");
                $totalSent++;
            } else {
                $notification->update([
                    'status' => 'failed',
                    'error_message' => $sendResult['error'],
                ]);
                $this->error("    ❌ Error: {$sendResult['error']}");
                $totalFailed++;
            }

            usleep(300000); // 0.3s entre mensajes
        }

        $this->newLine();
        $summary = "🎉 Completado: ✅ {$totalSent} enviados | ❌ {$totalFailed} fallidos";
        if ($totalSkipped > 0) {
            $summary .= " | ⚠️ {$totalSkipped} sin número";
        }
        $this->info($summary);

        return Command::SUCCESS;
    }

    /**
     * Obtiene el número de WhatsApp según el nombre del asignado
     */
    private function getPhoneByName(string $name): ?string
    {
        $normalized = strtolower(trim($name));
        
        if (isset(self::USER_PHONE_MAP[$normalized])) {
            return self::USER_PHONE_MAP[$normalized];
        }

        foreach (self::USER_PHONE_MAP as $key => $phone) {
            if (str_contains($normalized, $key)) {
                return $phone;
            }
        }

        return null;
    }

    private function fetchTasks(bool $consume = true): array
    {
        $baseUrl = trim((string) env('DTOS_API_URL', 'https://os.dtgrowthpartners.com'));
        $endpoint = $consume ? '/api/webhook/whatsapp/tasks' : '/api/webhook/whatsapp/tasks/peek';

        try {
            $response = Http::timeout(30)->get($baseUrl . $endpoint);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'API status ' . $response->status(),
                    'tasks' => [],
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'count' => $data['count'] ?? 0,
                'tasks' => $data['tasks'] ?? [],
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'tasks' => [],
            ];
        }
    }

    private function sendNotification(array $task, string $toNumber): array
    {
        $sid = trim((string) env('TWILIO_ACCOUNT_SID', ''));
        $token = trim((string) env('TWILIO_AUTH_TOKEN', ''));
        $from = trim((string) env('TWILIO_WHATSAPP_FROM', ''));

        if ($sid === '' || $token === '' || $from === '') {
            return ['success' => false, 'error' => 'Credenciales Twilio faltantes'];
        }

        $to = $this->normalizePhone($toNumber);
        if (!$to) {
            return ['success' => false, 'error' => 'Número inválido'];
        }

        try {
            $twilio = new TwilioClient($sid, $token);

            $fromNorm = str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:+' . ltrim($from, '+');

            $message = $twilio->messages->create($to, [
                'from' => $fromNorm,
                'contentSid' => self::TASK_TEMPLATE_SID,
                'contentVariables' => json_encode([
                    '1' => $task['asignado'] ?? 'Sin asignar',
                    '2' => $task['creador'] ?? 'Sistema',
                    '3' => $task['prioridad'] ?? 'Alta',
                    '4' => $task['titulo'] ?? 'Sin título',
                    '5' => $task['descripcion'] ?? 'Sin descripción',
                ]),
            ]);

            return ['success' => true, 'sid' => $message->sid];

        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function normalizePhone(string $value): ?string
    {
        $v = preg_replace('/[^0-9+]/', '', trim($value));

        if (str_starts_with($v, 'whatsapp:')) {
            return $v;
        }

        if (str_starts_with($v, '+')) {
            return 'whatsapp:' . $v;
        }

        if (preg_match('/^57\d{10}$/', $v)) {
            return 'whatsapp:+' . $v;
        }

        if (preg_match('/^\d{10}$/', $v)) {
            return 'whatsapp:+57' . $v;
        }

        return null;
    }
}