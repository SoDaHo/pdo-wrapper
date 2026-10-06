<?php

declare(strict_types=1);

namespace Sodaho\PdoWrapper\Exception;

/**
 * Why the library refused a connection it could open (ConnectionException::$refusal): the
 * handshake told it something it cannot work with. Read without a statement, when the connection
 * opens and at reconnect().
 */
enum ConnectionRefusal: string
{
    /** pdo_mysql is not built on mysqlnd: the PHP types of fetched values would differ */
    case NotMysqlnd = 'not_mysqlnd';

    /** The server is no MariaDB - a MySQL server, or another one */
    case NotMariaDb = 'not_mariadb';

    /** The server is a MariaDB older than 10.11 */
    case MariaDbTooOld = 'mariadb_too_old';

    /** The connection's NULL mode (PDO::ATTR_ORACLE_NULLS) is not PDO::NULL_NATURAL */
    case NullMode = 'null_mode';
}
