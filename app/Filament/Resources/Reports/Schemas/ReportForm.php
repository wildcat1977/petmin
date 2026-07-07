<?php

namespace App\Filament\Resources\Reports\Schemas;

use App\Models\Report;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ReportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                View::make('filament.components.map-picker')
                    ->columnSpanFull(),

                Select::make('category')
                    ->label('災情分類')
                    ->options(Report::CATEGORY_LABELS)
                    ->live()
                    ->required(),

                TextInput::make('extra_value')
                    ->label('補充數值')
                    ->numeric()
                    ->nullable()
                    ->visible(fn (Get $get) => $get('category') === Report::CATEGORY_FLOOD),

                TextInput::make('latitude')
                    ->label('緯度')
                    ->numeric()
                    ->required(),

                TextInput::make('longitude')
                    ->label('經度')
                    ->numeric()
                    ->required(),

                Textarea::make('description')
                    ->label('描述')
                    ->columnSpanFull(),

                Select::make('status')
                    ->label('狀態')
                    ->options([
                        Report::STATUS_PENDING => '待審核',
                        Report::STATUS_APPROVED => '✅ 已核准（照片公開）',
                        Report::STATUS_REJECTED => '已拒絕',
                    ])
                    ->helperText('內容適切性審核；與災情核實分離')
                    ->required(),

                Select::make('verification_level')
                    ->label('災情核實')
                    ->options(Report::VERIFICATION_LABELS)
                    ->helperText('官方核實請用此欄位標記 official')
                    ->required(),

                DateTimePicker::make('resolved_at')
                    ->label('已排除時間')
                    ->nullable()
                    ->helperText('官方確認災情已排除'),

                Select::make('rejection_reason')
                    ->label('拒絕原因')
                    ->options(Report::REJECTION_REASONS)
                    ->nullable()
                    ->native(false)
                    ->placeholder('（未填）')
                    ->helperText('僅在拒絕時填寫，使用者將看到此原因'),

                DateTimePicker::make('reviewed_at')
                    ->label('審核時間')
                    ->disabled()
                    ->dehydrated(false)
                    ->displayFormat('Y-m-d H:i'),

                FileUpload::make('image_path')
                    ->label('照片')
                    ->disk('public')
                    ->directory('reports')
                    ->image()
                    ->fetchFileInformation(false)
                    ->columnSpanFull()
                    ->hintAction(
                        Action::make('viewImage')
                            ->label('開新頁查看原圖')
                            ->icon(Heroicon::ArrowTopRightOnSquare)
                            ->url(fn (?Report $record) => $record?->image_path
                                ? Storage::disk('public')->url($record->image_path)
                                : null)
                            ->openUrlInNewTab()
                            ->visible(fn (?Report $record): bool => (bool) $record?->image_path)
                    ),
            ]);
    }
}
