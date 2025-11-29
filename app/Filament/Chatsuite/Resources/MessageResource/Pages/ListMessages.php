<?php

namespace App\Filament\Chatsuite\Resources\MessageResource\Pages;

use App\Filament\Chatsuite\Resources\MessageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Actions\GenerateReportAction;

class ListMessages extends ListRecords
{
    protected static string $resource = MessageResource::class;

    protected function getHeaderActions(): array
    {
             return [
     Actions\Action::make('generar_reporte')
    ->label('Generar reporte')
    ->icon('heroicon-o-document-chart-bar')
    ->color('success')
    ->action(function () {
        $url = GenerateReportAction::generate();

        if ($url) {
            return redirect()->away($url); // redirige a la URL pública correcta
        }
    })
    ->requiresConfirmation()
    ->modalHeading('Generar Reporte')
    ->modalDescription('Se analizarán los mensajes y se generará un PDF con estadísticas y recomendaciones. Este proceso puede tardar unos segundos.')
    ->modalSubmitActionLabel('Generar')
    ->openUrlInNewTab()// abre nueva pestaña


        ];
    }
}