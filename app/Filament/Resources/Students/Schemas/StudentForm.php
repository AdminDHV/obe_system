<?php

namespace App\Filament\Resources\Students\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class StudentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('cohort_id')
                    ->relationship('cohort', 'name'),
                Select::make('program_id')
                    ->relationship('program', 'name'),
                TextInput::make('student_code')
                    ->required(),
                TextInput::make('citizen_id'),
                TextInput::make('full_name')
                    ->required(),
                DatePicker::make('date_of_birth'),
                TextInput::make('gender'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('personal_email')
                    ->email(),
                TextInput::make('phone')
                    ->tel(),
                Textarea::make('permanent_address')
                    ->columnSpanFull(),
                Textarea::make('contact_address')
                    ->columnSpanFull(),
                DatePicker::make('admission_date'),
                TextInput::make('status')
                    ->required()
                    ->default('studying'),
                TextInput::make('avatar'),
            ]);
    }
}
