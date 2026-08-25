<?php

namespace Database\Seeders;

use App\Models\ProductUnit;
use Illuminate\Database\Seeder;

class ProductUnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'pcs', 'name' => 'Pieces (pcs)', 'sort_order' => 10],
            ['code' => 'set', 'name' => 'Set', 'sort_order' => 20],
            ['code' => 'pair', 'name' => 'Pair', 'sort_order' => 30],
            ['code' => 'box', 'name' => 'Box', 'sort_order' => 40],
            ['code' => 'pack', 'name' => 'Pack', 'sort_order' => 50],
            ['code' => 'ltr', 'name' => 'Litre (L)', 'sort_order' => 60],
            ['code' => 'ml', 'name' => 'Millilitre (ml)', 'sort_order' => 70],
            ['code' => 'kg', 'name' => 'Kilogram (kg)', 'sort_order' => 80],
            ['code' => 'g', 'name' => 'Gram (g)', 'sort_order' => 90],
            ['code' => 'm', 'name' => 'Metre (m)', 'sort_order' => 100],
            ['code' => 'roll', 'name' => 'Roll', 'sort_order' => 110],
            ['code' => 'tin', 'name' => 'Tin', 'sort_order' => 120],
            ['code' => 'bottle', 'name' => 'Bottle', 'sort_order' => 130],
            ['code' => 'service', 'name' => 'Service unit', 'sort_order' => 140],
        ];

        foreach ($units as $unit) {
            ProductUnit::updateOrCreate(
                ['code' => $unit['code']],
                [...$unit, 'is_active' => true],
            );
        }
    }
}
