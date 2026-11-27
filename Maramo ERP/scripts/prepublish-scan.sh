#!/usr/bin/env bash
# Pre-publish blocking scan: files AND git commit metadata. Exit 1 on any hit.
# Usage: scripts/prepublish-scan.sh [repo-dir]   (default: current dir)
# Maintainers can add extra blocked terms with SCAN_TERMS_FILE=/path/to/terms.sh (sets BAD, NAME_CS, AI, STRICT_AUTHOR).
set -u
cd "${1:-.}" || { echo "cannot cd"; exit 2; }
FAIL=0
NEVER='a^'   # matches nothing
BAD=$NEVER; NAME_CS=$NEVER; AI=$NEVER; STRICT_AUTHOR=''
[ -n "${SCAN_TERMS_FILE:-}" ] && . "$SCAN_TERMS_FILE"
OKMAIL='@(classivoerp\.com|example\.(com|org|net)|[A-Za-z0-9.-]+\.(test|local|example|invalid))$'
KEYS='rzp_(test|live)_X*[A-WYZa-wyz0-9]|sk_(test|live)_X*[A-WYZa-wyz0-9]|AKIA[0-9A-Z]{8}|AIza[0-9A-Za-z_-]{10}'
hit() { echo "FAIL: $1"; FAIL=1; }
INTERNAL='(^|/)(REPORT|INTEGRATION|STATE|HANDOFF)\.md$|(^|/)PHASE1-REPORT[^/]*$'

# File list: git-tracked files inside a git repo, else everything except vendor/node_modules.
LIST=$(mktemp); trap 'rm -f "$LIST"' EXIT
if git rev-parse --git-dir >/dev/null 2>&1; then
  git ls-files -z > "$LIST"
  IN_GIT=1
else
  find . \( -name vendor -o -name node_modules -o -name .git \) -prune -o -type f -print0 > "$LIST"
  IN_GIT=0
fi
# Lock files and the scanner itself are not scanned for content.
FILTERED=$(mktemp); trap 'rm -f "$LIST" "$FILTERED"' EXIT
tr '\0' '\n' < "$LIST" | grep -vE '(^|/)(composer\.lock|package-lock\.json|prepublish-scan\.sh)$' | grep -vE '(^|/)(vendor|node_modules)/' | tr '\n' '\0' > "$FILTERED"
scan() { xargs -0 grep -nEI "$@" < "$FILTERED" 2>/dev/null; }

# 0. vendor/ or lock files must not be tracked
if [ $IN_GIT -eq 1 ]; then
  out=$(git ls-files | grep -E '(^|/)(vendor|node_modules)/|(^|/)(composer\.lock|package-lock\.json)$' | grep -vxE 'app/composer\.lock')
  [ -n "$out" ] && { hit "vendor/ or lock files are tracked"; echo "$out" | head; }
fi

# 0b. extra blocked terms + internal working notes anywhere in tree
out=$(scan -i "$AI") && { hit "blocked terms (list B) in files"; echo "$out" | head -40; }
out=$( { tr '\0' '\n' < "$LIST"; [ $IN_GIT -eq 1 ] && git ls-files -o --exclude-standard; } | grep -vE '(^|/)(vendor|node_modules)/' | grep -E "$INTERNAL")
out2=$(tr '\0' '\n' < "$LIST" | grep -iE "$AI")
[ -n "$out2" ] && { hit "blocked terms (list B) in file names"; echo "$out2"; }
[ -n "$out" ] && { hit "internal working-note files present (tracked or unignored)"; echo "$out"; }

# 1. forbidden words / IPs in files
out=$(scan -i "$BAD") && { hit "forbidden terms in files"; echo "$out" | head -40; }
out=$(scan "$NAME_CS") && { hit "blocked name in files"; echo "$out" | head; }

# 2. gateway keys / secrets
out=$(scan "$KEYS") && { hit "secret-like key prefixes in files"; echo "$out" | head -40; }

# 3. private keys
out=$(scan -e '-----BEGIN [A-Z ]*PRIVATE KEY-----') && { hit "private key material"; echo "$out" | head; }

# 4. emails outside the allow-list
out=$(scan -o -h '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' | sed 's/^[0-9]*://' | grep -viE "$OKMAIL" | sort -u)
[ -n "$out" ] && { hit "disallowed email addresses in files"; echo "$out" | head -40; }

# 5. .env / key files (tracked or listed)
out=$(tr '\0' '\n' < "$LIST" | grep -vE '(^|/)(vendor|node_modules)/' | grep -E '(^|/)(\.env|\.env\..*|[^/]*\.env)$' | grep -vE '(^|/)\.env\.example$')
[ -n "$out" ] && { hit ".env files present"; echo "$out"; }
out=$(tr '\0' '\n' < "$LIST" | grep -vE '(^|/)(vendor|node_modules)/' | grep -E '(\.pem|\.p8|\.keystore|\.jks)$|(^|/)id_rsa')
[ -n "$out" ] && { hit "key/cert files present"; echo "$out"; }

# 6. git metadata. Release mode (STRICT_AUTHOR set) checks local branches + tags and every author email;
#    contributor mode (CI) checks the current branch only and allows any author/sign-off email.
if git rev-parse --git-dir >/dev/null 2>&1 && git rev-parse HEAD >/dev/null 2>&1; then
  REFS=HEAD; [ -n "$STRICT_AUTHOR" ] && REFS="--branches --tags"
  meta=$(git log $REFS --format='A:%an <%ae>%nC:%cn <%ce>%nM:%B%nT:%D' 2>/dev/null)
  out=$(echo "$meta" | grep -iE "$BAD|$KEYS") && { hit "forbidden terms in commit metadata"; echo "$out" | head -20; }
  out=$(echo "$meta" | grep -iE "$AI") && { hit "blocked terms (list B) in commit metadata"; echo "$out" | head -20; }
  if [ -n "$STRICT_AUTHOR" ]; then
  out=$(echo "$meta" | grep -E '^[AC]:' | grep -oE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' | grep -viE "$OKMAIL" | sort -u)
  [ -n "$out" ] && { hit "disallowed emails in author/committer"; echo "$out"; }
  out=$(echo "$meta" | grep -E '^M:' | grep -oE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' | grep -viE "$OKMAIL" | sort -u)
  [ -n "$out" ] && { hit "disallowed emails in commit messages"; echo "$out"; }
  fi
  out=$(echo "$meta" | grep -E "$NAME_CS") && { hit "blocked name in commit metadata"; echo "$out" | head; }
  if [ -n "$STRICT_AUTHOR" ]; then
    out=$(echo "$meta" | grep -E '^[AC]:' | grep -vE "^[AC]:$STRICT_AUTHOR\$" | sort -u)
    [ -n "$out" ] && { hit "author/committer is not the release identity"; echo "$out"; }
  fi
  # history: forbidden content in any past revision (keys in old commits)
  out=$(git grep -niEI "$BAD|$KEYS|$AI" $(git rev-list $REFS) -- . ':!vendor' ':!node_modules' ':!scripts/prepublish-scan.sh' 2>/dev/null | head -20)
  [ -n "$out" ] && { hit "forbidden content in git history"; echo "$out"; }
fi

[ $FAIL -eq 0 ] && echo "prepublish-scan: OK" || echo "prepublish-scan: FAILED"
exit $FAIL
