<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Tests\Support;

use PDO;

/**
 * A class that extends PDO and cannot be created: what 'pdoClass' must refuse before new does.
 */
abstract class AbstractPdo extends PDO
{
}
