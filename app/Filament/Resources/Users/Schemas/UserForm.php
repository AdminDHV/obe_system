<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('department_id')
                    ->relationship('department', 'name'),
                TextInput::make('staff_code')
                    ->required(),
                TextInput::make('title_prefix'),
                TextInput::make('full_name')
                    ->required(),
                TextInput::make('position'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required(),
                TextInput::make('password')
                    ->password()
                    ->required(),
                TextInput::make('phone')
                    ->tel(),
                TextInput::make('avatar'),
                TextInput::make('employment_status')
                    ->required()
                    ->default('full_time'),
                TextInput::make('contract_type'),
                DatePicker::make('joined_date'),
                DatePicker::make('contract_end_date'),
                Toggle::make('is_active')
                    ->required(),
                DateTimePicker::make('last_login_at'),
            ]);
    }
}
