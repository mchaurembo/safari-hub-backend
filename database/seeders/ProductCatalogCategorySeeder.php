<?php

namespace Database\Seeders;

use App\Models\ProductCatalogCategory;
use Illuminate\Database\Seeder;

class ProductCatalogCategorySeeder extends Seeder
{
    public function run(): void
    {
        $auto = ['spare_parts', 'garage'];
        $hardware = ['hardware'];
        $shop = ['general_shop'];
        $hospitality = ['restaurant', 'hotel'];

        $categories = [
            // Automotive / spare parts
            ['code' => 'engine_oils', 'name' => 'Engine oils & lubricants', 'sort_order' => 10, 'applies_to' => $auto],
            ['code' => 'filters', 'name' => 'Filters', 'sort_order' => 20, 'applies_to' => $auto],
            ['code' => 'brakes', 'name' => 'Brake parts', 'sort_order' => 30, 'applies_to' => $auto],
            ['code' => 'tyres_wheels', 'name' => 'Tyres & wheels', 'sort_order' => 40, 'applies_to' => $auto],
            ['code' => 'batteries', 'name' => 'Batteries & electrical', 'sort_order' => 50, 'applies_to' => $auto],
            ['code' => 'suspension', 'name' => 'Suspension & steering', 'sort_order' => 60, 'applies_to' => $auto],
            ['code' => 'belts_hoses', 'name' => 'Belts & hoses', 'sort_order' => 70, 'applies_to' => $auto],
            ['code' => 'fluids', 'name' => 'Coolants & fluids', 'sort_order' => 80, 'applies_to' => $auto],
            ['code' => 'lighting', 'name' => 'Lighting', 'sort_order' => 90, 'applies_to' => $auto],
            ['code' => 'body_parts', 'name' => 'Body parts & accessories', 'sort_order' => 100, 'applies_to' => $auto],
            ['code' => 'tools', 'name' => 'Tools & equipment', 'sort_order' => 110, 'applies_to' => array_values(array_unique([...$auto, ...$hardware]))],
            ['code' => 'consumables', 'name' => 'Workshop consumables', 'sort_order' => 120, 'applies_to' => $auto],
            ['code' => 'auto_fasteners', 'name' => 'Auto fasteners', 'sort_order' => 130, 'applies_to' => $auto],

            // Retail hardware store
            ['code' => 'hw_cement', 'name' => 'Cement & building materials', 'sort_order' => 200, 'applies_to' => $hardware],
            ['code' => 'hw_paint', 'name' => 'Paints & finishes', 'sort_order' => 210, 'applies_to' => $hardware],
            ['code' => 'hw_plumbing', 'name' => 'Plumbing', 'sort_order' => 220, 'applies_to' => $hardware],
            ['code' => 'hw_electrical', 'name' => 'Electrical fittings', 'sort_order' => 230, 'applies_to' => $hardware],
            ['code' => 'hw_timber', 'name' => 'Timber & boards', 'sort_order' => 240, 'applies_to' => $hardware],
            ['code' => 'hw_fasteners', 'name' => 'Nails, screws & fasteners', 'sort_order' => 250, 'applies_to' => $hardware],
            ['code' => 'hw_hand_tools', 'name' => 'Hand tools', 'sort_order' => 260, 'applies_to' => $hardware],
            ['code' => 'hw_safety', 'name' => 'Safety gear', 'sort_order' => 270, 'applies_to' => $hardware],
            ['code' => 'hw_garden', 'name' => 'Garden & outdoor', 'sort_order' => 280, 'applies_to' => $hardware],

            // General shop
            ['code' => 'shop_beverages', 'name' => 'Beverages', 'sort_order' => 300, 'applies_to' => $shop],
            ['code' => 'shop_staples', 'name' => 'Food staples', 'sort_order' => 310, 'applies_to' => $shop],
            ['code' => 'shop_household', 'name' => 'Household', 'sort_order' => 320, 'applies_to' => $shop],
            ['code' => 'shop_personal_care', 'name' => 'Personal care', 'sort_order' => 330, 'applies_to' => $shop],

            // Hospitality products
            ['code' => 'resto_food', 'name' => 'Food dishes', 'sort_order' => 400, 'applies_to' => $hospitality],
            ['code' => 'resto_drinks', 'name' => 'Drinks', 'sort_order' => 410, 'applies_to' => $hospitality],

            // Legacy code from first seed — keep but scope to auto only
            ['code' => 'hardware', 'name' => 'Auto hardware & fasteners', 'sort_order' => 135, 'applies_to' => $auto, 'is_active' => false],
            ['code' => 'general', 'name' => 'General merchandise (legacy)', 'sort_order' => 999, 'applies_to' => $shop, 'is_active' => false],
        ];

        foreach ($categories as $row) {
            ProductCatalogCategory::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'sort_order' => $row['sort_order'],
                    'applies_to' => $row['applies_to'],
                    'is_active' => $row['is_active'] ?? true,
                ],
            );
        }
    }
}
