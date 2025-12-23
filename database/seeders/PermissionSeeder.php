<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['id' => 1, 'name' => 'Dashboard', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 2, 'name' => 'Sales', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 3, 'name' => 'Sales', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 4, 'name' => 'Sales', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 5, 'name' => 'Sales', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 6, 'name' => 'Purchase', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 7, 'name' => 'Purchase', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 8, 'name' => 'Purchase', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 9, 'name' => 'Purchase', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 10, 'name' => 'Branch', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 11, 'name' => 'Branch', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 12, 'name' => 'Branch', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 13, 'name' => 'Branch', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 14, 'name' => 'Counter', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 15, 'name' => 'Counter', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 16, 'name' => 'Counter', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 17, 'name' => 'Counter', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 18, 'name' => 'User', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 19, 'name' => 'User', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 20, 'name' => 'User', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 21, 'name' => 'User', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 22, 'name' => 'Role', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 23, 'name' => 'Role', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 24, 'name' => 'Role', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 25, 'name' => 'Role', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 26, 'name' => 'Warehouse', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 27, 'name' => 'Warehouse', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 28, 'name' => 'Warehouse', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 29, 'name' => 'Warehouse', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 30, 'name' => 'Product', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 31, 'name' => 'Product', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 32, 'name' => 'Product', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 33, 'name' => 'Product', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 34, 'name' => 'Inventory', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 35, 'name' => 'Inventory', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 36, 'name' => 'Inventory', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 37, 'name' => 'Inventory', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 38, 'name' => 'Customer', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 39, 'name' => 'Customer', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 40, 'name' => 'Customer', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 41, 'name' => 'Customer', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 42, 'name' => 'Category', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 43, 'name' => 'Category', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 44, 'name' => 'Category', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 45, 'name' => 'Category', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 46, 'name' => 'POS', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 47, 'name' => 'Payment method', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 48, 'name' => 'Payment method', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 49, 'name' => 'Payment method', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 50, 'name' => 'Payment method', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 51, 'name' => 'Wallet', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 52, 'name' => 'Wallet', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 53, 'name' => 'Wallet', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 54, 'name' => 'Wallet', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 55, 'name' => 'Promotion', 'action' => 'View', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 56, 'name' => 'Promotion', 'action' => 'Create', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 57, 'name' => 'Promotion', 'action' => 'Update', 'created_by' => 1, 'updated_by' => 1],
            ['id' => 58, 'name' => 'Promotion', 'action' => 'Delete', 'created_by' => 1, 'updated_by' => 1],
            
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['id' => $permission['id']],
                $permission
            );
        }
    }
}

// php artisan db:seed --class=PermissionSeeder