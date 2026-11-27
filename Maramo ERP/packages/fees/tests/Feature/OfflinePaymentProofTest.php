<?php

namespace Classivo\Fees\Tests\Feature;

use Classivo\Fees\Filament\Resources\OfflinePayments\Pages\CreateOfflinePayment;
use Classivo\Fees\Models\OfflinePayment;
use Classivo\Fees\Support\FeesPermissions;
use Classivo\Fees\Tests\UiTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class OfflinePaymentProofTest extends UiTestCase
{
    public function test_proof_upload_rejects_html_and_accepts_pdf_on_a_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->userWith([FeesPermissions::COLLECT]);
        $student = $this->student();

        Livewire::test(CreateOfflinePayment::class)
            ->fillForm([
                'student_id' => $student->id,
                'amount' => 500,
                'attachment_path' => UploadedFile::fake()->createWithContent('proof.html', '<script>alert(1)</script>'),
            ])
            ->call('create')
            ->assertHasFormErrors(['attachment_path']);

        $this->assertSame(0, OfflinePayment::query()->count());

        Livewire::test(CreateOfflinePayment::class)
            ->fillForm([
                'student_id' => $student->id,
                'amount' => 500,
                'attachment_path' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $payment = OfflinePayment::query()->firstOrFail();
        $this->assertNotEmpty($payment->attachment_path);
        Storage::disk('local')->assertExists($payment->attachment_path);
        Storage::disk('public')->assertMissing($payment->attachment_path);
    }

    public function test_proof_upload_has_a_size_cap(): void
    {
        Storage::fake('local');
        $this->userWith([FeesPermissions::COLLECT]);

        Livewire::test(CreateOfflinePayment::class)
            ->fillForm([
                'student_id' => $this->student()->id,
                'amount' => 500,
                'attachment_path' => UploadedFile::fake()->create('big.pdf', 8000, 'application/pdf'),
            ])
            ->call('create')
            ->assertHasFormErrors(['attachment_path']);
    }

    public function test_proof_download_requires_the_collect_permission(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('offline-payments/p.pdf', '%PDF-1.4 test');
        $payment = OfflinePayment::query()->create([
            'student_id' => $this->student()->id,
            'amount' => 500,
            'attachment_path' => 'offline-payments/p.pdf',
        ]);

        $this->userWith([]);
        $this->get(route('fees.offline-payment.proof', $payment->id))->assertForbidden();

        $this->userWith([FeesPermissions::COLLECT], 'collector@example.test');
        $this->get(route('fees.offline-payment.proof', $payment->id))->assertOk();
    }
}
