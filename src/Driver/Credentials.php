<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use SensitiveParameterValue;

/**
 * The user name and password a driver keeps for reconnect(), out of every dump of the driver:
 * a closure shows what it captured in var_dump() and print_r(), and var_export() of an object
 * shows its properties - a SensitiveParameterValue shows its value in none of them.
 *
 * @internal
 */
final class Credentials
{
    private readonly SensitiveParameterValue $username;

    private readonly SensitiveParameterValue $password;

    public function __construct(?string $username, #[\SensitiveParameter] ?string $password)
    {
        $this->username = new SensitiveParameterValue($username);
        $this->password = new SensitiveParameterValue($password);
    }

    public function username(): ?string
    {
        $username = $this->username->getValue();

        return is_string($username) ? $username : null;
    }

    public function password(): ?string
    {
        $password = $this->password->getValue();

        return is_string($password) ? $password : null;
    }
}
