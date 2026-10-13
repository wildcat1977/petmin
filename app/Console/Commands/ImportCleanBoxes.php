<?php

namespace App\Console\Commands;

use App\Models\TrashBin;

class ImportCleanBoxes extends ImportTrashBins
{
    protected $signature = 'bins:import-clean-boxes {--file= : CSV 路徑，預設 database/data/clean_boxes.csv}';

    protected $description = '匯入臺北市行人專用清潔箱位置（open data CSV）';

    protected function kind(): string
    {
        return TrashBin::KIND_CLEAN_BOX;
    }

    protected function source(): string
    {
        return TrashBin::SOURCE_OFFICIAL_CLEAN_BOX;
    }

    protected function defaultFile(): string
    {
        return database_path('data/clean_boxes.csv');
    }
}
