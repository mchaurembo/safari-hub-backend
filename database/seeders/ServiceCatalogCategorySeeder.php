<?php

namespace Database\Seeders;

use App\Models\ServiceCatalogCategory;
use Illuminate\Database\Seeder;

class ServiceCatalogCategorySeeder extends Seeder
{
    public function run(): void
    {
        $garage = ['garage'];
        $both = ['garage', 'car_wash'];

        $categories = [
            ['code' => 'maintenance', 'name' => 'Routine maintenance', 'sort_order' => 10, 'applies_to' => $garage],
            ['code' => 'brakes', 'name' => 'Brakes', 'sort_order' => 20, 'applies_to' => $garage],
            ['code' => 'engine', 'name' => 'Engine & drivetrain', 'sort_order' => 30, 'applies_to' => $garage],
            ['code' => 'electrical', 'name' => 'Electrical & battery', 'sort_order' => 40, 'applies_to' => $garage],
            ['code' => 'tyres', 'name' => 'Tyres & wheels', 'sort_order' => 50, 'applies_to' => $garage],
            ['code' => 'suspension', 'name' => 'Suspension & steering', 'sort_order' => 60, 'applies_to' => $garage],
            ['code' => 'ac_cooling', 'name' => 'AC & cooling', 'sort_order' => 70, 'applies_to' => $garage],
            ['code' => 'body_paint', 'name' => 'Body & paint', 'sort_order' => 80, 'applies_to' => $garage],
            ['code' => 'diagnostics', 'name' => 'Diagnostics & inspection', 'sort_order' => 90, 'applies_to' => $garage],
            ['code' => 'car_wash', 'name' => 'Car wash & detailing', 'sort_order' => 100, 'applies_to' => $both],
            ['code' => 'other', 'name' => 'Other services', 'sort_order' => 110, 'applies_to' => $both],
        ];

        foreach ($categories as $row) {
            ServiceCatalogCategory::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'] ?? null,
                    'sort_order' => $row['sort_order'],
                    'applies_to' => $row['applies_to'],
                    'is_active' => true,
                ],
            );
        }
    }
}
