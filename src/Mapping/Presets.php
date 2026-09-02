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
     * @param  array<string, string|\Closure>  $attributes  facet axis label => column / dot-path / closure ($m, $locale)
     * @return array<string, mixed>
     */
    public static function product(array $columns, ?string $currency = null, array $attributes = []): array
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

        if ($attributes !== []) {
            $map['payload.attributes'] = self::attributeBuilder($attributes);
        }

        return $map;
    }

    /**
     * Build the `payload.attributes` closure from a facet-axis map. Each entry becomes a
     * grounded facet ({name, slug, values: [...]}) the backend indexes for attribute
     * filtering — color/size/material on a flat product row that has no variation table.
     * Empty or null axis values are dropped; a scalar yields one value, an array/Collection
     * yields many. Mirrors the WooCommerce plugin's top-level product `attributes` shape.
     *
     * @param  array<string, string|\Closure>  $attributes  axis label => column / dot-path / closure ($m, $locale)
     * @return \Closure(object, ?string): list<array<string, mixed>>
     */
    public static function attributeBuilder(array $attributes): \Closure
    {
        return static function (object $model, ?string $locale = null) use ($attributes): array {
            $out = [];
            foreach ($attributes as $label => $spec) {
                $axisName = (string) $label;
                $resolved = $spec instanceof \Closure
                    ? $spec($model, $locale)
                    : data_get($model, $spec);
                $values = self::attributeValues($resolved);
                if ($values === []) {
                    continue;
                }
                $out[] = [
                    'name' => $axisName,
                    'slug' => self::slugify($axisName),
                    'values' => $values,
                ];
            }

            return $out;
        };
    }

    /**
     * Build the `variations` config block (sibling of `map`, NOT merged into it) that
     * EntityMapper turns into canonical `variant_axes` + inline `variations[]` from a
     * relation. `axes` maps an axis slug => {name, value, value_slug?, value_external_id?};
     * `fields` adds per-variation columns (price_amount, stock_status, …).
     *
     * @param  string|\Closure  $items  relation name / dot-path / closure ($product) => iterable<Model>
     * @param  string|\Closure  $externalId  variation id column / closure ($variant, $locale)
     * @param  array<string, array<string, mixed>>  $axes
     * @param  array<string, string|\Closure>  $fields
     * @return array<string, mixed>
     */
    public static function variations(string|\Closure $items, string|\Closure $externalId, array $axes, array $fields = []): array
    {
        return [
            'items' => $items,
            'external_id' => $externalId,
            'axes' => $axes,
            'fields' => $fields,
        ];
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

    /** @return list<string> */
    private static function attributeValues(mixed $resolved): array
    {
        if ($resolved instanceof Collection) {
            $resolved = $resolved->all();
        }
        $items = is_array($resolved) ? $resolved : [$resolved];
        $values = [];
        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $value = trim((string) $item);
            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private static function slugify(string $value): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9]+/u', '-', $value);
        $slug = mb_strtolower(trim($ascii, '-'));

        return $slug !== '' ? $slug : mb_strtolower(trim($value));
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
