<?php

namespace App\Filament\Chatsuite\Resources\MessageResource\Pages;

use App\Filament\Chatsuite\Resources\MessageResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateMessage extends CreateRecord
{
    protected static string $resource = MessageResource::class;
}
