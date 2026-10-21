<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 狗狗圖鑑裡的一張卡：某筆回報送出後扭蛋抽到的（見 create_dog_cards_table migration）。 */
class DogCard extends Model
{
    protected $fillable = ['user_id', 'report_id', 'card_key'];

    /** @return array<string, array{story: string, title: string, image: string, line: string}> */
    public static function definitions(): array
    {
        return config('petmin.gacha.cards', []);
    }

    public function definition(): ?array
    {
        return self::definitions()[$this->card_key] ?? null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
