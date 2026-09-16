<?php

declare(strict_types=1);

namespace Vigen\Tests\Fixtures;

use Vigen\Database\Model;

/**
 * A model exactly as Vigen generates one.
 */
class Account extends Model
{
    protected static string $table = 'accounts';

    protected array $fillable = ['name', 'email', 'password'];

    protected array $hidden = ['password'];
}
