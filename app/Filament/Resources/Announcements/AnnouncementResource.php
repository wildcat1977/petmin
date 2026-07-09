<?php

namespace App\Filament\Resources\Announcements;

use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = '公告與消息';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('標題（選填）')
                ->maxLength(100)
                ->columnSpanFull(),

            Toggle::make('is_active')
                ->label('立即顯示')
                ->default(true),

            Select::make('category')
                ->label('分類')
                ->options(Announcement::CATEGORY_LABELS)
                ->nullable(),

            RichEditor::make('body')
                ->label('內容')
                ->helperText('最新消息頁顯示完整內文；首頁彈窗顯示前 30 字摘要。')
                ->required()
                ->columnSpanFull(),

            TextInput::make('link_url')
                ->label('相關連結')
                ->url()
                ->maxLength(500),

            Toggle::make('is_pinned')
                ->label('置頂'),

            DateTimePicker::make('published_at')
                ->label('發布時間（設定後出現在最新消息頁；留空＝僅跑馬燈）'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                IconColumn::make('is_active')
                    ->label('顯示中')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),

                TextColumn::make('title')
                    ->label('標題')
                    ->default('（無標題）')
                    ->wrap()
                    ->width('32rem')
                    ->searchable(),

                TextColumn::make('body')
                    ->label('內容')
                    ->formatStateUsing(fn (?string $state): string => Str::limit(strip_tags((string) $state), 60))
                    ->searchable(),

                IconColumn::make('is_pinned')
                    ->boolean()
                    ->label('置頂'),

                TextColumn::make('published_at')
                    ->dateTime('Y-m-d H:i')
                    ->label('發布時間'),

                TextColumn::make('created_at')
                    ->label('建立時間')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
