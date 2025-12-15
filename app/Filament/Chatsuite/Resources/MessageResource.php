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

class MessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Mensajes del Bot';
    protected static ?string $modelLabel = 'Mensaje';
    protected static ?string $pluralModelLabel = 'Mensajes del Bot';
    protected static ?int $navigationSort = 1;

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

        // Si viene sin código país, aquí NO lo inventamos (lo dejas como tengas tu data)
        // pero si normalmente guardas 57..., esto lo convierte a whatsapp:+57...
        return 'whatsapp:+' . $v;
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

                                // ==========================
                                // ✅ 1) MENSAJE DEL USUARIO
                                // ==========================
                                if ($msgFrom === $contact) {
                                    $html .= '<div style="margin-bottom: 20px; padding: 12px; background-color: #f3f4f6; border-radius: 8px;">';
                                    $html .= '<div style="font-weight: bold; color: #374151; margin-bottom: 5px;">📲 Usuario (' . e($contact) . ') - ' . $fecha . '</div>';
                                    $html .= '<div style="color: #1f2937;">' . nl2br(e($msg->message)) . '</div>';
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
                                        $html .= '</div>';
                                    } else {
                                        // Si cae aquí, lo mostramos neutro (por si hay data vieja/inconsistente)
                                        $html .= '<div style="margin-bottom: 20px; padding: 12px; background-color: #fff7ed; border-radius: 8px;">';
                                        $html .= '<div style="font-weight: bold; color: #9a3412; margin-bottom: 5px;">⚠️ Sistema - ' . $fecha . '</div>';
                                        $html .= '<div style="color: #7c2d12;">' . nl2br(e($msg->message)) . '</div>';
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

                Tables\Columns\TextColumn::make('message')
                    ->label('Ultimo mensaje')
                    ->limit(80)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('messages.message', 'like', "%{$search}%");
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
    // Preferido: TWILIO_WHATSAPP_FROM="whatsapp:+1415..."
    // Fallback: TWILIO_WHATSAPP_NUMBER="1415..."
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
            'from' => $from,        // ✅ CLAVE (antes faltaba)
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
