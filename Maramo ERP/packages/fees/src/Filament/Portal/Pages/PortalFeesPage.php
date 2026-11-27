<?php

namespace Classivo\Fees\Filament\Portal\Pages;

use Classivo\Core\Tenancy\Scopes\SessionScope;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Support\FeeDues;
use Classivo\Fees\Support\Portal;
use Classivo\Students\Models\Student;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * Shared read-only fee view for the student and guardian portals: the summary,
 * every fee line of the current session with its balance, and the receipts
 * (each linking to its PDF). Only students linked to the signed-in user are
 * ever shown.
 */
abstract class PortalFeesPage extends Page
{
    protected string $view = 'classivo-fees::filament.portal.fees';

    protected static ?string $slug = 'fees';

    protected static ?int $navigationSort = 30;

    /**
     * Locked so the browser cannot rewrite it; every read below also goes back
     * through student(), which only returns a student linked to the user.
     */
    #[Locked]
    public ?int $studentId = null;

    public static function canAccess(): bool
    {
        return Portal::studentsFor(auth()->user())->isNotEmpty();
    }

    public function mount(): void
    {
        $students = $this->students();
        $requested = request()->integer('student');

        $this->studentId = $students->contains('id', $requested) ? $requested : $students->first()?->id;
    }

    /** @return Collection<int, Student> */
    public function students(): Collection
    {
        return Portal::studentsFor(auth()->user());
    }

    public function student(): ?Student
    {
        return $this->students()->firstWhere('id', $this->studentId);
    }

    /** @return array{total: float, paid: float, discount: float, fine: float, balance: float} */
    public function summary(): array
    {
        $id = $this->ownStudentId();

        return $id === null
            ? ['total' => 0.0, 'paid' => 0.0, 'discount' => 0.0, 'fine' => 0.0, 'balance' => 0.0]
            : FeeDues::summary($id);
    }

    /** @return Collection<int, FeeStudentAllocation> */
    public function allocations(): Collection
    {
        $id = $this->ownStudentId();
        if ($id === null) {
            return collect();
        }

        return FeeStudentAllocation::query()
            ->where('student_id', $id)
            ->when(current_session_id(), fn ($q, $id) => $q->where('session_id', $id))
            ->with('feeMaster.feeType')
            ->orderBy('due_date')
            ->get();
    }

    /** @return Collection<int, FeePayment> */
    public function receipts(): Collection
    {
        $id = $this->ownStudentId();
        if ($id === null) {
            return collect();
        }

        // Receipts from earlier sessions stay visible; school and branch scopes stay on.
        return FeePayment::query()
            ->withoutGlobalScope(SessionScope::class)
            ->where('student_id', $id)
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get();
    }

    /** The selected student's id, re-checked against the signed-in user's own students. */
    protected function ownStudentId(): ?int
    {
        return $this->student()?->id;
    }

    public function switchStudent(int $id): void
    {
        if ($this->students()->contains('id', $id)) {
            $this->studentId = $id;
        }
    }
}
