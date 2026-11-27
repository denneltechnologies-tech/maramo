#!/usr/bin/env bash
# Installs the demo app with ONLY the base packages and runs migrate:fresh --seed
# and the app test suite against it, to prove the app works with any subset of
# packages. Works on a throw-away copy in .base-set/ (git-ignored); app/ is untouched.
#
# Usage: scripts/test-base-set.sh [package ...]   (default: the base set below)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$ROOT/.base-set"
BASE=("$@")
[ ${#BASE[@]} -eq 0 ] && BASE=(core students exams report-cards grading timetable pdf demo-guard)

rm -rf "$WORK"
mkdir -p "$WORK"
ln -s ../packages "$WORK/packages"
rsync -a --exclude vendor --exclude node_modules --exclude .env --exclude 'database/*.sqlite' \
  --exclude 'storage/logs/*' --exclude 'storage/framework/*/*' "$ROOT/app/" "$WORK/app/"

cd "$WORK/app"

# Keep only the chosen classivo/* packages in require (path repositories may stay).
KEEP="$(printf '%s\n' "${BASE[@]}")" php -r '
  $keep = array_map(fn ($p) => "classivo/".$p, array_filter(explode("\n", getenv("KEEP"))));
  $json = json_decode(file_get_contents("composer.json"), true);
  foreach (array_keys($json["require"]) as $name) {
      if (str_starts_with($name, "classivo/") && ! in_array($name, $keep, true)) {
          unset($json["require"][$name]);
      }
  }
  file_put_contents("composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
'

echo "== packages: ${BASE[*]}"
composer update --no-interaction --no-progress --quiet
composer show 'classivo/*' --name-only

cp .env.example .env
php artisan key:generate --force --quiet
: > database/base-set.sqlite
DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/base-set.sqlite" php artisan migrate:fresh --seed --force --no-interaction
php artisan test --do-not-cache-result
