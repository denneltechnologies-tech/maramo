# Architecture

## Packages

Every package is a Composer library named `classivo/<name>` with the PHP namespace `Classivo\<Name>\`, PSR-4 sources in `src/`, tests in `tests/`, its own migrations and English/Hindi translations. Packages that have screens add a Filament plugin and a Laravel service provider.

Packages talk to each other through Laravel events (for example a fee payment raises an event) rather than calling closed modules directly. This is how private add-ons can hook in without changes to the open code.

## Tenancy

Every record belongs to a school (`school_id`) and, where it matters, a branch and an academic session. The classes `BelongsToTenant`, `BelongsToBranch` and `BelongsToSession` in `Classivo\Core\Concerns` add this to a model. TODO: verify the trait namespace and global scope behaviour against core's final code.

`Classivo\Core\Tenancy\CurrentContext` is a request-scoped singleton holding the current school, branch and session. Helpers `current_school_id()`, `current_branch_id()` and `current_session_id()` read from it.

- If no branch is selected, the school's default (or first active) branch is used.
- If no session is selected, the current session is used.
- In console commands (seeders, queues) the context is not resolved from a request, so reads are not filtered, but new rows are still filled with school, branch and session.

### Single school (default)

`Classivo\Core\Tenancy\SingleSchoolResolver` implements `TenantResolver` and returns `config('classivo.school_id')`, which defaults to `1`. A fresh install runs as one school with no extra setup.

### Plugging in multi-school

`TenantResolver` has one method:

```php
interface TenantResolver
{
    public function schoolId(): ?int;
}
```

Write your own resolver (from the logged-in user, a sub-domain, a header) and bind it in a service provider:

```php
$this->app->bind(\Classivo\Core\Tenancy\TenantResolver::class, MyResolver::class);
```

Returning `null` means "no school found"; tenant-scoped queries then return nothing rather than another school's rows. For tests and scripts you can pin a school with `CurrentContext::setSchoolId()`.

The open kit does not include a hosted multi-school layer (sign-up, tenants, billing); the resolver is the extension point for building your own.

## Regional presets

`config/classivo.php` defines presets (`india`, `generic`) that supply defaults for board, currency, timezone, date format, session start month, languages and weekend days. Add your own entry under `presets` and set `CLASSIVO_PRESET`.
