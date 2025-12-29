<?php

namespace App\Filament\Chatsuite\Resources;

use App\Filament\Chatsuite\Resources\MessageResource\Pages;
use App\Models\Message;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

// ✅ NUEVO (solo lo necesario)
use Illuminate\Support\Facades\Http;
use Filament\Notifications\Notification;

// ✅ NUEVO: Twilio SDK para enviar difusión (sin controlador extra)
use Twilio\Rest\Client as TwilioClient;

class MessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Mensajes del Bot';
    protected static ?string $modelLabel = 'Mensaje';
    protected static ?string $pluralModelLabel = 'Mensajes del Bot';
    protected static ?int $navigationSort = 1;

    // ✅ NUEVO: Template SID fijo (tu plantilla)
    private const DIFFUSION_TEMPLATE_SID = 'HXba8ab559e42f01599e6661ef49d32a98';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    /**
     * ✅ Helper: normaliza whatsapp:+57...
     */
    private static function normalizeWhatsapp(string $value): string
    {
        $v = trim($value);

        if (str_starts_with($v, 'whatsapp:')) {
            return $v;
        }

        $v = ltrim($v, '+');

        return 'whatsapp:+' . $v;
    }

    /**
     * ✅ NUEVO: normaliza número a E.164 y lo devuelve como whatsapp:+...
     * Acepta:
     * - whatsapp:+57...
     * - +57...
     * - 57XXXXXXXXXX
     * - 300XXXXXXXX (asume Colombia +57)
     */
    private static function normalizeWhatsappPhone(string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') return null;

        $v = preg_replace('/\s+/', '', $v);
        $v = str_replace(['-','(',')'], '', $v);

        if (str_starts_with($v, 'whatsapp:')) {
            // Asegurar whatsapp:+...
            $raw = str_replace('whatsapp:', '', $v);
            $raw = trim($raw);
            if (str_starts_with($raw, '+')) return 'whatsapp:' . $raw;
            if (preg_match('/^\d+$/', $raw)) return 'whatsapp:+' . $raw;
            return null;
        }

        // +E.164
        if (str_starts_with($v, '+')) {
            return 'whatsapp:' . $v;
        }

        // 57XXXXXXXXXX -> +57XXXXXXXXXX
        if (preg_match('/^57\d{10}$/', $v)) {
            return 'whatsapp:+' . $v;
        }

        // 10 dígitos Colombia (300xxxxxxx)
        if (preg_match('/^\d{10}$/', $v)) {
            return 'whatsapp:+57' . $v;
        }

        return null;
    }

    /**
     * ✅ Helper: obtiene el teléfono del contacto real (usuario) para este record
     */
    private static function getContactPhone($record): string
    {
        $twilioFrom = trim((string) env('TWILIO_WHATSAPP_FROM', ''));

        // Normalizamos para comparar bien
        $recordFrom = self::normalizeWhatsapp((string) $record->from);
        $recordTo   = self::normalizeWhatsapp((string) ($record->to ?? ''));

        $twilioFromNorm = $twilioFrom ? self::normalizeWhatsapp($twilioFrom) : '';

        // Si el último mensaje (record) lo envió el BOT/ADMIN, el contacto real es "to"
        if ($twilioFromNorm && $recordFrom === $twilioFromNorm && $recordTo) {
            return $recordTo;
        }

        // Si no, el contacto real es "from"
        return $recordFrom;
    }

    /**
     * ✅ NUEVO: Texto "principal" para mostrar en UI:
     * - si es audio y ya hay transcript -> mostrar transcript (no [NOTA_DE_VOZ])
     * - si no, mostrar message o fallback por tipo
     */
    private static function getDisplayText($msg): string
    {
        $type = strtolower((string) ($msg->messageType ?? ''));
        $message = trim((string) ($msg->message ?? ''));

        if ($type === 'audio') {
            $transcript = trim((string) ($msg->transcript ?? ''));
            if ($transcript !== '') {
                return $transcript;
            }

            $status = strtolower((string) ($msg->transcriptStatus ?? ''));
            if ($status === 'pending') return '📝 Transcribiendo nota de voz...';
            if ($status === 'error') return '❌ No se pudo transcribir la nota de voz.';

            return $message !== '' ? $message : '[NOTA_DE_VOZ]';
        }

        if ($message !== '') return $message;

        return match ($type) {
            'sticker' => '[STICKER]',
            'image'   => '[IMAGEN]',
            'file'    => '[ARCHIVO]',
            default   => '[MENSAJE]',
        };
    }

    /**
     * ✅ Render de adjuntos (audio/sticker/image/file) desde mediaJson
     * + Render de estado de transcripción (pending/error)
     * - Si Twilio MediaUrl no carga directo en browser por auth, al menos queda evidenciado + link.
     * - Si luego montas un proxy (Laravel/Express), aquí solo cambias $src a tu proxy.
     */
    private static function renderMediaHtml($msg): string
    {
        $numMedia = (int) ($msg->numMedia ?? 0);
        $mediaJson = $msg->mediaJson ?? null;

        if ($numMedia <= 0 || empty($mediaJson)) {
            // Aunque no haya media, si por alguna razón hay transcript/estado, lo mostramos
            $status = strtolower((string) ($msg->transcriptStatus ?? ''));
            if (!empty($msg->transcript)) {
                return self::renderTranscriptBlock($msg->transcript);
            }
            if ($status === 'pending') {
                return self::renderTranscriptPendingBlock();
            }
            if ($status === 'error') {
                return self::renderTranscriptErrorBlock();
            }
            return '';
        }

        $items = json_decode($mediaJson, true);
        if (!is_array($items) || empty($items)) {
            $status = strtolower((string) ($msg->transcriptStatus ?? ''));
            if (!empty($msg->transcript)) {
                return self::renderTranscriptBlock($msg->transcript);
            }
            if ($status === 'pending') {
                return self::renderTranscriptPendingBlock();
            }
            if ($status === 'error') {
                return self::renderTranscriptErrorBlock();
            }
            return '';
        }

        $type = strtolower((string) ($msg->messageType ?? ''));

        // ✅ Si tienes un proxy, puedes setearlo en .env:
        // MEDIA_PROXY_BASE_URL=https://tu-dominio.com/twilio/media
        // y construir src hacia ese proxy (más abajo).
        $proxyBase = rtrim((string) env('MEDIA_PROXY_BASE_URL', ''), '/');

        $html = '<div style="margin-top:10px;">';

        foreach ($items as $i => $m) {
            $url = (string) ($m['url'] ?? '');
            if ($url === '') continue;

            $ct = strtolower((string) ($m['contentType'] ?? ''));
            $labelCt = $ct !== '' ? $ct : 'media';

            // Si hay proxy, mandamos por proxy con query (?url=...)
            $src = $url;
            if ($proxyBase !== '') {
                $src = $proxyBase . '?url=' . urlencode($url);
            }

            // Detectar tipo real por ContentType aunque messageType venga vacío
            $isAudio = str_starts_with($ct, 'audio/');
            $isWebp  = ($ct === 'image/webp');
            $isImage = str_starts_with($ct, 'image/');

            $badge = '';
            if ($isAudio || $type === 'audio') $badge = '🎤 Nota de voz';
            elseif ($isWebp || $type === 'sticker') $badge = '🧩 Sticker';
            elseif ($isImage || $type === 'image') $badge = '🖼️ Imagen';
            else $badge = '📎 Archivo';

            $html .= '<div style="margin-top:8px; padding:10px; background:#ffffff; border:1px solid #e5e7eb; border-radius:10px;">';
            $html .= '<div style="font-weight:600; color:#111827; margin-bottom:6px;">' . e($badge) . '</div>';

            if ($isAudio || $type === 'audio') {
                $html .= '<audio controls preload="none" style="width: 280px;">';
                $html .= '<source src="' . e($src) . '" type="' . e($ct ?: 'audio/ogg') . '">';
                $html .= 'Tu navegador no soporta audio.';
                $html .= '</audio>';
                $html .= '<div style="margin-top:6px;"><a href="' . e($src) . '" target="_blank" style="color:#2563eb;">Abrir audio</a> <span style="color:#6b7280;">(' . e($labelCt) . ')</span></div>';

                // ✅ Estado de transcripción (si aplica)
                $status = strtolower((string) ($msg->transcriptStatus ?? ''));
                if (empty($msg->transcript) && $status === 'pending') {
                    $html .= self::renderTranscriptPendingBlock();
                }
                if ($status === 'error') {
                    $html .= self::renderTranscriptErrorBlock();
                }
            } elseif ($isWebp || $isImage || $type === 'sticker' || $type === 'image') {
                $html .= '<a href="' . e($src) . '" target="_blank">';
                $html .= '<img src="' . e($src) . '" style="max-width:220px; border-radius:10px; border:1px solid #e5e7eb;" />';
                $html .= '</a>';
                $html .= '<div style="margin-top:6px;"><a href="' . e($src) . '" target="_blank" style="color:#2563eb;">Abrir imagen</a> <span style="color:#6b7280;">(' . e($labelCt) . ')</span></div>';
            } else {
                $html .= '<a href="' . e($src) . '" target="_blank" style="color:#2563eb;">Descargar / ver adjunto</a>';
                $html .= '<div style="margin-top:4px; color:#6b7280;">' . e($labelCt) . '</div>';
            }

            // ✅ Transcripción (si ya existe)
            if (!empty($msg->transcript)) {
                $html .= self::renderTranscriptBlock($msg->transcript);
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function renderTranscriptBlock(string $text): string
    {
        $html  = '<div style="margin-top:10px; padding:10px; background:#f9fafb; border-radius:10px; color:#111827;">';
        $html .= '<div style="font-weight:600; margin-bottom:6px;">📝 Transcripción</div>';
        $html .= '<div style="white-space:pre-wrap;">' . e($text) . '</div>';
        $html .= '</div>';
        return $html;
    }

    private static function renderTranscriptPendingBlock(): string
    {
        return '<div style="margin-top:10px; padding:10px; background:#eff6ff; border-radius:10px; color:#1e3a8a;">📝 Transcribiendo nota de voz...</div>';
    }

    private static function renderTranscriptErrorBlock(): string
    {
        return '<div style="margin-top:10px; padding:10px; background:#fef2f2; border-radius:10px; color:#991b1b;">❌ No se pudo transcribir la nota de voz.</div>';
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Información del Contacto')
                ->schema([
                    TextEntry::make('contact_phone')
                        ->label('Número de teléfono')
                        ->state(function ($record) {
                            return self::getContactPhone($record);
                        })
                        ->copyable(),

                    TextEntry::make('total_conversaciones')
                        ->label('Total de conversaciones')
                        ->state(function ($record) {
                            $contact = self::getContactPhone($record);

                            return Message::where(function ($q) use ($contact) {
                                $q->where('from', $contact)->orWhere('to', $contact);
                            })->count();
                        }),
                ])
                ->columns(2),

            Section::make('Historial de Conversación')
                ->schema([
                    TextEntry::make('conversaciones')
                        ->label('')
                        ->state(function ($record) {
                            $twilioFrom = trim((string) env('TWILIO_WHATSAPP_FROM', ''));
                            $twilioFrom = $twilioFrom ? self::normalizeWhatsapp($twilioFrom) : '';

                            $contact = self::getContactPhone($record);

                            // ✅ Conversación en ambos sentidos (usuario <-> bot/admin)
                            $mensajes = Message::where(function ($q) use ($contact) {
                                $q->where('from', $contact)
                                  ->orWhere('to', $contact);
                            })
                                ->orderBy('timestamp', 'asc')
                                ->get();

                            $html = '<div style="max-height: 500px; overflow-y: auto;">';

                            foreach ($mensajes as $msg) {
                                $fecha = $msg->timestamp ? $msg->timestamp->format('d/m/Y H:i') : 'Sin fecha';

                                $msgFrom = self::normalizeWhatsapp((string) $msg->from);
                                $msgTo   = self::normalizeWhatsapp((string) ($msg->to ?? ''));

                                // Render adjuntos (si hay)
                                $mediaHtml = self::renderMediaHtml($msg);

                                // ==========================
                                // ✅ 1) MENSAJE DEL USUARIO
                                // ==========================
                                if ($msgFrom === $contact) {
                                    $html .= '<div style="margin-bottom: 20px; padding: 12px; background-color: #f3f4f6; border-radius: 8px;">';
                                    $html .= '<div style="font-weight: bold; color: #374151; margin-bottom: 5px;">📲 Usuario (' . e($contact) . ') - ' . $fecha . '</div>';

                                    // ✅ NUEVO: texto principal (si es audio y ya hay transcript, muestra transcript)
                                    $text = self::getDisplayText($msg);

                                    $html .= '<div style="color: #1f2937;">' . nl2br(e($text)) . '</div>';
                                    $html .= $mediaHtml;
                                    $html .= '</div>';
                                } else {
                                    // ==========================
                                    // ✅ 2) MENSAJE DEL ADMIN (outbound humano)
                                    // ==========================
                                    $isAdminOutbound =
                                        $twilioFrom &&
                                        $msgFrom === $twilioFrom &&
                                        $msgTo === $contact &&
                                        empty($msg->response); // outbound humano se guarda como fila con response null

                                    if ($isAdminOutbound) {
                                        $html .= '<div style="margin-bottom: 20px; padding: 12px; background-color: #dcfce7; border-radius: 8px;">';
                                        $html .= '<div style="font-weight: bold; color: #166534; margin-bottom: 5px;">👤 Admin ChatSuite - ' . $fecha . '</div>';
                                        $html .= '<div style="color: #14532d;">' . nl2br(e($msg->message)) . '</div>';
                                        $html .= $mediaHtml;
                                        $html .= '</div>';
                                    } else {
                                        // Si cae aquí, lo mostramos neutro (por si hay data vieja/inconsistente)
                                        $html .= '<div style="margin-bottom: 20px; padding: 12px; background-color: #fff7ed; border-radius: 8px;">';
                                        $html .= '<div style="font-weight: bold; color: #9a3412; margin-bottom: 5px;">⚠️ Sistema - ' . $fecha . '</div>';
                                        $html .= '<div style="color: #7c2d12;">' . nl2br(e($msg->message)) . '</div>';
                                        $html .= $mediaHtml;
                                        $html .= '</div>';
                                    }
                                }

                                // ==========================
                                // ✅ 3) RESPUESTA DEL BOT (Lia)
                                // ==========================
                                if (!empty($msg->response)) {
                                    $html .= '<div style="margin-bottom: 20px; padding: 12px; background-color: #dbeafe; border-radius: 8px;">';
                                    $html .= '<div style="font-weight: bold; color: #1e40af; margin-bottom: 5px;">🤖 Lia</div>';
                                    $html .= '<div style="color: #1e3a8a;">' . nl2br(e($msg->response)) . '</div>';
                                    $html .= '</div>';
                                }
                            }

                            $html .= '</div>';

                            return $html;
                        })
                        ->html(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $subQuery = Message::select('from', DB::raw('MAX(id) as last_message_id'))
                    ->groupBy('from');

                return $query
                    ->select('messages.*')
                    ->joinSub($subQuery, 'latest', function ($join) {
                        $join->on('messages.id', '=', 'latest.last_message_id');
                    });
            })
            ->columns([
                Tables\Columns\TextColumn::make('from')
                    ->label('Número')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('messages.from', 'like', "%{$search}%");
                    })
                    ->copyable()
                    ->icon('heroicon-o-user')
                    ->description(fn ($record) => Message::where('from', $record->from)->count() . ' mensajes'),

                // ✅ NUEVO: si es audio y hay transcript, mostrar transcript aquí también
                Tables\Columns\TextColumn::make('message')
                    ->label('Ultimo mensaje')
                    ->state(function ($record) {
                        return self::getDisplayText($record);
                    })
                    ->limit(80)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('messages.message', 'like', "%{$search}%")
                                     ->orWhere('messages.transcript', 'like', "%{$search}%");
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('response')
                    ->label('Ultima respuesta')
                    ->limit(80)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('messages.response', 'like', "%{$search}%");
                    })
                    ->wrap()
                    ->placeholder('Sin respuesta')
                    ->icon(fn ($record) => $record->response ? 'heroicon-o-check-circle' : 'heroicon-o-clock'),

                Tables\Columns\TextColumn::make('timestamp')
                    ->label('Ultima interaccion')
                    ->formatStateUsing(function ($state) {
                        if (!$state) return 'Sin fecha';

                        $horaCorrecta = \Carbon\Carbon::parse($state)->subHours(5);
                        $ahora = \Carbon\Carbon::now();
                        $segundos = abs($horaCorrecta->diffInSeconds($ahora));

                        if ($segundos < 60) {
                            $diferencia = "Hace " . $segundos . " seg";
                        } elseif ($segundos < 3600) {
                            $minutos = floor($segundos / 60);
                            $diferencia = "Hace " . $minutos . " min";
                        } elseif ($segundos < 86400) {
                            $horas = floor($segundos / 3600);
                            $diferencia = "Hace " . $horas . " hora" . ($horas > 1 ? 's' : '');
                        } elseif ($segundos < 604800) {
                            $dias = floor($segundos / 86400);
                            $diferencia = "Hace " . $dias . " d¨ªa" . ($dias > 1 ? 's' : '');
                        } else {
                            return $horaCorrecta->format('d/m/Y h:i A');
                        }

                        return $horaCorrecta->format('d/m/Y h:i A') . "\n" . $diferencia;
                    })
                    ->html()
                    ->wrap()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('sin_respuesta')
                    ->label('Sin respuesta')
                    ->query(fn ($query) => $query->whereNull('response')),

                Tables\Filters\Filter::make('hoy')
                    ->label('Hoy')
                    ->query(function (Builder $query) {
                        return $query->whereRaw('DATE(timestamp) = CURDATE()');
                    }),

                Tables\Filters\SelectFilter::make('mes')
                    ->label('Mes')
                    ->options([
                        '1' => 'Enero',
                        '2' => 'Febrero',
                        '3' => 'Marzo',
                        '4' => 'Abril',
                        '5' => 'Mayo',
                        '6' => 'Junio',
                        '7' => 'Julio',
                        '8' => 'Agosto',
                        '9' => 'Septiembre',
                        '10' => 'Octubre',
                        '11' => 'Noviembre',
                        '12' => 'Diciembre',
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (isset($data['value'])) {
                            $query->whereMonth('timestamp', $data['value']);
                        }
                    }),

                Tables\Filters\SelectFilter::make('año')
                    ->label('Año')
                    ->options(function () {
                        $years = [];
                        $currentYear = date('Y');
                        for ($i = $currentYear; $i >= $currentYear - 5; $i--) {
                            $years[$i] = $i;
                        }
                        return $years;
                    })
                    ->query(function (Builder $query, array $data) {
                        if (isset($data['value'])) {
                            $query->whereYear('timestamp', $data['value']);
                        }
                    }),

                Tables\Filters\SelectFilter::make('numero_activo')
                    ->label('Números más activos')
                    ->options(function () {
                        return Message::query()
                            ->selectRaw('`from`, COUNT(*) as total')
                            ->groupBy('from')
                            ->orderByDesc('total')
                            ->limit(10)
                            ->pluck('from', 'from')
                            ->mapWithKeys(fn ($phone, $key) => [
                                $key => $phone . ' (' . Message::where('from', $phone)->count() . ' mensajes)'
                            ]);
                    })
                    ->query(function (Builder $query, array $data) {
                        if (isset($data['value'])) {
                            $query->where('messages.from', $data['value']);
                        }
                    }),
            ])

            // ✅ NUEVO: BOTÓN SUPERIOR "Difución" (header actions)
            ->headerActions([
                Tables\Actions\Action::make('difucion')
                    ->label('Difución')
                    ->icon('heroicon-o-megaphone')
                    ->modalHeading('Enviar difución (WhatsApp Template)')
                    ->modalWidth('2xl')
                    ->form([
                        Forms\Components\Textarea::make('numbers')
                            ->label('Números destino')
                            ->helperText('Uno por línea o separados por coma. Acepta: +57..., 300xxxxxxx, whatsapp:+57...')
                            ->rows(6)
                            ->required(),

                        Forms\Components\Textarea::make('vars_json')
                            ->label('Variables (JSON) para el template')
                            ->helperText('Ej: {"1":"BHK","2":"50% OFF"} — si no usas variables, deja {}')
                            ->rows(4)
                            ->default('{}')
                            ->required(),

                        Forms\Components\Placeholder::make('preview')
                            ->label('Preview (resumen)')
                            ->content(function ($get) {
                                $raw = (string) ($get('numbers') ?? '');
                                $parts = preg_split("/\r\n|\n|\r|,|;/", $raw) ?: [];
                                $nums = collect($parts)
                                    ->map(fn ($n) => self::normalizeWhatsappPhone((string) $n))
                                    ->filter()
                                    ->unique()
                                    ->values();

                                $varsRaw = (string) ($get('vars_json') ?? '{}');
                                $vars = json_decode($varsRaw, true);
                                if (!is_array($vars)) $vars = [];

                                return
                                    'Template: ' . self::DIFFUSION_TEMPLATE_SID . "\n" .
                                    'From: ' . (string) env('TWILIO_WHATSAPP_FROM', '') . "\n" .
                                    'Total destinatarios válidos: ' . $nums->count() . "\n\n" .
                                    'Variables:' . "\n" . json_encode($vars, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n" .
                                    'Destinatarios:' . "\n" . $nums->take(30)->implode("\n") .
                                    ($nums->count() > 30 ? "\n... (+".($nums->count()-30)." más)" : '');
                            }),
                    ])
                    ->modalSubmitActionLabel('Enviar difución')
                    ->action(function (array $data) {
                        // Validaciones env
                        $sid = trim((string) env('TWILIO_ACCOUNT_SID', ''));
                        $token = trim((string) env('TWILIO_AUTH_TOKEN', ''));
                        $from = trim((string) env('TWILIO_WHATSAPP_FROM', ''));

                        if ($sid === '' || $token === '' || $from === '') {
                            Notification::make()
                                ->title('Faltan variables en .env')
                                ->body('Requiere: TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_WHATSAPP_FROM')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Parse números
                        $raw = (string) ($data['numbers'] ?? '');
                        $parts = preg_split("/\r\n|\n|\r|,|;/", $raw) ?: [];
                        $numbers = collect($parts)
                            ->map(fn ($n) => self::normalizeWhatsappPhone((string) $n))
                            ->filter()
                            ->unique()
                            ->values();

                        if ($numbers->isEmpty()) {
                            Notification::make()
                                ->title('No hay números válidos')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Variables
                        $varsRaw = (string) ($data['vars_json'] ?? '{}');
                        $vars = json_decode($varsRaw, true);
                        if (!is_array($vars)) {
                            Notification::make()
                                ->title('JSON inválido en variables')
                                ->body('Corrige el JSON. Ej: {"1":"BHK","2":"50% OFF"}')
                                ->danger()
                                ->send();
                            return;
                        }

                        $statusCallback = trim((string) env('TWILIO_STATUS_CALLBACK_URL', ''));

                        $twilio = new TwilioClient($sid, $token);

                        $ok = 0;
                        $fail = 0;
                        $lines = [];

                        foreach ($numbers as $to) {
                            try {
                                $payload = [
                                    'from' => self::normalizeWhatsapp($from),
                                    'contentSid' => self::DIFFUSION_TEMPLATE_SID,
                                    'contentVariables' => json_encode($vars, JSON_UNESCAPED_UNICODE),
                                ];

                                if ($statusCallback !== '') {
                                    $payload['statusCallback'] = $statusCallback;
                                }

                                $msg = $twilio->messages->create($to, $payload);

                                $ok++;
                                $lines[] = "✅ {$to} — {$msg->sid}";
                            } catch (\Throwable $e) {
                                $fail++;
                                $lines[] = "❌ {$to} — " . $e->getMessage();
                            }
                        }

                        Notification::make()
                            ->title("Difución enviada: {$ok} OK / {$fail} fallos")
                            ->body(implode("\n", array_slice($lines, 0, 15)) . (count($lines) > 15 ? "\n..." : ''))
                            ->success()
                            ->send();
                    }),
            ])

            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver conversación')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->modalHeading(function ($record) {
                        $contact = self::getContactPhone($record);
                        return 'Conversación con ' . $contact;
                    })
                    ->modalWidth('3xl'),

                Tables\Actions\Action::make('responder')
                    ->label('Responder')
                    ->icon('heroicon-o-paper-airplane')
                    ->modalHeading(function ($record) {
                        $contact = self::getContactPhone($record);
                        return 'Responder a ' . $contact;
                    })
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Textarea::make('human_reply')
                            ->label('Mensaje')
                            ->placeholder('Escribe tu mensaje…')
                            ->rows(4)
                            ->required(),
                    ])
                    ->modalSubmitActionLabel('Enviar')
                    ->action(function (array $data, $record) {
                        $text = trim($data['human_reply'] ?? '');
                        if ($text === '') {
                            Notification::make()->title('Mensaje vacío')->danger()->send();
                            return;
                        }

                        $contact = self::getContactPhone($record);

                        // ✅ Express (recomendado): Laravel -> Express -> Twilio + pausa IA
                        $expressBase = rtrim(env('EXPRESS_BASE_URL', ''), '/');
                        $panelKey = env('PANEL_API_KEY');

                        if (!$expressBase || !$panelKey) {
                            Notification::make()
                                ->title('Falta EXPRESS_BASE_URL o PANEL_API_KEY en .env')
                                ->danger()
                                ->send();
                            return;
                        }

                        // ✅ FROM requerido por Express
                        $from = trim((string) env('TWILIO_WHATSAPP_FROM', ''));
                        if ($from === '') {
                            $num = trim((string) env('TWILIO_WHATSAPP_NUMBER', ''));
                            if ($num !== '') {
                                $from = 'whatsapp:+' . ltrim($num, '+');
                            }
                        }

                        if ($from === '') {
                            Notification::make()
                                ->title('Falta TWILIO_WHATSAPP_FROM o TWILIO_WHATSAPP_NUMBER en .env')
                                ->danger()
                                ->send();
                            return;
                        }

                        $resp = Http::withToken($panelKey)
                            ->asJson()
                            ->post($expressBase . '/handoff/send', [
                                'from' => $from,
                                'to'   => $contact,
                                'text' => $text,
                                'pauseMinutes' => (int) env('HUMAN_PAUSE_MINUTES', 60),
                            ]);

                        if (!$resp->successful()) {
                            Notification::make()
                                ->title('Express respondió error')
                                ->body($resp->body())
                                ->danger()
                                ->send();
                            return;
                        }

                        Notification::make()
                            ->title('Mensaje enviado ✅ (guardado + IA pausada)')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('timestamp', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessages::route('/'),
        ];
    }
}
