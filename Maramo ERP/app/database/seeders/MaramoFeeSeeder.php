<?php

namespace Database\Seeders;

use App\Models\CourseApplication;
use App\Models\User;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\Fees\Models\FeeGroup;
use Classivo\Fees\Models\FeeMaster;
use Classivo\Fees\Models\FeePayment;
use Classivo\Fees\Models\FeePaymentDetail;
use Classivo\Fees\Models\FeeStudentAllocation;
use Classivo\Fees\Models\FeeType;
use Classivo\Students\Models\Student;
use Illuminate\Database\Seeder;

class MaramoFeeSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::query()->first();
        $branch = Branch::query()->first();
        $session = AcademicSession::query()->where('is_current', true)->first();
        $admin = User::query()->where('email', 'admin@maramobeauty.com')->first();

        if (! $school || ! $branch || ! $session) {
            return;
        }

        // 1. Fee Types
        $tuition = FeeType::firstOrCreate(['school_id' => $school->id, 'branch_id' => $branch->id, 'code' => 'TUI'], [
            'name' => 'Tuition Fee',
            'description' => 'Course instruction and practical studio sessions',
            'status' => true,
        ]);

        $regFee = FeeType::firstOrCreate(['school_id' => $school->id, 'branch_id' => $branch->id, 'code' => 'REG'], [
            'name' => 'Admission & Registration Fee',
            'description' => 'One-time registration and student ID card processing',
            'status' => true,
        ]);

        $kitFee = FeeType::firstOrCreate(['school_id' => $school->id, 'branch_id' => $branch->id, 'code' => 'KIT'], [
            'name' => 'Beauty Starter Kit & Materials',
            'description' => 'Training kits, mannequin heads, practice tools and products',
            'status' => true,
        ]);

        // 2. Fee Group
        $group = FeeGroup::firstOrCreate(['school_id' => $school->id, 'branch_id' => $branch->id, 'name' => 'General Academy Fees'], [
            'description' => 'Standard training fees for all intakes',
            'status' => true,
        ]);

        // 3. Fee Masters
        $tuiMaster = FeeMaster::firstOrCreate([
            'school_id' => $school->id,
            'branch_id' => $branch->id,
            'session_id' => $session->id,
            'fee_group_id' => $group->id,
            'fee_type_id' => $tuition->id,
        ], [
            'amount' => 2500.00,
            'due_date' => now()->addDays(30),
            'status' => true,
        ]);

        $regMaster = FeeMaster::firstOrCreate([
            'school_id' => $school->id,
            'branch_id' => $branch->id,
            'session_id' => $session->id,
            'fee_group_id' => $group->id,
            'fee_type_id' => $regFee->id,
        ], [
            'amount' => 350.00,
            'due_date' => now()->addDays(7),
            'status' => true,
        ]);

        $kitMaster = FeeMaster::firstOrCreate([
            'school_id' => $school->id,
            'branch_id' => $branch->id,
            'session_id' => $session->id,
            'fee_group_id' => $group->id,
            'fee_type_id' => $kitFee->id,
        ], [
            'amount' => 1200.00,
            'due_date' => now()->addDays(14),
            'status' => true,
        ]);

        // 4. Allocate fees to enrolled students
        $students = Student::query()->get();
        foreach ($students as $student) {
            foreach ([$regMaster, $tuiMaster, $kitMaster] as $master) {
                FeeStudentAllocation::firstOrCreate([
                    'school_id' => $school->id,
                    'branch_id' => $branch->id,
                    'session_id' => $session->id,
                    'student_id' => $student->id,
                    'fee_master_id' => $master->id,
                ], [
                    'amount' => $master->amount,
                    'due_date' => $master->due_date,
                    'paid_amount' => 0.00,
                    'status' => 'unpaid',
                ]);
            }
        }

        // 5. Seed some sample payments across different payment modes (Mobile Money, Bank Deposit, Cash)
        $sampleModes = [
            ['mode' => 'mobile_money', 'ref' => 'MTN-MOMO-891024', 'note' => 'Paid via MTN Mobile Money'],
            ['mode' => 'bank_deposit', 'ref' => 'GCB-ACC-783210', 'note' => 'GCB Bank deposit slip #4102'],
            ['mode' => 'cash', 'ref' => 'CASH-REC-001', 'note' => 'Cash received at academy front desk'],
            ['mode' => 'bank_transfer', 'ref' => 'E-LEVY-TRF-6641', 'note' => 'Instant bank transfer via mobile app'],
        ];

        $payingStudents = $students->take(12);
        $receiptSeq = ((int) FeePayment::query()->where('branch_id', $branch->id)->max('receipt_seq')) + 1;

        foreach ($payingStudents as $index => $student) {
            $allocation = FeeStudentAllocation::query()
                ->where('student_id', $student->id)
                ->where('fee_master_id', $regMaster->id)
                ->where('status', 'unpaid')
                ->first();

            if (! $allocation) {
                continue;
            }

            $modeInfo = $sampleModes[$index % count($sampleModes)];
            $payAmount = $allocation->amount; // Full payment of registration fee
            $rNo = 'MBI-RCPT-' . str_pad((string) $receiptSeq, 4, '0', STR_PAD_LEFT);

            $payment = FeePayment::firstOrCreate([
                'branch_id' => $branch->id,
                'receipt_no' => $rNo,
            ], [
                'school_id' => $school->id,
                'session_id' => $session->id,
                'student_id' => $student->id,
                'receipt_seq' => $receiptSeq++,
                'payment_date' => now()->subDays($index * 2),
                'total_amount' => $payAmount,
                'paid_amount' => $payAmount,
                'discount_amount' => 0.00,
                'fine_amount' => 0.00,
                'mode' => $modeInfo['mode'],
                'reference_no' => $modeInfo['ref'],
                'note' => $modeInfo['note'],
                'collected_by' => $admin?->id,
                'status' => 'completed',
            ]);

            FeePaymentDetail::firstOrCreate([
                'fee_payment_id' => $payment->id,
                'fee_student_allocation_id' => $allocation->id,
            ], [
                'school_id' => $school->id,
                'branch_id' => $branch->id,
                'fee_type_id' => $regFee->id,
                'label' => 'Admission & Registration Fee',
                'amount' => $payAmount,
                'discount_amount' => 0.00,
                'fine_amount' => 0.00,
            ]);

            $allocation->update([
                'paid_amount' => $payAmount,
                'status' => 'paid',
            ]);
        }

        // 6. Seed sample Online & Walk-in Applications for the Admissions pipeline
        $sampleApplications = [
            [
                'first_name' => 'Priscilla',
                'last_name' => 'Annan',
                'phone' => '+233541122334',
                'email' => 'priscilla.annan@gmail.com',
                'gender' => 'female',
                'course' => 'Comprehensive Cosmetology',
                'schedule' => 'morning',
                'source' => 'website',
                'status' => 'pending',
                'notes' => 'Inquired through website. Looking to start in next cohort.',
            ],
            [
                'first_name' => 'Jessica',
                'last_name' => 'Boadi',
                'phone' => '+233249988776',
                'email' => 'jessica.b@yahoo.com',
                'gender' => 'female',
                'course' => 'Wig & Frontal Installation Mastery',
                'schedule' => 'weekend',
                'source' => 'website',
                'status' => 'under_review',
                'notes' => 'Self-taught beginner wanting professional frontal melting skills.',
            ],
            [
                'first_name' => 'Beatrice',
                'last_name' => 'Quaye',
                'phone' => '+233504455667',
                'email' => 'beatrice.q@hotmail.com',
                'gender' => 'female',
                'course' => 'Professional Makeup Artistry',
                'schedule' => 'morning',
                'source' => 'walk_in',
                'status' => 'approved',
                'notes' => 'Visited the academy in person. Interviewed and approved by Director.',
            ],
            [
                'first_name' => 'Eunice',
                'last_name' => 'Tetteh',
                'phone' => '+233557788990',
                'email' => 'eunice.t@outlook.com',
                'gender' => 'female',
                'course' => 'Nail Technology & Pedicure',
                'schedule' => 'afternoon',
                'source' => 'social_media',
                'status' => 'pending',
                'notes' => 'Reached out via Instagram DM.',
            ],
        ];

        foreach ($sampleApplications as $app) {
            $courseId = SchoolClass::query()->where('name', $app['course'])->value('id')
                ?? SchoolClass::query()->value('id');

            CourseApplication::create([
                'school_id' => $school->id,
                'branch_id' => $branch->id,
                'session_id' => $session->id,
                'class_id' => $courseId,
                'application_no' => CourseApplication::generateApplicationNumber(),
                'first_name' => $app['first_name'],
                'last_name' => $app['last_name'],
                'phone' => $app['phone'],
                'email' => $app['email'],
                'gender' => $app['gender'],
                'preferred_schedule' => $app['schedule'],
                'source' => $app['source'],
                'status' => $app['status'],
                'notes' => $app['notes'],
                'city' => 'Accra',
            ]);
        }
    }
}
