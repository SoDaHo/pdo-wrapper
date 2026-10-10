<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Driver;

use SensitiveParameterValue;

/**
 * What a driver keeps for reconnect() beyond its DSN - user name, password and the PDO options -,
 * out of every dump of the driver: a closure shows what it captured in var_dump() and print_r(),
 * and var_export() of an object shows its properties; a SensitiveParameterValue shows its value
 * in none of them.
 *
 * @internal
 */
final class ConnectionSettings
{
    private readonly SensitiveParameterValue $username;

    private readonly SensitiveParameterValue $password;

    private readonly SensitiveParameterValue $options;

    /**
     * @param array<int, mixed> $options
     */
    public function __construct(?string $username, #[\SensitiveParameter] ?string $password, #[\SensitiveParameter] array $options)
    {
        $this->username = new SensitiveParameterValue($username);
        $this->password = new SensitiveParameterValue($password);
        $this->options = new SensitiveParameterValue($options);
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

    /**
     * @return array<int, mixed>
     */
    public function options(): array
    {
        return $this->options->getValue();
    }
}
