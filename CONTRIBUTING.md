# Contributing

Thanks for helping improve MenuNest.

## Before you start

- For bugs, open an issue with your WordPress and PHP versions, your theme, and the steps to reproduce.
- For new features, open an issue first so we can agree on the approach.

## Making a change

1. Fork the repository and create a branch.
2. Run `composer install`.
3. Make your change, with a test in `tests/` when it changes behaviour.
4. Run `composer check` (coding standards, PHPStan level 8, PHPUnit). All three must pass.
5. Open a pull request describing what changed and why.

## Code style

- WordPress Coding Standards (enforced by `composer lint`).
- `declare( strict_types=1 )` and native types in every PHP file; PHP 7.4 compatible.
- Every user-facing string is translatable with the text domain `menunest-smart-menu-manager`.
- Every action checks the user's permission and a security token (nonce).
- Use the native WordPress admin interface; no extra frameworks.
