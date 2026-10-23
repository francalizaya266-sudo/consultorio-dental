<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Catálogos siempre; datos de demostración solo en local.
     */
    public function run(): void
    {
        $this->call(CatalogSeeder::class);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
