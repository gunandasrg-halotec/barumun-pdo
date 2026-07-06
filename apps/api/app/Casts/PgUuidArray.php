<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cast untuk kolom UUID[] di PostgreSQL.
 * Laravel array cast menghasilkan JSON ["a","b"] yang tidak diterima PostgreSQL.
 * Cast ini mengkonversi ke format {"a","b"} saat write dan sebaliknya saat read.
 */
class PgUuidArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) return null;

        if (DB::getDriverName() === 'sqlite') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        // PostgreSQL returns: {"uuid1","uuid2"} or {uuid1,uuid2}
        $value = trim($value, '{}');
        if ($value === '') return [];

        return array_map(
            fn ($v) => trim($v, '"'),
            str_getcsv($value, ',', '"', "\0")
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) return null;
        if (!is_array($value) || empty($value)) return null;

        if (DB::getDriverName() === 'sqlite') {
            return json_encode(array_values($value));
        }

        // Format PostgreSQL array literal: {uuid1,uuid2}
        return '{' . implode(',', $value) . '}';
    }
}
