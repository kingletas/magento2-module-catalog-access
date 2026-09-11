# Changelog

## Unreleased

Tooling only. Release notes join each changelog paragraph onto one line,
because a release page turns every newline into a line break. Nothing about
how the module behaves changed.

## 2.0.0

The vendor is now Kingletas: the package is `kingletas/module-catalog-access`, the namespace
`Kingletas\CatalogAccess` and the module `Kingletas_CatalogAccess`, and every config
section, table, console command and queue name starts with `kingletas`
instead of `commerce`. Nothing about how the module behaves changed; an
existing install moves its `commerce_` config rows and tables to `kingletas_`.

Tooling only. `make test` and `make cs` read Magento and the tools from this
package's own `vendor/`, which `make install` fills, and stop with instructions
when it is missing rather than running whatever `phpcs` or `phpunit` is on the
PATH. Nothing about how the module behaves changed.

## 1.0.3

Tooling only. Every workflow action is pinned to a commit rather than a tag, and
static analysis moved to PHPStan 2. Nothing about how the module behaves changed.

## 1.0.2

Mess detection runs through the module's own composer script, so `composer md`
and the CI gate ask for exactly the same thing.

## Earlier

This module is developed alongside fourteen others and published here from that
tree. The releases before 1.0.2 are in the tags, and the reasoning behind
each one is in the commits.
