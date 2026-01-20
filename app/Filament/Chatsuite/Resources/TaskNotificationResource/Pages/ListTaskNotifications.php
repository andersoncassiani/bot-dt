<?php

namespace App\Filament\Chatsuite\Resources\TaskNotificationResource\Pages;

use App\Filament\Chatsuite\Resources\TaskNotificationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaskNotifications extends ListRecords
{
    protected static string $resource = TaskNotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Los actions están en headerActions de la tabla
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            // Puedes agregar widgets de estadísticas aquí
        ];
    }
}