<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('名稱')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->disabledOn('edit')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                TextInput::make('password')
                    ->label('密碼')
                    ->password()
                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? bcrypt($state) : null)
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->required(fn (string $operation) => $operation === 'create')
                    ->helperText('編輯時若不變更請留空'),

                Toggle::make('is_admin')
                    ->label('管理員權限')
                    ->helperText('開啟後可登入後台並審核回報')
                    ->reactive()
                    ->afterStateUpdated(fn ($set) => $set('is_admin_confirm', false)),

                Toggle::make('is_admin_confirm')
                    ->label('確認變更管理員權限')
                    ->helperText('請勾選以確認此操作')
                    ->visible(fn (string $operation, $get, $record) => $operation === 'edit' &&
                        $record !== null &&
                        (bool) $get('is_admin') !== (bool) $record->is_admin
                    ),

                Toggle::make('is_banned')
                    ->label('封禁帳號')
                    ->helperText('封禁後該帳號將無法提交任何通報')
                    ->reactive(),

                Textarea::make('ban_reason')
                    ->label('封禁原因')
                    ->rows(2)
                    ->visible(fn ($get) => (bool) $get('is_banned'))
                    ->placeholder('請填寫封禁原因，方便日後查閱'),

                Toggle::make('is_private')
                    ->label('隱私模式')
                    ->helperText('開啟後公開頁面以「匿名市民」顯示，隱藏名稱與頭像'),
            ]);
    }
}
