<?php

namespace Database\Factories;

use App\Models\PlantationUnit;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'role_id'            => Role::factory(),
            'plantation_unit_id' => null,
            'full_name'          => $this->faker->name(),
            'email'              => $this->faker->unique()->safeEmail(),
            'password_hash'      => Hash::make('password'),
            'whatsapp_number'    => '628' . $this->faker->numerify('#########'),
            'is_active'          => true,
        ];
    }
}
