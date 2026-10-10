# Security Policy

## Supported versions

Fixes go into the latest release line only (`main`); older lines get no backports or advisories.

| Version | Supported |
|---------|-----------|
| 3.2.x   | yes       |
| < 3.2   | no        |

## Reporting a vulnerability

Report vulnerabilities privately through GitHub's private vulnerability reporting:
https://github.com/SoDaHo/pdo-wrapper/security/advisories/new

Please do not open a public issue for a security problem. Include the affected version, the MariaDB
version (MariaDB is the only database since 3.0) and, if you have one, a minimal reproduction.

## Scope

The library binds every value as a prepared-statement parameter and quotes every identifier it is given.
`Database::raw()`, `query()`, `execute()`, `getPdo()`, and the condition SQL of `whereRaw()` and
`insertWhen()` (only their bindings are bound) pass SQL through unchanged by design and are documented
as unsafe for untrusted input; a report that needs untrusted input in one of those calls is a usage
error in the application, not a vulnerability in the library.

Likewise outside the library's reach, and documented in the README:

- **Parameters in logs.** The `query.before`, `query` and `error` hooks, `QueryException::getDebugMessage()`,
  the previous exception (a duplicate key's message quotes the value), `(string) $e` and the arguments in a
  trace (while `zend.exception_ignore_args` is off) carry the SQL and the bound parameters as passed, secrets
  included - the README's "Parameters are secrets" lists every channel. An application that writes them to a
  log or an error page unredacted leaks them itself. Since 3.2 the option `redactParameters` keeps the values
  out of the hook payloads, the debug messages and the previous exceptions, and the library's value
  parameters are `#[\SensitiveParameter]` in every trace.
- **PDO options.** `options` replace the secure defaults (native prepared statements, exceptions).
  Switching to emulated prepares or a runtime `SET NAMES` is the application's decision and risk.
  Multi-statements cannot be switched on (since 3.2): `ATTR_MULTI_STATEMENTS` is refused.
- **Transport and charset.** The library sets no TLS option: a connection across a network the
  application does not control needs `Pdo\Mysql::ATTR_SSL_CA` and `ATTR_SSL_VERIFY_SERVER_CERT` among the
  `options`. The quoting of names assumes an ASCII-safe charset (`utf8mb4`, the default); `big5`, `cp932`,
  `gbk`, `gb18030` and `sjis` are not supported.
- **Identifiers from request input.** Quoting keeps a column or table name from becoming SQL; it does
  not decide which column a request may read, filter or sort by. That needs a whitelist in the
  application.
