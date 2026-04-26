<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'الزيتون',
                'price' => 35,
                'unit' => 'كيلو',
                'description' => 'زيتون مغربي أصلي وعالي الجودة من منطقة فاس',
                'category' => 'مواد غذائية',
                'in_stock' => true,
            ],
            [
                'name' => 'زيت الزيتون',
                'price' => 60,
                'unit' => 'لتر',
                'description' => 'زيت زيتون بكر ممتاز، عصرة أولى',
                'category' => 'مواد غذائية',
                'in_stock' => true,
            ],
            [
                'name' => 'العسل',
                'price' => 150,
                'unit' => 'كيلو',
                'description' => 'عسل طبيعي 100% من جبال الأطلس',
                'category' => 'مواد غذائية',
                'in_stock' => true,
            ],
            [
                'name' => 'اللوز',
                'price' => 80,
                'unit' => 'كيلو',
                'description' => 'لوز مغربي فاخر من منطقة تافراوت',
                'category' => 'مكسرات',
                'in_stock' => true,
            ],
            [
                'name' => 'التمور',
                'price' => 45,
                'unit' => 'كيلو',
                'description' => 'تمور مجهولة، طازجة وعالية الجودة',
                'category' => 'مواد غذائية',
                'in_stock' => true,
            ],
            [
                'name' => 'الأرز',
                'price' => 25,
                'unit' => 'كيلو',
                'description' => 'أرز بسمتي فاخر',
                'category' => 'مواد غذائية',
                'in_stock' => true,
            ],
            [
                'name' => 'الزعفران',
                'price' => 200,
                'unit' => 'غرام',
                'description' => 'زعفران تاليوين أصلي',
                'category' => 'توابل',
                'in_stock' => true,
            ],
        ];

        $path = storage_path('app/products.json');
        
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
