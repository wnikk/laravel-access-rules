<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

class TestUserFactory extends Factory
{
    protected $model = TestUser::class;

    public function definition()
    {
        return [
            'id'    => $this->faker->unique()->numberBetween(1, 999999),
            'name'  => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
        ];
    }
}
