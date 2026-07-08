<?php

namespace Tests\Feature\Models;

use App\Models\PumpStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PumpStationTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_running_reflects_pumb_status(): void
    {
        $this->assertTrue(PumpStation::factory()->create(['pumb_status' => '運轉中'])->isRunning());
        $this->assertFalse(PumpStation::factory()->create(['pumb_status' => '停止'])->isRunning());
    }
}
