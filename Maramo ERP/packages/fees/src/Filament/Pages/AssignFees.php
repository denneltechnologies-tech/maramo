<?php

namespace Classivo\Fees\Filament\Pages;

use BackedEnum;
use Classivo\Core\Models\SchoolClass;
use Classivo\Fees\Services\FeeAllocationService;
use Classivo\Fees\Support\FeesPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Assign Fees — raise the applicable fee masters against every student enrolled
 * in a class. Idempotent: lines already issued are left exactly as they are,
 * optional masters are never assigned in bulk, and where several prices apply
 * to a student only the most specific one is billed.
 */
class AssignFees extends FeesPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?int $navigationSort = 45;

    public ?array $data = [];

    public static function permission(): string
    {
        return FeesPermissions::ASSIGN;
    }

    protected static function englishTitle(): string
    {
        return 'Assign Fees';
    }

    public function mount(): void
    {
        $this->form->fill(['class_id' => null]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make(__('Assign by class'))
                    ->columns(3)
                    ->schema([
                        Select::make('class_id')
                            ->label(__('Class'))
                            ->options(fn (): array => SchoolClass::query()->orderBy('numeric_order')->pluck('name', 'id')->all())
                            ->searchable()
                            ->live()
                            ->required()
                            ->columnSpan(2),
                        Actions::make([
                            Action::make('allocate')
                                ->label(__('Assign to class'))
                                ->icon(Heroicon::OutlinedUserPlus)
                                ->color('primary')
                                ->requiresConfirmation()
                                ->modalDescription(fn (Get $get): string => $this->previewText($get('class_id')))
                                ->action(fn () => $this->allocate()),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function allocate(): void
    {
        $class = SchoolClass::find($this->data['class_id'] ?? null);

        if (! $class) {
            Notification::make()->warning()->title(__('Choose a class first.'))->send();

            return;
        }

        $tally = app(FeeAllocationService::class)->sweepClass($class);

        $notification = Notification::make()->success()->title($tally['created'] > 0
            ? __('Created :count new fee line(s).', ['count' => $tally['created']])
            : __('No new fee lines — already assigned.'));

        if (($tally['superseded'] ?? 0) > 0) {
            $notification->warning()
                ->body(__(':count line(s) were not raised because those students are already billed for the same fee head at another price.', ['count' => $tally['superseded']]))
                ->persistent();
        }

        $notification->send();
    }

    protected function previewText(mixed $classId): string
    {
        $class = SchoolClass::find($classId);

        if (! $class) {
            return __('Choose a class first.');
        }

        $service = app(FeeAllocationService::class);
        $students = $service->studentsInClass($class);

        if ($students->isEmpty()) {
            return __('No students are enrolled in this class in the current session.');
        }

        $candidates = $service->mastersForClass($class);
        $held = $service->existingBillingKeys($students);
        $new = 0;
        $already = 0;

        foreach ($students as $student) {
            foreach ($service->resolveMastersFor($student, $candidates) as $master) {
                $key = ((int) $master->session_id).'|'.$master->billingKey();

                isset($held[(int) $student->getKey()][$key]) ? $already++ : $new++;
            }
        }

        return __('This raises :new new fee line(s) for :students student(s); :already line(s) are already assigned and stay as issued. Optional fee masters are assigned per student only.', [
            'new' => $new,
            'students' => $students->count(),
            'already' => $already,
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getFormContentComponent()]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])->id('form');
    }
}
