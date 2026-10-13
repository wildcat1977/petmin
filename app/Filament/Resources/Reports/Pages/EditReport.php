<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use GuzzleHttp\Client;

class EditReport extends EditRecord
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * 儲存後自動對新座標執行 reverse geocode，更新行政區地址
     */
    protected function afterSave(): void
    {
        $record = $this->getRecord();

        try {
            $resp = (new Client([
                'headers' => ['User-Agent' => 'Petmin/1.0'],
                'timeout' => 8,
            ]))->get('https://nominatim.openstreetmap.org/reverse', [
                'query' => [
                    'lat' => $record->latitude,
                    'lon' => $record->longitude,
                    'format' => 'json',
                    'accept-language' => 'zh-TW',
                ],
            ]);

            $data = json_decode((string) $resp->getBody(), true);
            $a = $data['address'] ?? [];
            $city = $a['city'] ?? $a['county'] ?? $a['state'] ?? '';
            $district = $a['city_district'] ?? $a['suburb'] ?? $a['town'] ?? $a['village'] ?? '';
            $parts = array_filter([$city, $district]);
            $address = implode(' ', $parts) ?: null;

            $record->updateQuietly(['address' => $address]);
        } catch (\Throwable) {
            // geocode 失敗不阻止儲存，靜默略過
        }
    }
}
