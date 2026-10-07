<?php

namespace App\Filament\Resources\Programs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProgramForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('department_id')
                    ->relationship('department', 'name')
                    ->required(),
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
            ]);
    }
}
