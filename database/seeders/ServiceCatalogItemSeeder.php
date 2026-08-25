<?php

namespace Database\Seeders;

use App\Models\ServiceCatalogCategory;
use App\Models\ServiceCatalogItem;
use Illuminate\Database\Seeder;

class ServiceCatalogItemSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ServiceCatalogCategorySeeder::class);

        $items = [
            'maintenance' => [
                ['code' => 'oil_change', 'name' => 'Oil change', 'default_duration_minutes' => 45, 'default_pricing_type' => 'fixed'],
                ['code' => 'full_service', 'name' => 'Full service', 'default_duration_minutes' => 180, 'default_pricing_type' => 'fixed'],
                ['code' => 'oil_filter_change', 'name' => 'Oil & filter change', 'default_duration_minutes' => 60, 'default_pricing_type' => 'fixed'],
                ['code' => 'tune_up', 'name' => 'Engine tune-up', 'default_duration_minutes' => 120, 'default_pricing_type' => 'estimate'],
            ],
            'brakes' => [
                ['code' => 'brake_pads_replace', 'name' => 'Brake pads replacement', 'default_duration_minutes' => 90, 'default_pricing_type' => 'fixed'],
                ['code' => 'brake_disc_replace', 'name' => 'Brake disc replacement', 'default_duration_minutes' => 120, 'default_pricing_type' => 'estimate'],
                ['code' => 'brake_fluid_flush', 'name' => 'Brake fluid flush', 'default_duration_minutes' => 60, 'default_pricing_type' => 'fixed'],
                ['code' => 'handbrake_adjust', 'name' => 'Handbrake adjustment', 'default_duration_minutes' => 45, 'default_pricing_type' => 'fixed'],
            ],
            'engine' => [
                ['code' => 'timing_belt', 'name' => 'Timing belt replacement', 'default_duration_minutes' => 240, 'default_pricing_type' => 'estimate'],
                ['code' => 'clutch_replace', 'name' => 'Clutch replacement', 'default_duration_minutes' => 360, 'default_pricing_type' => 'estimate'],
                ['code' => 'spark_plugs', 'name' => 'Spark plugs replacement', 'default_duration_minutes' => 60, 'default_pricing_type' => 'fixed'],
                ['code' => 'fuel_pump', 'name' => 'Fuel pump service', 'default_duration_minutes' => 180, 'default_pricing_type' => 'estimate'],
            ],
            'electrical' => [
                ['code' => 'battery_replace', 'name' => 'Battery replacement', 'default_duration_minutes' => 30, 'default_pricing_type' => 'fixed'],
                ['code' => 'alternator_repair', 'name' => 'Alternator repair', 'default_duration_minutes' => 180, 'default_pricing_type' => 'estimate'],
                ['code' => 'starter_repair', 'name' => 'Starter motor repair', 'default_duration_minutes' => 180, 'default_pricing_type' => 'estimate'],
                ['code' => 'wiring_fix', 'name' => 'Electrical wiring fix', 'default_duration_minutes' => 120, 'default_pricing_type' => 'estimate'],
            ],
            'tyres' => [
                ['code' => 'tyre_fitment', 'name' => 'Tyre fitment', 'default_duration_minutes' => 30, 'default_pricing_type' => 'fixed'],
                ['code' => 'wheel_balancing', 'name' => 'Wheel balancing', 'default_duration_minutes' => 30, 'default_pricing_type' => 'fixed'],
                ['code' => 'wheel_alignment', 'name' => 'Wheel alignment', 'default_duration_minutes' => 60, 'default_pricing_type' => 'fixed'],
                ['code' => 'puncture_repair', 'name' => 'Puncture repair', 'default_duration_minutes' => 30, 'default_pricing_type' => 'fixed'],
            ],
            'suspension' => [
                ['code' => 'shock_replace', 'name' => 'Shock absorber replacement', 'default_duration_minutes' => 120, 'default_pricing_type' => 'estimate'],
                ['code' => 'ball_joint_replace', 'name' => 'Ball joint replacement', 'default_duration_minutes' => 120, 'default_pricing_type' => 'estimate'],
                ['code' => 'bush_replace', 'name' => 'Bush replacement', 'default_duration_minutes' => 90, 'default_pricing_type' => 'estimate'],
            ],
            'ac_cooling' => [
                ['code' => 'ac_gas_refill', 'name' => 'AC gas refill', 'default_duration_minutes' => 60, 'default_pricing_type' => 'fixed'],
                ['code' => 'ac_service', 'name' => 'AC full service', 'default_duration_minutes' => 120, 'default_pricing_type' => 'estimate'],
                ['code' => 'radiator_flush', 'name' => 'Radiator flush', 'default_duration_minutes' => 90, 'default_pricing_type' => 'fixed'],
                ['code' => 'thermostat_replace', 'name' => 'Thermostat replacement', 'default_duration_minutes' => 90, 'default_pricing_type' => 'estimate'],
            ],
            'body_paint' => [
                ['code' => 'dent_repair', 'name' => 'Dent repair', 'default_duration_minutes' => 180, 'default_pricing_type' => 'estimate'],
                ['code' => 'panel_paint', 'name' => 'Panel paint', 'default_duration_minutes' => 240, 'default_pricing_type' => 'estimate'],
                ['code' => 'full_respray', 'name' => 'Full body respray', 'default_duration_minutes' => 1440, 'default_pricing_type' => 'estimate'],
                ['code' => 'bumper_repair', 'name' => 'Bumper repair', 'default_duration_minutes' => 180, 'default_pricing_type' => 'estimate'],
            ],
            'diagnostics' => [
                ['code' => 'obd_scan', 'name' => 'OBD / computer diagnostics', 'default_duration_minutes' => 45, 'default_pricing_type' => 'fixed'],
                ['code' => 'pre_purchase_insp', 'name' => 'Pre-purchase inspection', 'default_duration_minutes' => 90, 'default_pricing_type' => 'fixed'],
                ['code' => 'roadworthy', 'name' => 'Roadworthy check', 'default_duration_minutes' => 60, 'default_pricing_type' => 'fixed'],
            ],
            'car_wash' => [
                ['code' => 'exterior_wash', 'name' => 'Exterior wash', 'default_duration_minutes' => 30, 'default_pricing_type' => 'fixed'],
                ['code' => 'interior_clean', 'name' => 'Interior cleaning', 'default_duration_minutes' => 45, 'default_pricing_type' => 'fixed'],
                ['code' => 'full_detail', 'name' => 'Full detailing', 'default_duration_minutes' => 180, 'default_pricing_type' => 'fixed'],
                ['code' => 'engine_bay_clean', 'name' => 'Engine bay clean', 'default_duration_minutes' => 45, 'default_pricing_type' => 'fixed'],
            ],
            'other' => [
                ['code' => 'towing_assist', 'name' => 'Towing assistance', 'default_duration_minutes' => 60, 'default_pricing_type' => 'estimate'],
                ['code' => 'custom_job', 'name' => 'Custom workshop job', 'default_duration_minutes' => 60, 'default_pricing_type' => 'estimate'],
            ],
        ];

        foreach ($items as $categoryCode => $rows) {
            $category = ServiceCatalogCategory::where('code', $categoryCode)->first();
            if (! $category) {
                continue;
            }

            foreach ($rows as $i => $row) {
                ServiceCatalogItem::updateOrCreate(
                    ['code' => $row['code']],
                    [
                        'service_catalog_category_id' => $category->id,
                        'name' => $row['name'],
                        'description' => $row['description'] ?? null,
                        'default_pricing_type' => $row['default_pricing_type'] ?? 'fixed',
                        'default_duration_minutes' => $row['default_duration_minutes'] ?? null,
                        'sort_order' => ($i + 1) * 10,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
