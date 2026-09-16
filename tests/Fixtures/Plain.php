<?php

declare(strict_types=1);

namespace Vigen\Tests\Fixtures;

use Vigen\Database\Model;

/**
 * A model whose table has no timestamp columns, so saving must not try to
 * write them.
 */
class Plain extends Model
{
    protected static string $table = 'plain_rows';

    protected static bool $timestamps = false;

    protected array $fillable = ['label'];
}
