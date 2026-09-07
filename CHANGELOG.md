# Changelog

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
