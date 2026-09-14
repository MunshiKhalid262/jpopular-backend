<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => (string) fake()->numberBetween(7000000000, 9999999999),
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'Kerala',
            // Kerala. The seller defaults to the same, so the common case in
            // tests is intra-state (CGST + SGST).
            'state_code' => '32',
            'pincode' => (string) fake()->numberBetween(600000, 699999),
            'gstin' => null,
            'notes' => null,
            'is_active' => true,
        ];
    }

    /** A customer in another state, producing an inter-state (IGST) supply. */
    public function interState(): static
    {
        return $this->state(fn (): array => [
            'state' => 'Karnataka',
            'state_code' => '29',
        ]);
    }

    /** Registered under GST, so the invoice carries their GSTIN. */
    public function registered(): static
    {
        return $this->state(fn (): array => [
            'gstin' => '32'.Str::upper(Str::random(3)).'PS'.fake()->numberBetween(1000, 9999).'A1Z5',
        ]);
    }

    /**
     * No state code, so a GST invoice cannot resolve the place of supply.
     */
    public function withoutStateCode(): static
    {
        return $this->state(fn (): array => [
            'state' => null,
            'state_code' => null,
        ]);
    }
}
