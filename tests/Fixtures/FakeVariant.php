<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $product_id
 * @property string $color_uk
 * @property string|null $color_en
 * @property string|null $color_hex
 * @property string|null $size
 * @property float $price
 * @property int $stock_qty
 */
final class FakeVariant extends Model
{
    protected $table = 'fake_variants';

    protected $guarded = [];

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = ['price' => 'float', 'stock_qty' => 'int'];
}
