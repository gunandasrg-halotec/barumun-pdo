<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        $code = strtoupper($this->faker->unique()->lexify('ROLE_???'));

        return [
            'name'        => $code,
            'code'        => $code,
            'description' => null,
        ];
    }
}
