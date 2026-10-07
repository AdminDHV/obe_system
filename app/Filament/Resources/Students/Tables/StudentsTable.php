<?php

namespace App\Filament\Resources\Students\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student_code')
                    ->label('Mã Sinh viên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label('Họ và Tên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('program.name')
                    ->label('Ngành đào tạo')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cohort.name')
                    ->label('Niên khóa')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email trường')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('date_of_birth')
                    ->label('Ngày sinh')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gender')
                    ->label('Giới tính')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->searchable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'studying' => 'success',
                        'suspended' => 'warning',
                        'graduated' => 'info',
                        'dropped_out' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Ngày cập nhật')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
