# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 3.2.x   | yes       |
| < 3.2   | no        |

Fixes go into the latest release line (`main`); older lines get no backports or advisories.

## Reporting a vulnerability

Report privately through GitHub's private vulnerability reporting:
https://github.com/SoDaHo/pdo-wrapper/security/advisories/new

Do not open a public issue for a security problem. Include the affected version, the MariaDB version and, if you
have one, a minimal reproduction.

## Scope

Not a vulnerability of the library:

- Untrusted input in SQL that is passed through as written: `Database::raw()`, `query()`, `execute()`, `getPdo()`,
  the condition of `whereRaw()` and `insertWhen()` (only their bindings are bound).
- Bound values in logs: the `query.before`, `query` and `error` payloads, `getDebugMessage()`, the previous
  exception and `(string) $e` carry them as passed. `redactParameters` keeps them out of the payloads, the debug
  messages and the previous exceptions; value parameters are `#[\SensitiveParameter]` in traces (README, Security).
- PDO `options` that replace the defaults (emulated prepares, a runtime `SET NAMES`). `ATTR_MULTI_STATEMENTS`
  other than `false` or `0` and `ATTR_STATEMENT_CLASS` are refused.
- Transport: the library sets no TLS option and checks none; whether the connection is encrypted is up to pdo_mysql,
  MariaDB and the `options` the application passes (`Pdo\Mysql::ATTR_SSL_CA`, `ATTR_SSL_VERIFY_SERVER_CERT`).
- Charsets: name quoting assumes an ASCII-safe charset (`utf8mb4`, the default). `big5`, `cp932`, `gbk`, `gb18030` and
  `sjis` are outside the support - the library does not refuse them.
- Names from request input: quoting keeps a name from becoming SQL; which column a request may read, filter or sort
  by needs a whitelist in the application.
