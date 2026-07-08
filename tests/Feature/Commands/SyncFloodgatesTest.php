<?php

namespace Tests\Feature\Commands;

use App\Models\Floodgate;
use App\Services\DataSourceHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncFloodgatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_floodgates_are_ingested_with_coords(): void
    {
        Http::fake(['data.taipei/*' => Http::response(['result' => ['results' => [
            ['riverside_park' => '景美河濱公園', 'name' => '景1,育英', 'description' => '文山區育英街底',
                'longitude' => '121.5369669', 'latitude' => '24.98947221'],
            ['riverside_park' => '大稻埕', 'name' => '5號水門', 'description' => '民權西路底',
                'longitude' => '121.5100', 'latitude' => '25.0600'],
        ]]])]);

        $this->artisan('hazdar:sync-floodgates')->assertSuccessful();

        $this->assertSame(2, Floodgate::count());
        $gate = Floodgate::where('name', '景1,育英')->sole();
        $this->assertEqualsWithDelta(24.98947221, $gate->latitude, 0.00001);
        $this->assertEqualsWithDelta(121.5369669, $gate->longitude, 0.00001);
        $this->assertSame(DataSourceHealth::STATUS_OK, app(DataSourceHealth::class)->status('floodgates')['status']);

        // 重跑 upsert 不重複
        $this->artisan('hazdar:sync-floodgates')->assertSuccessful();
        $this->assertSame(2, Floodgate::count());
    }

    public function test_failure_degrades_health(): void
    {
        Http::fake(['data.taipei/*' => Http::response('x', 500)]);
        $this->artisan('hazdar:sync-floodgates')->assertFailed();
        $this->assertSame(DataSourceHealth::STATUS_DOWN, app(DataSourceHealth::class)->status('floodgates')['status']);
    }
}
