<?php

namespace App\Filament\Chatsuite\Resources;

use App\Filament\Chatsuite\Resources\TaskNotificationResource\Pages;
use App\Models\TaskNotification;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Twilio\Rest\Client as TwilioClient;
use Illuminate\Database\Eloquent\Builder;

class TaskNotificationResource extends Resource
{
    protected static ?string $model = TaskNotification::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
    protected static ?string $navigationLabel = 'Tareas Urgentes';
    protected static ?string $modelLabel = 'Notificación de Tarea';
    protected static ?string $pluralModelLabel = 'Notificaciones de Tareas';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationGroup = 'DT-OS';

    // ✅ Tu plantilla de tareas (Content SID) - CAMBIAR POR EL REAL
    private const TASK_TEMPLATE_SID = 'HX6e82ab1fb0e94f61c49d226abd927670';

    // ✅ MAPEO DE USUARIOS → NÚMEROS DE WHATSAPP
    private const USER_PHONE_MAP = [
        'edgardo'  => '573116123189',
        'dairo'    => '573007189383',
        'stiven'   => '573026444564',
        // Agregar más usuarios aquí si es necesario
    ];

    /**
     * Obtiene el número de WhatsApp según el nombre del asignado
     */
    private static function getPhoneByName(string $name): ?string
    {
        $normalized = strtolower(trim($name));
        
        // Buscar coincidencia exacta
        if (isset(self::USER_PHONE_MAP[$normalized])) {
            return self::USER_PHONE_MAP[$normalized];
        }

        // Buscar coincidencia parcial (por si viene "Edgardo Pérez" en vez de solo "Edgardo")
        foreach (self::USER_PHONE_MAP as $key => $phone) {
            if (str_contains($normalized, $key)) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * Normaliza número a formato whatsapp:+...
     */
    private static function normalizeWhatsappPhone(string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') return null;

        $v = preg_replace('/\s+/', '', $v);
        $v = str_replace(['-', '(', ')'], '', $v);

        if (str_starts_with($v, 'whatsapp:')) {
            $raw = str_replace('whatsapp:', '', $v);
            $raw = trim($raw);
            if (str_starts_with($raw, '+')) return 'whatsapp:' . $raw;
            if (preg_match('/^\d+$/', $raw)) return 'whatsapp:+' . $raw;
            return null;
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

    /**
     * Envía notificación de tarea via Twilio Template
     */
    private static function sendTaskNotification(array $task, string $toNumber): array
    {
        $sid = trim((string) env('TWILIO_ACCOUNT_SID', ''));
        $token = trim((string) env('TWILIO_AUTH_TOKEN', ''));
        $from = trim((string) env('TWILIO_WHATSAPP_FROM', ''));

        if ($sid === '' || $token === '' || $from === '') {
            return [
                'success' => false,
                'error' => 'Faltan credenciales de Twilio en .env',
            ];
        }

        $to = self::normalizeWhatsappPhone($toNumber);
        if (!$to) {
            return [
                'success' => false,
                'error' => 'Número inválido: ' . $toNumber,
            ];
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

            return [
                'success' => true,
                'sid' => $message->sid,
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Consulta tareas de alta prioridad desde DT-OS API
     */
    private static function fetchPendingTasks(bool $consume = true): array
    {
        $baseUrl = trim((string) env('DTOS_API_URL', 'https://os.dtgrowthpartners.com'));
        $endpoint = $consume ? '/api/webhook/whatsapp/tasks' : '/api/webhook/whatsapp/tasks/peek';

        try {
            $response = Http::timeout(30)->get($baseUrl . $endpoint);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'API respondió con status ' . $response->status(),
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

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('titulo')
                    ->label('Tarea')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->titulo),

                Tables\Columns\TextColumn::make('asignado')
                    ->label('Asignado a')
                    ->searchable()
                    ->icon('heroicon-o-user'),

                Tables\Columns\TextColumn::make('creador')
                    ->label('Creado por')
                    ->searchable(),

                Tables\Columns\TextColumn::make('proyecto')
                    ->label('Proyecto')
                    ->searchable()
                    ->placeholder('Sin proyecto'),

                Tables\Columns\TextColumn::make('enviado_a')
                    ->label('WhatsApp')
                    ->searchable()
                    ->copyable()
                    ->formatStateUsing(fn ($state) => '+' . ltrim($state, '+')),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'warning' => 'pending',
                        'success' => fn ($state) => in_array($state, ['sent', 'delivered']),
                        'info' => 'read',
                        'danger' => 'failed',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'pending' => '⏳ Pendiente',
                        'sent' => '✅ Enviado',
                        'delivered' => '✅✅ Entregado',
                        'read' => '👁️ Leído',
                        'failed' => '❌ Fallido',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(30)
                    ->visible(fn ($record) => $record && $record->status === 'failed')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Enviado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->placeholder('No enviado'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => '⏳ Pendiente',
                        'sent' => '✅ Enviado',
                        'delivered' => '✅✅ Entregado',
                        'read' => '👁️ Leído',
                        'failed' => '❌ Fallido',
                    ]),

                Tables\Filters\SelectFilter::make('asignado')
                    ->label('Asignado a')
                    ->options([
                        'Edgardo' => 'Edgardo',
                        'Dairo' => 'Dairo',
                        'Stiven' => 'Stiven',
                    ]),

                Tables\Filters\Filter::make('hoy')
                    ->label('Hoy')
                    ->query(fn (Builder $query) => $query->whereDate('created_at', today())),
            ])
            ->headerActions([
                // ✅ Botón principal: Consultar y notificar al asignado
                Tables\Actions\Action::make('fetch_tasks')
                    ->label('🔄 Consultar Tareas')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Consultar tareas de alta prioridad')
                    ->modalDescription('Se consultará la API de DT-OS y se notificará por WhatsApp a la persona asignada de cada tarea.')
                    ->modalSubmitActionLabel('Consultar y Notificar')
                    ->action(function () {
                        $result = self::fetchPendingTasks(consume: true);

                        if (!$result['success']) {
                            Notification::make()
                                ->title('Error consultando API')
                                ->body($result['error'])
                                ->danger()
                                ->send();
                            return;
                        }

                        if ($result['count'] === 0) {
                            Notification::make()
                                ->title('Sin tareas pendientes')
                                ->body('No hay tareas de alta prioridad en la cola.')
                                ->info()
                                ->send();
                            return;
                        }

                        $sent = 0;
                        $failed = 0;
                        $skipped = 0;
                        $details = [];

                        foreach ($result['tasks'] as $task) {
                            $asignado = $task['asignado'] ?? 'Sin asignar';
                            
                            // Buscar el número del asignado
                            $phone = self::getPhoneByName($asignado);

                            if (!$phone) {
                                $skipped++;
                                $details[] = "⚠️ {$task['titulo']} → '{$asignado}' no tiene número configurado";
                                continue;
                            }

                            // Crear registro en BD
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

                            // Enviar via Twilio
                            $sendResult = self::sendTaskNotification($task, $phone);

                            if ($sendResult['success']) {
                                $notification->update([
                                    'status' => 'sent',
                                    'twilio_sid' => $sendResult['sid'],
                                    'sent_at' => now(),
                                ]);
                                $sent++;
                                $details[] = "✅ {$task['titulo']} → {$asignado}";
                            } else {
                                $notification->update([
                                    'status' => 'failed',
                                    'error_message' => $sendResult['error'],
                                ]);
                                $failed++;
                                $details[] = "❌ {$task['titulo']} → {$asignado}: {$sendResult['error']}";
                            }

                            usleep(300000); // 0.3 segundos entre mensajes
                        }

                        $summary = "Procesadas: {$result['count']} | ✅ Enviadas: {$sent} | ❌ Fallidas: {$failed}";
                        if ($skipped > 0) {
                            $summary .= " | ⚠️ Sin número: {$skipped}";
                        }

                        Notification::make()
                            ->title('Tareas procesadas')
                            ->body($summary . "\n\n" . implode("\n", array_slice($details, 0, 10)))
                            ->success()
                            ->duration(10000)
                            ->send();
                    }),

                // ✅ Ver cola sin consumir
                Tables\Actions\Action::make('peek_tasks')
                    ->label('👁️ Ver Cola')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading('Tareas pendientes en cola')
                    ->modalDescription('Vista previa - estas tareas NO se consumen')
                    ->modalWidth('2xl')
                    ->modalContent(function () {
                        $result = self::fetchPendingTasks(consume: false);

                        if (!$result['success']) {
                            return view('filament.components.task-preview', [
                                'error' => $result['error'],
                                'tasks' => [],
                                'userMap' => self::USER_PHONE_MAP,
                            ]);
                        }

                        return view('filament.components.task-preview', [
                            'error' => null,
                            'tasks' => $result['tasks'],
                            'count' => $result['count'],
                            'userMap' => self::USER_PHONE_MAP,
                        ]);
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),

                // ✅ Envío manual
                Tables\Actions\Action::make('manual_task')
                    ->label('➕ Enviar Manual')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading('Enviar notificación manual')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\TextInput::make('titulo')
                            ->label('Título de la tarea')
                            ->required(),

                        Forms\Components\Textarea::make('descripcion')
                            ->label('Descripción')
                            ->rows(2),

                        Forms\Components\Select::make('asignado')
                            ->label('Asignado a')
                            ->options([
                                'Edgardo' => 'Edgardo (3116123189)',
                                'Dairo' => 'Dairo (3007189383)',
                                'Stiven' => 'Stiven (3026444564)',
                            ])
                            ->required()
                            ->native(false),

                        Forms\Components\TextInput::make('creador')
                            ->label('Creado por')
                            ->default(auth()->user()?->name ?? 'Admin'),

                        Forms\Components\TextInput::make('proyecto')
                            ->label('Proyecto'),
                    ])
                    ->modalSubmitActionLabel('Enviar Notificación')
                    ->action(function (array $data) {
                        $asignado = $data['asignado'];
                        $phone = self::getPhoneByName($asignado);

                        if (!$phone) {
                            Notification::make()
                                ->title('Error')
                                ->body("No se encontró número para '{$asignado}'")
                                ->danger()
                                ->send();
                            return;
                        }

                        $task = [
                            'titulo' => $data['titulo'],
                            'descripcion' => $data['descripcion'] ?? '',
                            'prioridad' => 'Alta',
                            'asignado' => $asignado,
                            'creador' => $data['creador'],
                            'proyecto' => $data['proyecto'] ?? '',
                        ];

                        $notification = TaskNotification::create([
                            'titulo' => $task['titulo'],
                            'descripcion' => $task['descripcion'],
                            'prioridad' => $task['prioridad'],
                            'asignado' => $task['asignado'],
                            'creador' => $task['creador'],
                            'proyecto' => $task['proyecto'],
                            'enviado_a' => $phone,
                            'status' => 'pending',
                        ]);

                        $result = self::sendTaskNotification($task, $phone);

                        if ($result['success']) {
                            $notification->update([
                                'status' => 'sent',
                                'twilio_sid' => $result['sid'],
                                'sent_at' => now(),
                            ]);

                            Notification::make()
                                ->title('Notificación enviada')
                                ->body("✅ Enviado a {$asignado}")
                                ->success()
                                ->send();
                        } else {
                            $notification->update([
                                'status' => 'failed',
                                'error_message' => $result['error'],
                            ]);

                            Notification::make()
                                ->title('Error al enviar')
                                ->body($result['error'])
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('resend')
                    ->label('Reenviar')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'failed')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $task = [
                            'titulo' => $record->titulo,
                            'descripcion' => $record->descripcion,
                            'prioridad' => $record->prioridad,
                            'asignado' => $record->asignado,
                            'creador' => $record->creador,
                        ];

                        $result = self::sendTaskNotification($task, $record->enviado_a);

                        if ($result['success']) {
                            $record->update([
                                'status' => 'sent',
                                'twilio_sid' => $result['sid'],
                                'sent_at' => now(),
                                'error_message' => null,
                            ]);

                            Notification::make()
                                ->title('Reenviado correctamente')
                                ->success()
                                ->send();
                        } else {
                            $record->update([
                                'error_message' => $result['error'],
                            ]);

                            Notification::make()
                                ->title('Error al reenviar')
                                ->body($result['error'])
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTaskNotifications::route('/'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = TaskNotification::where('status', 'pending')->count();
        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}