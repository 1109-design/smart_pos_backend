<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Central key/value store for platform-wide admin settings, e.g. the
 * EcoCash and WhatsApp numbers shown to locked-out merchants.
 */
class Setting extends Model
{
    public const PAYMENT_ECOCASH_NUMBER = 'payment_ecocash_number';

    public const PAYMENT_ECOCASH_NAME = 'payment_ecocash_name';

    public const PAYMENT_WHATSAPP_NUMBER = 'payment_whatsapp_number';

    public const PAYMENT_INSTRUCTIONS = 'payment_instructions';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::find($key)?->value ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Fetch several settings in one query.
     *
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    public static function getMany(array $keys): array
    {
        $found = static::whereIn('key', $keys)->pluck('value', 'key');

        return collect($keys)
            ->mapWithKeys(fn (string $key) => [$key => $found[$key] ?? null])
            ->all();
    }

    /**
     * The payment details shown to merchants on the lock screen.
     *
     * @return array{ecocash_number: string|null, ecocash_name: string|null, whatsapp_number: string|null, instructions: string|null}
     */
    public static function paymentInfo(): array
    {
        $values = static::getMany([
            self::PAYMENT_ECOCASH_NUMBER,
            self::PAYMENT_ECOCASH_NAME,
            self::PAYMENT_WHATSAPP_NUMBER,
            self::PAYMENT_INSTRUCTIONS,
        ]);

        return [
            'ecocash_number' => $values[self::PAYMENT_ECOCASH_NUMBER],
            'ecocash_name' => $values[self::PAYMENT_ECOCASH_NAME],
            'whatsapp_number' => $values[self::PAYMENT_WHATSAPP_NUMBER],
            'instructions' => $values[self::PAYMENT_INSTRUCTIONS],
        ];
    }
}
