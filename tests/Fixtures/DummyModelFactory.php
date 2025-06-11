<?php
namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Fixtures\DummyModel;

/**
 * Dummy model factory for creating test instances.
 */
class DummyModelFactory extends Factory
{
    protected $model = DummyModel::class;

    public function definition()
    {
        return [
            'id' => 42, // Fixed ID for testing
            // Assuming testuser_id is a foreign key to a user model
            'testuser_id' => $this->faker->unique()->numberBetween(1, 999999),
        ];
    }
}