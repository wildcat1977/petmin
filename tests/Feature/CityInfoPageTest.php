<?php

namespace Tests\Feature;

use App\Models\ExternalAlert;
use App\Models\PumpStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityInfoPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_normal_state_when_no_data(): void
    {
        $this->get('/info')->assertOk()->assertSee('正常上班上課');
    }

    public function test_page_shows_active_closure_and_running_pumps(): void
    {
        ExternalAlert::create([
            'source' => 'DGPA', 'cap_id' => 'C1', 'alert_type' => '停班停課',
            'headline' => '臺北市明天停止上班、停止上課', 'covers_taipei' => true,
            'effective_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        PumpStation::factory()->create(['name' => '玉成', 'pumb_status' => '運轉中']);

        $this->get('/info')->assertOk()
            ->assertSee('停止上班')
            ->assertSee('玉成');
    }
}
