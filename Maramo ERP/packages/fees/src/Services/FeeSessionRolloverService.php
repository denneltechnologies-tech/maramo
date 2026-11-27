<?php

namespace Classivo\Fees\Services;

use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Models\FeeGroup;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Models\FeeSessionRollover;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * FeeSessionRolloverService — copies last year's FEE STRUCTURE into a new academic
 * session: fee groups and fee masters, re-priced by a percentage increment with a
 * school-friendly rounding rule, with every date moved by the session delta.
 *
 * There was no way to do this at all. A school opening 2027-2028 had to retype every
 * priced line by hand, and {@see StudentPromotionService} refuses to promote into a
 * session whose structure has not been rolled over — so the missing rollover was
 * blocking the promotion flow as well as the fees one.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT COPIES, AND WHAT IT REFUSES TO TOUCH
 * ─────────────────────────────────────────────────────────────────────────────
 * IN  — `fee_groups` (find-or-create by name in the target session) and
 *       `fee_masters` (one clone per source master, re-priced and re-dated).
 * OUT — `fee_student_allocations`. A structure rollover prices NEXT year; it does
 *       not decide who owes it. Allocation stays where it belongs: Assign Fees for
 *       the deliberate act, {@see FeeCarryForwardService} for opening balances. A
 *       clone that also allocated would bill an entire school off one button.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NOT ONE COLUMN OF `fee_masters` IS NAMED IN THIS FILE
 * ─────────────────────────────────────────────────────────────────────────────
 * `fee_masters` is actively growing — section and student-category pricing,
 * billing frequency, installment schedules, effective-from/to windows, a fine-rule
 * reference. A clone written against a fixed field list would keep passing its
 * tests while SILENTLY DROPPING every new column, and a school would lose next
 * year's per-section prices without a single error. So every decision here is
 * derived from the LIVE SCHEMA at runtime:
 *
 *   • THE COPY is {@see Model::replicate()}, which
 *     carries every attribute the row has. New columns ride along by construction.
 *   • THE DATES are every column {@see Schema::getColumns()} reports as a `date`
 *     (minus the timestamps), all shifted by the same delta — so `due_date`,
 *     `first_due_date`, `effective_from`, `effective_to` and anything added later
 *     move together. A clone that kept last year's effective window would be a
 *     price that never applies; one that kept last year's due date would be
 *     overdue, and accruing fines, on the day the session opens.
 *   • THE SESSION-LOCAL FOREIGN KEYS are found by reading
 *     {@see Schema::getForeignKeys()} and keeping the ones whose target table has a
 *     `session_id` of its own — today `class_id` → classes, `section_id` → sections,
 *     `fee_group_id` → fee_groups and `parent_fee_master_id` → fee_masters itself.
 *     Each is REMAPPED into the target session; a new one added tomorrow is picked
 *     up without touching this file.
 *   • THE DUPLICATE TEST treats every column that is not in
 *     {@see self::NON_IDENTITY_COLUMNS} as part of a head's identity, so a new
 *     pricing DIMENSION starts distinguishing heads the moment it exists: two
 *     masters differing only by `section_id` are two heads, not one head cloned
 *     twice.
 *
 * That inverts the usual default — unknown columns are significant rather than
 * ignored — which is the safe direction here. Over-splitting means a head gets
 * cloned when it might have been skipped: visible in the preview, fixable by
 * unticking a row. Under-splitting means a school loses pricing lines it will not
 * notice until invoices go out.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY SESSION-LOCAL IDS MUST BE REMAPPED, NEVER CARRIED
 * ─────────────────────────────────────────────────────────────────────────────
 * `classes`, `sections` and `fee_groups` are all session-scoped (classes are unique
 * on branch + session + name), so a source master's `class_id` names a row belonging
 * to LAST year. Carried across verbatim it produces a master that
 * {@see FeeAllocationService::mastersForClass()} can never match — a priced line
 * that silently bills nobody. Remapping is by branch + name, the natural key those
 * tables are actually unique on. A source class or section with no counterpart in
 * the target session BLOCKS its masters, reported per row with the reason, rather
 * than being nulled — nulling `class_id` turns a Grade-5-only charge into an
 * all-classes one, which is a far worse failure than refusing.
 *
 * `parent_fee_master_id` is the self-reference an installment schedule hangs off, so
 * it is resolved in two passes: schedule parents are cloned first and their source →
 * clone ids remembered (skipped ones map to the twin already in the target session),
 * then the children are cloned pointing at the right parent. A child whose parent
 * could not be resolved is blocked, never re-parented onto last year's row.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IDEMPOTENCE
 * ─────────────────────────────────────────────────────────────────────────────
 * Every write is find-or-create against a natural key, so a double-clicked rollover
 * cannot double-price a school: groups match on name, masters on the runtime
 * identity key. A second run reports every head as "skip". {@see record()}
 * additionally logs the run in `fee_session_rollovers` so the page can tell an
 * operator this pair has already been rolled over and a second press only fills gaps.
 */
class FeeSessionRolloverService
{
    /**
     * Columns of `fee_masters` that do NOT identify a head. Everything else —
     * including any column added after this was written — does.
     *
     * `amount` and the fine rule are excluded because the rollover is allowed to
     * restate them: a re-priced clone of a head is still that head, so a second run
     * must recognise the first run's output as the same line. `fee_group_id` is
     * excluded because regrouping a head does not make it a different charge —
     * including it would let a regrouped structure be cloned a second time.
     *
     * @var array<int, string>
     */
    public const NON_IDENTITY_COLUMNS = [
        'id',
        'school_id',
        'branch_id',
        'session_id',
        'fee_group_id',
        'amount',
        'fine_type',
        'fine_amount',
        'fine_frequency',
        'fine_after_days',
        'is_optional',
        'status',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** Rounding steps a school actually asks for. */
    public const ROUNDING_STEPS = [
        '0' => 'No rounding',
        '1' => 'Nearest 1',
        '10' => 'Nearest 10',
        '100' => 'Nearest 100',
    ];

    public const ROUNDING_MODES = [
        'nearest' => 'Nearest',
        'up' => 'Always up',
        'down' => 'Always down',
    ];

    /** Schema facts, resolved once per instance. */
    private ?array $identityColumns = null;

    private ?array $dateColumns = null;

    private ?array $sessionLocalKeys = null;

    /** table => [natural key => id] for the session being remapped INTO. */
    private array $naturalKeyCache = [];

    // ------------------------------------------------------------------ Pricing

    /**
     * Apply a percentage increment and then a rounding rule.
     *
     * The quotient is rounded to 6 places before ceil/floor so a value that is
     * mathematically a whole multiple of the step — 1000 / 10, which binary floats
     * happily render as 99.99999999999999 — is not pushed a whole step by a
     * rounding error. On a ₹100 step that error is ₹100 per line, per student.
     */
    public function price(float $amount, float $percent, int $step, string $mode = 'nearest'): float
    {
        $raised = $amount * (1 + ($percent / 100));

        if ($step <= 0) {
            return round($raised, 2);
        }

        $quotient = round($raised / $step, 6);

        $steps = match ($mode) {
            'up' => ceil($quotient),
            'down' => floor($quotient),
            default => round($quotient),
        };

        return round($steps * $step, 2);
    }

    /**
     * How far to move dates, in MONTHS, derived from the two sessions' start dates
     * — 12 for a normal year-to-year rollover.
     *
     * Months rather than days on purpose: a day shift lands "10 Aug" on "9 Aug"
     * whenever a leap day falls between the two sessions, and a due date that
     * drifts by a day every four years eventually crosses a month boundary and
     * changes which installment a payment belongs to. Falls back to 12 when either
     * session has no start date — a session is a year, and leaving last year's
     * dates in place would make the whole new structure overdue on day one.
     */
    public function shiftMonths(int $fromSessionId, int $toSessionId): int
    {
        $from = AcademicSession::query()->find($fromSessionId)?->start_date;
        $to = AcademicSession::query()->find($toSessionId)?->start_date;

        if ($from === null || $to === null) {
            return 12;
        }

        return (int) round($from->copy()->startOfDay()->diffInMonths($to->copy()->startOfDay(), false));
    }

    // ------------------------------------------------------------------ Preview

    /**
     * What {@see commit()} WOULD write — same resolution, same identity test, same
     * blocking rules, so the preview cannot promise more than the commit delivers.
     *
     * Row shape: fee_master_id, group, head, class, old_amount, new_amount,
     * old_due_date, new_due_date, action (create | skip | blocked), reason.
     *
     * `$options`: percent (float), rounding (int step), rounding_mode, shift_months.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(int $fromSessionId, int $toSessionId, array $options = []): Collection
    {
        $opts = $this->options($fromSessionId, $toSessionId, $options);
        $plan = $this->plan($fromSessionId, $toSessionId, $opts);

        if ($plan->isEmpty()) {
            return collect();
        }

        $groupNames = $this->sourceGroupNames($plan->map(fn (array $e): FeeMaster => $e['source']));

        return $plan
            ->map(function (array $entry) use ($opts, $groupNames): array {
                $source = $entry['source'];

                return [
                    'fee_master_id' => (int) $source->id,
                    'group' => $groupNames[$source->fee_group_id] ?? '—',
                    'head' => $source->feeType?->name ?? '—',
                    'class' => $this->scopeLabel($source),
                    'old_amount' => number_format((float) $source->amount, 2, '.', ''),
                    'new_amount' => number_format($this->price((float) $source->amount, $opts['percent'], $opts['rounding'], $opts['rounding_mode']), 2, '.', ''),
                    'old_due_date' => $source->due_date?->toDateString() ?? '—',
                    'new_due_date' => $this->shiftDate($source->due_date?->toDateString(), $opts['shift_months']) ?? '—',
                    'action' => $entry['action'],
                    'reason' => $entry['reason'],
                ];
            })
            ->values();
    }

    /**
     * The decision for every source master — create, skip or blocked — walked
     * schedule-parents-first so a child's parent has already been judged when the
     * child is reached.
     *
     * THE PREVIEW AND THE COMMIT BOTH RUN THIS. That is the whole point: the two
     * cannot drift, because there is only one place where a decision is made. The
     * commit then merely executes the plan (and applies the operator's unticks).
     *
     * @return Collection<int, array{source: FeeMaster, action: string, reason: string|null, target_id: int|null}>
     */
    public function plan(int $fromSessionId, int $toSessionId, array $options = []): Collection
    {
        $opts = $this->options($fromSessionId, $toSessionId, $options);
        $sources = $this->sourceMasters($fromSessionId);

        if ($sources->isEmpty()) {
            return collect();
        }

        $existing = $this->existingHeadKeys($toSessionId);
        $selfColumn = $this->selfReferenceColumn();
        $resolved = [];
        $plan = [];

        foreach ($this->parentsFirst($sources) as $source) {
            $reason = $this->blockingReason($source, $toSessionId);

            // A schedule child may only be cloned once its parent has somewhere to
            // point IN THE TARGET SESSION. Left unchecked, the clone would inherit
            // last year's parent id and silently attach next year's installment to
            // last year's schedule.
            if ($reason === null && $selfColumn !== null) {
                $parentId = $source->getAttribute($selfColumn);

                if ($parentId !== null && ! array_key_exists((int) $parentId, $resolved)) {
                    $reason = 'Its installment-schedule parent could not be resolved in the target session.';
                }
            }

            if ($reason !== null) {
                $plan[] = ['source' => $source, 'action' => 'blocked', 'reason' => $reason, 'target_id' => null];

                continue;
            }

            $key = $this->headKey($source, $opts['shift_months']);

            if (isset($existing[$key])) {
                $resolved[(int) $source->id] = $existing[$key];
                $plan[] = [
                    'source' => $source,
                    'action' => 'skip',
                    'reason' => 'Already present in the target session.',
                    'target_id' => $existing[$key],
                ];

                continue;
            }

            // Not written yet, but a child asking for it later must not be blocked.
            $resolved[(int) $source->id] = null;
            $plan[] = ['source' => $source, 'action' => 'create', 'reason' => null, 'target_id' => null];
        }

        return collect($plan);
    }

    // ------------------------------------------------------------------- Commit

    /**
     * Clone the structure. Returns
     * ['groups' => int, 'created' => int, 'skipped' => int, 'blocked' => int].
     *
     * One transaction, so a blocked class or a database hiccup half-way through
     * cannot leave a school with three quarters of next year's price list. Every
     * master is written through {@see FeeMaster}'s own `save()`, so its lifecycle
     * hooks, the tenancy traits and the activity log run exactly as they do for a
     * hand-typed line — a cloned master is not a privileged row.
     *
     * `$only` optionally limits the run to a set of source master ids (the preview's
     * rows minus whatever the operator unticked). Schedule PARENTS outside that set
     * are still resolved, never re-created — a child kept without its parent maps
     * onto the parent already in the target session, or is blocked.
     *
     * @param  array<int, int>|null  $only
     * @return array{groups: int, created: int, skipped: int, blocked: int}
     */
    public function commit(int $fromSessionId, int $toSessionId, array $options = [], ?array $only = null): array
    {
        $opts = $this->options($fromSessionId, $toSessionId, $options);

        return DB::transaction(function () use ($fromSessionId, $toSessionId, $opts, $only): array {
            $selected = $only === null ? null : array_flip(array_map('intval', $only));
            $selfColumn = $this->selfReferenceColumn();

            $groupMap = [];
            $masterMap = [];
            $groups = $created = $skipped = $blocked = 0;

            foreach ($this->plan($fromSessionId, $toSessionId, $opts) as $entry) {
                $source = $entry['source'];

                if ($entry['action'] === 'blocked') {
                    $blocked++;

                    continue;
                }

                // Already in the target session: remember which row it is, so a
                // child pointing at this parent still resolves, then move on.
                if ($entry['action'] === 'skip') {
                    $masterMap[(int) $source->id] = $entry['target_id'];
                    $skipped++;

                    continue;
                }

                // Unticked in the preview — nothing is written for it, and it is
                // counted as neither created nor skipped.
                if ($selected !== null && ! isset($selected[(int) $source->id])) {
                    continue;
                }

                // Its parent WAS in the plan but the operator unticked it, so there
                // is nothing for this child to hang off. Blocking beats attaching
                // next year's installment to last year's schedule.
                if ($selfColumn !== null
                    && $source->getAttribute($selfColumn) !== null
                    && ! isset($masterMap[(int) $source->getAttribute($selfColumn)])) {
                    $blocked++;

                    continue;
                }

                if (! isset($groupMap[$source->fee_group_id])) {
                    [$groupId, $wasCreated] = $this->mapGroup((int) $source->fee_group_id, $toSessionId);
                    $groupMap[$source->fee_group_id] = $groupId;
                    $groups += $wasCreated ? 1 : 0;
                }

                // replicate() carries EVERY column the row has, including any the
                // schema grows after this was written. Only what a rollover must
                // restate is overridden below.
                $clone = $source->replicate();
                $clone->session_id = $toSessionId;
                $clone->fee_group_id = $groupMap[$source->fee_group_id];
                $clone->amount = $this->price((float) $source->amount, $opts['percent'], $opts['rounding'], $opts['rounding_mode']);

                foreach ($this->dateColumns() as $column) {
                    $clone->{$column} = $this->shiftDate(
                        $this->dateString($source->getAttribute($column)),
                        $opts['shift_months'],
                    );
                }

                foreach ($this->sessionLocalKeys() as $column => $table) {
                    if ($column === 'fee_group_id') {
                        continue;
                    }

                    $clone->{$column} = $table === 'fee_masters'
                        ? ($masterMap[(int) $source->getAttribute($column)] ?? null)
                        : $this->targetIdFor($table, $source->getAttribute($column), $toSessionId);
                }

                // replicate() does not clear the soft-delete stamp. Sources are read
                // un-trashed so it is already null, but a clone born with one would
                // be invisible — cheap to be explicit about.
                $clone->deleted_at = null;
                $clone->save();

                $masterMap[(int) $source->id] = (int) $clone->id;
                $created++;
            }

            return ['groups' => $groups, 'created' => $created, 'skipped' => $skipped, 'blocked' => $blocked];
        });
    }

    /**
     * Record a committed rollover in `fee_session_rollovers`. Kept separate from
     * {@see commit()} so the register is written once per OPERATOR ACTION even when
     * the action rolled structure and balances together.
     *
     * @param  array<string, mixed>  $summary
     */
    public function record(int $fromSessionId, int $toSessionId, array $options, array $summary): FeeSessionRollover
    {
        return FeeSessionRollover::create([
            'from_session_id' => $fromSessionId,
            'to_session_id' => $toSessionId,
            'percent' => (float) ($options['percent'] ?? 0),
            'rounding' => max(0, (int) ($options['rounding'] ?? 0)),
            'rounding_mode' => (string) ($options['rounding_mode'] ?? 'nearest'),
            'shift_months' => (int) ($options['shift_months'] ?? 0),
            'groups_created' => (int) ($summary['groups'] ?? 0),
            'masters_created' => (int) ($summary['created'] ?? 0),
            'masters_skipped' => (int) ($summary['skipped'] ?? 0),
            'masters_blocked' => (int) ($summary['blocked'] ?? 0),
            'students_carried' => (int) ($summary['students'] ?? 0),
            'amount_carried' => (float) ($summary['amount'] ?? 0),
            'committed_by' => auth()->id(),
        ]);
    }

    /** Earlier committed runs for this pair — what the page warns an operator with. */
    public function previousRuns(int $fromSessionId, int $toSessionId): Collection
    {
        return FeeSessionRollover::query()
            ->where('from_session_id', $fromSessionId)
            ->where('to_session_id', $toSessionId)
            ->latest('id')
            ->get();
    }

    // ------------------------------------------------------------------ Sources

    /**
     * The source session's live fee masters.
     *
     * @return Collection<int, FeeMaster>
     */
    public function sourceMasters(int $fromSessionId): Collection
    {
        return FeeMaster::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('session_id', $fromSessionId)
            ->with('feeType')
            ->orderBy('fee_group_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The same masters, schedule parents first. Self-referencing rows must be
     * cloned after the row they point at.
     *
     * @param  Collection<int, FeeMaster>  $masters
     * @return Collection<int, FeeMaster>
     */
    private function parentsFirst(Collection $masters): Collection
    {
        $selfColumn = $this->selfReferenceColumn();

        if ($selfColumn === null) {
            return $masters;
        }

        return $masters
            ->sortBy(fn (FeeMaster $m): int => $m->getAttribute($selfColumn) === null ? 0 : 1)
            ->values();
    }

    /**
     * The column by which `fee_masters` points at itself (today
     * `parent_fee_master_id`), or null if the schema has no such key. Discovered,
     * not named, so a rename does not quietly disable the two-pass ordering.
     */
    private function selfReferenceColumn(): ?string
    {
        $column = array_search('fee_masters', $this->sessionLocalKeys(), true);

        return $column === false ? null : $column;
    }

    // ------------------------------------------------------------- Schema facts

    /**
     * Every `fee_masters` column that identifies a head, read from the live schema.
     *
     * @return array<int, string>
     */
    public function identityColumns(): array
    {
        if ($this->identityColumns === null) {
            $this->identityColumns = array_values(array_diff(
                Schema::getColumnListing('fee_masters'),
                self::NON_IDENTITY_COLUMNS,
            ));
            sort($this->identityColumns);
        }

        return $this->identityColumns;
    }

    /**
     * Every real `date` column of `fee_masters` — the ones a rollover shifts. The
     * created/updated/deleted timestamps are a different type and never appear here.
     *
     * @return array<int, string>
     */
    public function dateColumns(): array
    {
        if ($this->dateColumns === null) {
            $this->dateColumns = collect(Schema::getColumns('fee_masters'))
                ->filter(fn (array $c): bool => ($c['type_name'] ?? null) === 'date')
                ->pluck('name')
                ->values()
                ->all();
        }

        return $this->dateColumns;
    }

    /**
     * Foreign keys of `fee_masters` that point at a SESSION-SCOPED table, as
     * column => table. These are the ids that mean something different in every
     * session and so must be remapped rather than carried.
     *
     * @return array<string, string>
     */
    public function sessionLocalKeys(): array
    {
        if ($this->sessionLocalKeys !== null) {
            return $this->sessionLocalKeys;
        }

        $keys = [];

        foreach (Schema::getForeignKeys('fee_masters') as $fk) {
            $column = $fk['columns'][0] ?? null;
            $table = $fk['foreign_table'] ?? null;

            // `session_id` is the pin itself, not something pinned by it.
            if ($column === null || $table === null || $column === 'session_id') {
                continue;
            }

            if (Schema::hasColumn($table, 'session_id')) {
                $keys[$column] = $table;
            }
        }

        return $this->sessionLocalKeys = $keys;
    }

    // -------------------------------------------------------------- Remapping

    /**
     * Why this master cannot be cloned, or null when it can: every session-local id
     * it holds must have a counterpart in the target session.
     *
     * `fee_group_id` is exempt — groups are CREATED when missing, being the one
     * session-local thing a fees rollover owns. `fee_masters` self-references are
     * checked by the caller against what the run has actually mapped.
     */
    public function blockingReason(FeeMaster $master, int $toSessionId): ?string
    {
        foreach ($this->sessionLocalKeys() as $column => $table) {
            if ($column === 'fee_group_id' || $table === 'fee_masters') {
                continue;
            }

            $sourceId = $master->getAttribute($column);

            if ($sourceId === null) {
                continue;
            }

            if ($this->targetIdFor($table, $sourceId, $toSessionId) === null) {
                $name = $this->sourceNaturalName($table, (int) $sourceId) ?? $sourceId;
                $label = ucfirst(str_replace('_', ' ', Str::singular($table)));

                return "{$label} \"{$name}\" does not exist in the target session — roll the class structure over first.";
            }
        }

        return null;
    }

    /**
     * The target-session id equivalent to a source-session row, matched on the
     * natural key those tables are unique on: branch + name.
     *
     * Returns null when the table has no `name` to match on — a session-local table
     * this service cannot reason about is REFUSED rather than guessed at, which
     * turns the unknown into a blocked row an operator can see instead of a stale
     * id nobody notices.
     */
    public function targetIdFor(string $table, mixed $sourceId, int $toSessionId): ?int
    {
        if ($sourceId === null) {
            return null;
        }

        $key = $this->sourceNaturalKey($table, (int) $sourceId);

        return $key === null ? null : ($this->targetKeyIndex($table, $toSessionId)[$key] ?? null);
    }

    /** branch|lower(name) of a row, or null when the table cannot be matched. */
    private function sourceNaturalKey(string $table, int $id): ?string
    {
        if (! Schema::hasColumn($table, 'name')) {
            return null;
        }

        $row = DB::table($table)->where('id', $id)->first(['branch_id', 'name']);

        return $row === null ? null : $this->naturalKey($row->branch_id, $row->name);
    }

    /** The display name of a source row, for a blocking message. */
    private function sourceNaturalName(string $table, int $id): ?string
    {
        if (! Schema::hasColumn($table, 'name')) {
            return null;
        }

        return DB::table($table)->where('id', $id)->value('name');
    }

    /**
     * natural key => id for every live row of a session-scoped table in one session,
     * built once per (table, session).
     *
     * @return array<string, int>
     */
    private function targetKeyIndex(string $table, int $sessionId): array
    {
        $cacheKey = $table.':'.$sessionId;

        if (isset($this->naturalKeyCache[$cacheKey])) {
            return $this->naturalKeyCache[$cacheKey];
        }

        $index = [];

        DB::table($table)
            ->where('session_id', $sessionId)
            ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->get(['id', 'branch_id', 'name'])
            ->each(function (object $row) use (&$index): void {
                $index[$this->naturalKey($row->branch_id, $row->name)] = (int) $row->id;
            });

        return $this->naturalKeyCache[$cacheKey] = $index;
    }

    private function naturalKey(mixed $branchId, mixed $name): string
    {
        return $branchId.'|'.mb_strtolower(trim((string) $name));
    }

    /**
     * Find-or-create the target-session twin of a source fee group, by name.
     *
     * @return array{0: int, 1: bool}
     */
    private function mapGroup(int $sourceGroupId, int $toSessionId): array
    {
        $source = FeeGroup::query()
            ->withoutGlobalScope(SessionScope::class)
            ->find($sourceGroupId);

        if (! $source) {
            throw new \RuntimeException("Fee group {$sourceGroupId} no longer exists — refresh the preview.");
        }

        $existing = FeeGroup::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('session_id', $toSessionId)
            ->where('branch_id', $source->branch_id)
            ->where('name', $source->name)
            ->first();

        if ($existing) {
            return [(int) $existing->id, false];
        }

        $clone = $source->replicate();
        $clone->session_id = $toSessionId;
        $clone->deleted_at = null;
        $clone->save();

        // A group created now must be visible to the next master that asks for it.
        unset($this->naturalKeyCache['fee_groups:'.$toSessionId]);

        return [(int) $clone->id, true];
    }

    /** @param  Collection<int, FeeMaster>  $masters */
    private function sourceGroupNames(Collection $masters): array
    {
        return FeeGroup::query()
            ->withoutGlobalScope(SessionScope::class)
            ->whereIn('id', $masters->pluck('fee_group_id')->filter()->unique())
            ->pluck('name', 'id')
            ->all();
    }

    // ------------------------------------------------------------- Identity key

    /**
     * The SESSION-INDEPENDENT identity of a head.
     *
     * The trick that makes a source master and its target-session twin compare
     * equal: every session-local id is replaced by the NATURAL KEY of the row it
     * points at (branch + name), and a self-reference by the parent's own identity.
     * "Grade 5" is "Grade 5" in both years even though the two `classes` rows have
     * different ids, so no remapping is needed to ask "is this head already there?".
     * Dates are shifted by `$shiftMonths` — pass the delta for a source master and
     * 0 for one already in the target session.
     */
    public function headKey(FeeMaster $master, int $shiftMonths = 0, int $depth = 0): string
    {
        $sessionLocal = $this->sessionLocalKeys();
        $dates = $this->dateColumns();
        $parts = [];

        foreach ($this->identityColumns() as $column) {
            $value = $master->getAttribute($column);

            $parts[] = $column.'='.match (true) {
                isset($sessionLocal[$column]) => $this->identityOfReference(
                    $sessionLocal[$column],
                    $value,
                    $shiftMonths,
                    $depth,
                ),
                in_array($column, $dates, true) => $this->shiftDate($this->dateString($value), $shiftMonths) ?? '~',
                default => $this->normalise($value),
            };
        }

        return implode('|', $parts);
    }

    /**
     * The session-independent identity of a referenced row: its natural key, or —
     * for a `fee_masters` self-reference — the parent head's own key, so an
     * installment child is identified by WHICH SCHEDULE it belongs to rather than
     * by an id that changes every year.
     *
     * `$depth` stops a cyclic parent chain (which the schema permits even if the UI
     * does not) from recursing forever; beyond it the raw id is used, which at worst
     * makes a pathological row look unique and get cloned.
     */
    private function identityOfReference(string $table, mixed $id, int $shiftMonths, int $depth): string
    {
        if ($id === null) {
            return '~';
        }

        if ($table === 'fee_masters') {
            if ($depth >= 3) {
                return 'depth:'.$id;
            }

            $parent = FeeMaster::query()
                ->withoutGlobalScope(SessionScope::class)
                ->withTrashed()
                ->find($id);

            return $parent === null ? '~' : '['.$this->headKey($parent, $shiftMonths, $depth + 1).']';
        }

        return $this->sourceNaturalKey($table, (int) $id) ?? ('id:'.$id);
    }

    /** The head keys already in the target session, key => master id. */
    private function existingHeadKeys(int $toSessionId): array
    {
        $keys = [];

        FeeMaster::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('session_id', $toSessionId)
            ->get()
            ->each(function (FeeMaster $m) use (&$keys): void {
                $keys[$this->headKey($m)] = (int) $m->id;
            });

        return $keys;
    }

    // ------------------------------------------------------------------ Helpers

    /** @return array{percent: float, rounding: int, rounding_mode: string, shift_months: int} */
    private function options(int $fromSessionId, int $toSessionId, array $options): array
    {
        return [
            'percent' => (float) ($options['percent'] ?? 0),
            'rounding' => max(0, (int) ($options['rounding'] ?? 0)),
            'rounding_mode' => (string) ($options['rounding_mode'] ?? 'nearest'),
            'shift_months' => (int) ($options['shift_months'] ?? $this->shiftMonths($fromSessionId, $toSessionId)),
        ];
    }

    /**
     * A date moved by whole months.
     *
     * NoOverflow so 31 Jan + 1 month lands on 28/29 Feb rather than skipping into
     * March, which would move a charge into the wrong installment.
     */
    private function shiftDate(?string $date, int $shiftMonths): ?string
    {
        return $date === null
            ? null
            : Carbon::parse($date)->addMonthsNoOverflow($shiftMonths)->toDateString();
    }

    /** Y-m-d of whatever a date-cast attribute happens to be holding. */
    private function dateString(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => Carbon::parse((string) $value)->toDateString(),
        };
    }

    /** How a head is scoped, for the preview's "Class" column. */
    private function scopeLabel(FeeMaster $master): string
    {
        $parts = [];

        foreach ($this->sessionLocalKeys() as $column => $table) {
            if ($column === 'fee_group_id' || $table === 'fee_masters') {
                continue;
            }

            $id = $master->getAttribute($column);
            $parts[] = $id === null
                ? 'All '.$table
                : ($this->sourceNaturalName($table, (int) $id) ?? (string) $id);
        }

        return $parts === [] ? '—' : implode(' / ', $parts);
    }

    /** Stable string form of an attribute, so two equal values key identically. */
    private function normalise(mixed $value): string
    {
        return match (true) {
            $value === null => '~',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) || is_int($value) => (string) round((float) $value, 4),
            default => (string) $value,
        };
    }
}
