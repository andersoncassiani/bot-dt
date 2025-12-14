<?php

namespace App\Filament\Chatsuite\Resources;

use App\Filament\Chatsuite\Resources\MessageResource\Pages;
use App\Filament\Chatsuite\Resources\MessageResource\RelationManagers;
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
        return $form
            ->schema([
             
            ]);
    }


    
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Información del Contacto')
                    ->schema([
                        TextEntry::make('from')
                            ->label('Número de teléfono')
                            ->copyable(),
                        TextEntry::make('total_conversaciones')
                            ->label('Total de conversaciones')
                            ->state(function ($record) {
                                return Message::where('from', $record->from)->count();
                            }),
                    ])
                    ->columns(2),
                    
                Section::make('Historial de Conversación')
                    ->schema([
                        TextEntry::make('conversaciones')
                            ->label('')
                            ->state(function ($record) {
                                // ✅ Traer conversación en ambos sentidos (cliente<->twilio)
                                $mensajes = Message::where(function ($q) use ($record) {
                                    $q->where('from', $record->from)
                                      ->orWhere('to', $record->from);
                                })
                                    ->orderBy('timestamp', 'asc')
                                    ->get();
                                
                                $html = '<div style="max-height: 500px; overflow-y: auto;">';
                                
                                foreach ($mensajes as $msg) {
                                    $fecha = $msg->timestamp ? $msg->timestamp->format('d/m/Y H:i') : 'Sin fecha';
                                    
                                    // ✅ Si el from ES el cliente → Cliente
                                    if ($msg->from === $record->from) {
                                        $html .= '<div style="margin-bottom: 20px; padding: 12px; background-color: #f3f4f6; border-radius: 8px;">';
                                        $html .= '<div style="font-weight: bold; color: #374151; margin-bottom: 5px;">👤 Admin ChatSuite - ' . $fecha . '</div>';
                                        $html .= '<div style="color: #1f2937;">' . nl2br(e($msg->message)) . '</div>';
                                        $html .= '</div>';
                                    } else {
                                        // ✅ Si NO es el cliente → Humano (mensaje enviado desde panel / Twilio)
                                        $html .= '<div style="margin-bottom: 20px; padding: 12px; background-color: #dcfce7; border-radius: 8px;">';
                                        $html .= '<div style="font-weight: bold; color: #166534; margin-bottom: 5px;">📲 Usuario - ' . $fecha . '</div>';
                                        $html .= '<div style="color: #14532d;">' . nl2br(e($msg->message)) . '</div>';
                                        $html .= '</div>';
                                    }

                                    // Respuesta del bot (si existe)
                                    if ($msg->response) {
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
            // ModificaciÃ³n principal: agrupar por nÃºmero de telÃ©fono
            ->modifyQueryUsing(function (Builder $query) {
                // Subconsulta para obtener el ID del Ãºltimo mensaje de cada nÃºmero
                $subQuery = Message::select('from', DB::raw('MAX(id) as last_message_id'))
                    ->groupBy('from');
                
                // Unir con la tabla principal para obtener solo los Ãºltimos mensajes
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
        
        // Restar 5 horas para obtener hora correcta de Colombia
        $horaCorrecta = \Carbon\Carbon::parse($state)->subHours(5);
        $ahora = \Carbon\Carbon::now();
        
        // Calcular diferencia correctamente (de hora correcta a ahora)
        $segundos = abs($horaCorrecta->diffInSeconds($ahora)); // abs() para valor absoluto
        
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
            // Si es m¨¢s de 7 d¨ªas, solo mostrar la fecha
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
                    ->modalHeading(fn ($record) => 'Conversación con ' . $record->from)
                    ->modalWidth('3xl'),

                // ✅ NUEVO: Responder → Laravel llama a Express (NO Twilio directo)
                Tables\Actions\Action::make('responder')
                    ->label('Responder')
                    ->icon('heroicon-o-paper-airplane')
                    ->modalHeading(fn ($record) => 'Responder a ' . $record->from)
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

                        // ✅ Normaliza destino a whatsapp:+...
                        $toUser = trim((string) $record->from);
                        if (!str_starts_with($toUser, 'whatsapp:')) {
                            $toUser = 'whatsapp:+' . ltrim($toUser, '+');
                        }

                        // ✅ From (Twilio sender) desde .env Laravel (obligatorio para tu Express actual)
                        $fromTwilio = (string) env('TWILIO_WHATSAPP_FROM', '');
                        if ($fromTwilio === '' || !str_starts_with($fromTwilio, 'whatsapp:')) {
                            Notification::make()
                                ->title('TWILIO_WHATSAPP_FROM inválido en .env')
                                ->body('Debe ser tipo whatsapp:+1415XXXXXXX')
                                ->danger()
                                ->send();
                            return;
                        }

                        $expressBase = rtrim((string) env('EXPRESS_BASE_URL', ''), '/');
                        $panelKey = (string) env('PANEL_API_KEY', '');

                        if ($expressBase === '' || $panelKey === '') {
                            Notification::make()
                                ->title('Falta EXPRESS_BASE_URL o PANEL_API_KEY en .env')
                                ->danger()
                                ->send();
                            return;
                        }

                        try {
                            $resp = Http::withToken($panelKey)
                                ->timeout(20)
                                ->post($expressBase . '/handoff/send', [
                                    'from' => $fromTwilio,
                                    'to'   => $toUser,
                                    'text' => $text,
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
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('No se pudo conectar a Express')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                            return;
                        }
                    }),
            ])
            ->defaultSort('timestamp', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessages::route('/'),
        
        ];
    }
}
