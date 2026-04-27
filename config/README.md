# Install Configuration

This directory is the install-level configuration overlay for Dataphyre modules.
Runtime modules read files such as `config/stripe.php` or `config/access.php`
when an application boots.

For the public repository, direct `config/*.php` files are treated as local
install state. Keep reusable examples as `config/*.example.php`, then copy the
needed example to the matching local filename in an install.

Included templates:

- [access.example.php](access.example.php)
- [stripe.example.php](stripe.example.php)
- [supercookie.example.php](supercookie.example.php)
- [tracelog.example.php](tracelog.example.php)

Public language data for date translation remains under
`config/date_translation/languages/`.
