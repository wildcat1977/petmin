<?php

namespace App\Console\Commands;

use App\Models\TrashBin;

class ImportDogBagBoxes extends ImportTrashBins
{
    protected $signature = 'bins:import-dog-bag-boxes {--file= : CSV 路徑，預設 database/data/dog_bag_boxes.csv}';

    protected $description = '匯入臺北市狗便清潔袋箱位置總表（open data CSV，note 為「N處」）';

    protected function kind(): string
    {
        return TrashBin::KIND_DOG_BAG_BOX;
    }

    protected function source(): string
    {
        return TrashBin::SOURCE_OFFICIAL_DOG_BAG_BOX;
    }

    protected function defaultFile(): string
    {
        return database_path('data/dog_bag_boxes.csv');
    }

    protected function boxCount(array $row): int
    {
        return preg_match('/(\d+)/', $row['note'] ?? '', $m) ? max(1, (int) $m[1]) : 1;
    }
}
