<?php

declare(strict_types=1);

namespace Monoverse\VoicebotSync\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $title
 * @property string $sku
 * @property float $price
 */
final class FakeProduct extends Model
{
    use SoftDeletes;

    protected $table = 'fake_products';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = ['price' => 'float'];
}
