<?php

namespace Tests\Feature;

use App\Filament\Resources\ReportCategories\Pages\CreateReportCategory;
use App\Models\ReportCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 分類名稱是後台隨時能新增修改的資料，不能靠 lang/en.json（要改程式部署）：
 * 分類自己存英文名稱，英文版網站顯示它；沒填就退回中文，不會出現空白標籤。
 */
class ReportCategoryEnglishNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_follows_locale_and_falls_back_to_chinese(): void
    {
        $named = ReportCategory::create(['slug' => 'leak', 'name' => '漏水', 'name_en' => 'Leaking', 'color' => '#000000', 'sort_order' => 90]);
        $unnamed = ReportCategory::create(['slug' => 'other', 'name' => '其他狀況', 'color' => '#000000', 'sort_order' => 91]);

        app()->setLocale('zh_TW');
        $this->assertSame('漏水', $named->label());
        $this->assertSame('漏水', ReportCategory::labels()['leak']);

        app()->setLocale('en');
        $this->assertSame('Leaking', $named->label());
        $this->assertSame('Leaking', ReportCategory::labels()['leak']);
        $this->assertSame('其他狀況', $unnamed->label());
    }

    public function test_built_in_categories_ship_with_english_names(): void
    {
        $this->assertSame('Bin full!', ReportCategory::bySlug('full')->name_en);
        $this->assertSame([], ReportCategory::allOrdered()->whereNull('name_en')->pluck('slug')->all());
    }

    public function test_english_visitors_see_english_category_layers(): void
    {
        $this->withoutVite();

        $this->withHeader('Accept-Language', 'en-US,en;q=0.9')->get('/')
            ->assertOk()
            ->assertSee('Bin full!')
            ->assertDontSee('垃圾桶滿了！');
    }

    public function test_admin_can_enter_english_name_when_creating(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateReportCategory::class)
            ->fillForm(['slug' => 'leak', 'name' => '漏水', 'name_en' => 'Leaking', 'color' => '#22c55e', 'sort_order' => 70, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Leaking', ReportCategory::where('slug', 'leak')->value('name_en'));
    }
}
