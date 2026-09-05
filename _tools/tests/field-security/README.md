# Field security regression checks

The fixture uses an in-memory SQLite database and a mock mailer. It never boots a real Joomla application or reads its configuration, and sends no email. Playwright starts a temporary PHP server bound to loopback and exercises the actual contact handler and templates. Temporary files are removed afterwards.

Requirements: PHP with PDO SQLite and fileinfo, Node.js, Playwright/Chromium, and a Joomla installation providing its Composer dependencies.

Set JOOMLA_ROOT to the installation directory and run:

```sh
node _tools/tests/field-security/browser.cjs
```

Optional environment settings: PHP_BINARY, PHP_SQLITE_EXTENSION (for example pdo_sqlite when installed but disabled), PLAYWRIGHT_MODULE (absolute module location), CHROME_BINARY.

Coverage includes array/scalar compatibility, object and enum rejection before autoloading, recursion limits, resource-scoped coupons, executable configuration permissions, item/field/type/category ACLs, publication windows, recipient and metadata tampering, CAPTCHA/CSRF, consent, sender copies, real multipart attachment validation, mail quotas, and rendered link/embed injection.

A production installation still needs its own page and workflow regression checks, especially any custom template overrides.
