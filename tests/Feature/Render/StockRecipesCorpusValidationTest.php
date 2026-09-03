<?php

namespace Tests\Feature\Render;

use App\Services\Site\PageLayoutRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockRecipesCorpusValidationTest extends TestCase
{
    private PageLayoutRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(PageLayoutRegistry::class);
    }

    public static function stockConfigFileDataProvider(): array
    {
        return [
            'home' => ['site_home_layouts', 'home'],
            'about' => ['site_about_layouts', 'about'],
            'service' => ['site_service_layouts', 'service'],
            'projects' => ['site_projects_layouts', 'projects'],
        ];
    }

    #[DataProvider('stockConfigFileDataProvider')]
    public function test_all_stock_recipes_pass_registry_validation(string $configName, string $kind): void
    {
        $recipes = config($configName);
        $this->assertIsArray($recipes, "Config file [{$configName}] must return an array");
        $this->assertNotEmpty($recipes, "Config file [{$configName}] must not be empty");

        foreach ($recipes as $key => $recipe) {
            $this->assertIsArray($recipe, "Recipe [{$key}] in [{$configName}] must be an array");

            $isUsable = $this->registry->isUsable($recipe, $kind);
            $hardErrors = $this->registry->hardErrors($recipe, $kind);

            $this->assertTrue(
                $isUsable,
                "Recipe [{$key}] in [{$configName}] failed isUsable() check. Hard errors: ".implode('; ', $hardErrors),
            );
            $this->assertEmpty(
                $hardErrors,
                "Recipe [{$key}] in [{$configName}] has hard errors: ".implode('; ', $hardErrors),
            );
        }
    }
}
