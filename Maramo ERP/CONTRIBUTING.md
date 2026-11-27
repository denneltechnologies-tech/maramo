# Contributing to Classivo ERP

Thank you for helping. Bug reports, fixes, translations and new modules are all welcome.

## Developer Certificate of Origin (required)

We use the [Developer Certificate of Origin 1.1](https://developercertificate.org/) instead of a CLA. By adding a `Signed-off-by` line to a commit you certify that you wrote the change, or otherwise have the right to submit it under the project's MIT licence.

Every commit in a pull request must be signed off:

```bash
git commit -s -m "students: fix roll number for transferred students"
```

This adds a line like:

```
Signed-off-by: Your Name <you@example.com>
```

Use your real name and an email you can be reached at. Pull requests with unsigned commits cannot be merged. To sign off commits you already made: `git rebase --signoff main`.

## How to work

1. Fork the repository and create a branch from `main`.
2. Keep changes small and focused on one module (`packages/<name>`) where possible.
3. Add or update tests. Run the suite of every package you touched:
   ```bash
   cd packages/<name> && composer install && vendor/bin/phpunit
   cd app && composer install && php artisan test
   ```
4. Follow the existing code style (`vendor/bin/pint` in `app/`).
5. Add English **and** Hindi strings for any new UI text (`lang/en`, `lang/hi`). If you cannot write Hindi, add the English text in both files and say so in the pull request.
6. Open a pull request describing what changed and why.

## Module rules

- Package names are `classivo/<name>`, namespaces `Classivo\<Name>\`.
- Tenant data uses the core traits `BelongsToTenant`, `BelongsToBranch`, `BelongsToSession`.
- Modules communicate through Laravel events, not direct calls into other modules.
- No real personal data, school names, keys or server details in code, tests, seeders or fixtures.

## Reporting security issues

Do not open a public issue. See [SECURITY.md](SECURITY.md).
