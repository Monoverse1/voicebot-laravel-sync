<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Mapping;

use Illuminate\Support\Collection;

/**
 * Ready-made config maps for the common entity kinds. A merchant declares the few
 * columns they have and gets the full canonical payload — money to integer minor
 * units, content to plain text, taxonomy to slug lists, stock to a canonical status —
 * without hand-writing the closures. Drop the result straight into `'map'`.
 *
 *   'map' => Presets::product([
 *       'name' => 'title', 'price' => 'price', 'description' => 'body',
 *       'stock' => 'in_stock', 'categories' => 'categories', 'permalink' => 'url',
 *   ]),
 */
final class Presets
{
    /**
     * @param  array<string, string>  $columns  canonical key => the model column / relation / dot-path
     * @return array<string, mixed>
     */
    public static function product(array $columns, ?string $currency = null): array
    {
        $map = [];
        self::copy($map, $columns, ['name', 'sku', 'slug', 'product_type', 'permalink']);

        foreach (['price' => 'price_amount', 'regular_price' => 'regular_price_amount', 'sale_price' => 'sale_price_amount'] as $in => $out) {
            if (isset($columns[$in])) {
                $col = $columns[$in];
                $map["payload.$out"] = static fn (object $m): int => self::minor(data_get($m, $col));
            }
        }
        $map['payload.currency'] = static fn (): string => $currency ?? self::currency();

        foreach (['description', 'short_description'] as $key) {
            if (isset($columns[$key])) {
                $col = $columns[$key];
                $map["payload.$key"] = static fn (object $m): string => self::text(data_get($m, $col));
            }
        }
        if (isset($columns['stock_status'])) {
            $map['payload.stock_status'] = $columns['stock_status'];
        } elseif (isset($columns['stock'])) {
            $col = $columns['stock'];
            $map['payload.stock_status'] = static fn (object $m): string => data_get($m, $col) ? 'instock' : 'outofstock';
        }
        if (isset($columns['stock_quantity'])) {
            $col = $columns['stock_quantity'];
            $map['payload.stock_quantity'] = static fn (object $m): int => (int) self::scalar(data_get($m, $col));
        }
        foreach (['categories', 'tags'] as $key) {
            if (isset($columns[$key])) {
                $rel = $columns[$key];
                $map["payload.$key"] = static fn (object $m): array => self::slugs(data_get($m, $rel));
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    public static function category(array $columns): array
    {
        $map = [];
        self::copy($map, $columns, ['name', 'slug', 'permalink']);
        if (isset($columns['description'])) {
            $col = $columns['description'];
            $map['payload.description'] = static fn (object $m): string => self::text(data_get($m, $col));
        }
        if (isset($columns['parent_id'])) {
            $col = $columns['parent_id'];
            $map['payload.parent_external_id'] = static function (object $m) use ($col): ?string {
                $parent = data_get($m, $col);

                return $parent === null || $parent === '' ? null : 'laravel:category:'.self::scalar($parent);
            };
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, mixed>
     */
    public static function page(array $columns): array
    {
        $map = [];
        self::copy($map, $columns, ['title', 'slug', 'permalink']);
        foreach (['content_text' => 'content', 'excerpt' => 'excerpt'] as $out => $alias) {
            $col = $columns[$out] ?? ($columns[$alias] ?? null);
            if ($col !== null) {
                $map["payload.$out"] = static fn (object $m): string => self::text(data_get($m, $col));
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  array<string, string>  $columns
     * @param  list<string>  $keys
     */
    private static function copy(array &$map, array $columns, array $keys): void
    {
        foreach ($keys as $key) {
            if (isset($columns[$key])) {
                $map["payload.$key"] = $columns[$key];
            }
        }
    }

    private static function minor(mixed $value): int
    {
        return (int) round(((float) self::scalar($value)) * 100);
    }

    private static function text(mixed $value): string
    {
        return trim(strip_tags((string) self::scalar($value)));
    }

    /** @return list<string> */
    private static function slugs(mixed $relation): array
    {
        $items = $relation instanceof Collection ? $relation->all() : (is_iterable($relation) ? $relation : []);
        $slugs = [];
        foreach ($items as $item) {
            $slug = data_get($item, 'slug');
            if (is_scalar($slug) && (string) $slug !== '') {
                $slugs[] = (string) $slug;
            }
        }

        return $slugs;
    }

    private static function currency(): string
    {
        $value = function_exists('config') ? config('voicebot.currency', 'UAH') : 'UAH';

        return is_string($value) && $value !== '' ? $value : 'UAH';
    }

    private static function scalar(mixed $value): string|int|float
    {
        return is_int($value) || is_float($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
