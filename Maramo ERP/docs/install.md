# Install

Status: parts of this page are TODO until the demo app and Docker files land in the repository.

## Option 1: Docker compose

TODO: confirm the file name and service names once `docker-compose.yml` exists in the repository root.

```bash
git clone https://github.com/classivoschoolerp/classivo-erp.git
cd classivo-erp
cp app/.env.example app/.env
docker compose up -d
```

## Option 2: Composer packages in your own Laravel app

Requirements: PHP 8.2 or newer (CI runs 8.3 and 8.4), Laravel 12, Filament 4.

```bash
composer require classivo/core
```

TODO: add the other packages (`classivo/students`, `classivo/exams`, ...) once they are published on Packagist. Until then, use a path repository pointing at `packages/<name>`.

`classivo/core` registers its service provider through Laravel package discovery. Publish or set the configuration in `config/classivo.php`:

| Setting | Env variable | Default |
|---|---|---|
| School id (single-school install) | `CLASSIVO_SCHOOL_ID` | `1` |
| User model | `CLASSIVO_USER_MODEL` | `App\Models\User` |
| Regional preset | `CLASSIVO_PRESET` | `india` (other built-in: `generic`) |

Then run `php artisan migrate`. Each package loads its own migrations.

Each package that has screens ships a Filament plugin named `Classivo\<Name>\<Name>Plugin`. Register the plugins you want on your Filament panel. TODO: add a verified example once a package with a plugin is present.
