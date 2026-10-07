<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_code' => 'STF-'.fake()->unique()->numberBetween(1000, 9999),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make(env('SEED_DEFAULT_PASSWORD', 'password')),
            'remember_token' => Str::random(10),
            // Khai rõ dù DB đã có default(true): `create()` KHÔNG nạp lại từ DB, nên thiếu dòng
            // này thì `$user->is_active` trong bộ nhớ là null và middleware EnsureAccountIsActive
            // coi như tài khoản bị khoá. Thực tế Auth nạp cả dòng nên không gặp — nhưng test thì
            // gặp, và một test đỏ vì lý do không có thật còn tệ hơn không có test.
            'is_active' => true,
        ];
    }
}
