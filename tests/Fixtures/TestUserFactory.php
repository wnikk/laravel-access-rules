<?php

namespace Tests\Fixtures;

use Tests\Fixtures\TestUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TestUserFactory extends Factory
{
    protected $model = TestUser::class;

    public function definition()
    {
        return [
            'id' => $this->faker->unique()->numberBetween(1, 999999),
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
        ];
    }
}