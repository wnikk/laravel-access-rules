<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

class DummyModelFactory extends Factory
{
    protected $model = DummyModel::class;

    public function definition()
    {
        return [
            'id'          => 42, // Fixed ID for testing
            'testuser_id' => $this->faker->unique()->numberBetween(1, 999999),
        ];
    }
}
