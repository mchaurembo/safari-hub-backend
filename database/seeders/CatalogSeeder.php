<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Seeds platform product/service dropdown catalogs. */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProductUnitSeeder::class,
            ProductCatalogCategorySeeder::class,
            ProductCatalogItemSeeder::class,
            ServiceCatalogCategorySeeder::class,
            ServiceCatalogItemSeeder::class,
        ]);
    }
}
