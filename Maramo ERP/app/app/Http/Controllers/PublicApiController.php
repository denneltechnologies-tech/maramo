<?php

namespace App\Http\Controllers;

use App\Models\CourseApplication;
use Classivo\Core\Models\AcademicSession;
use Classivo\Core\Models\Branch;
use Classivo\Core\Models\School;
use Classivo\Core\Models\SchoolClass;
use Classivo\ReportCards\Models\GeneratedCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PublicApiController extends Controller
{
    /** Return available beauty programs for the website. */
    public function courses(): JsonResponse
    {
        $courses = SchoolClass::query()
            ->orderBy('numeric_order')
            ->get(['id', 'name', 'numeric_order'])
            ->map(fn (SchoolClass $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'duration' => match ($c->numeric_order) {
                    1 => '3 Months',
                    2 => '2 Months',
                    3 => '2 Months',
                    4 => '6 Months',
                    5 => '1 Month',
                    default => '2-3 Months',
                },
            ]);

        return response()->json([
            'success' => true,
            'courses' => $courses,
        ]);
    }

    /** Handle online course applications from the website. */
    public function apply(Request $request): JsonResponse
    {
        if ($request->has('full_name') && ! $request->has('first_name')) {
            $parts = explode(' ', trim($request->input('full_name')), 2);
            $request->merge([
                'first_name' => $parts[0] ?? 'Applicant',
                'last_name' => $parts[1] ?? 'Student',
            ]);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:150',
            'course_id' => 'nullable|exists:classes,id',
            'preferred_schedule' => 'nullable|string',
            'prior_experience' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $school = School::query()->first();
        $branch = Branch::query()->first();
        $session = AcademicSession::query()->where('is_current', true)->first()
            ?? AcademicSession::query()->first();

        $appNumber = CourseApplication::generateApplicationNumber();

        $application = CourseApplication::create([
            'school_id' => $school?->id ?? 1,
            'branch_id' => $branch?->id ?? 1,
            'session_id' => $session?->id ?? 1,
            'class_id' => $request->input('course_id') ?: SchoolClass::query()->value('id'),
            'application_no' => $appNumber,
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'city' => $request->input('city', 'Accra'),
            'address' => $request->input('address'),
            'preferred_schedule' => $request->input('preferred_schedule', 'morning'),
            'prior_experience' => $request->input('prior_experience'),
            'source' => 'website',
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'application_no' => $appNumber,
            'message' => "Application submitted successfully! Your application reference number is {$appNumber}. Management will review your application and contact you on WhatsApp/Phone.",
        ]);
    }

    /** Handle contact/inquiry form submissions from the website. */
    public function inquiry(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'service' => 'nullable|string|max:100',
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $names = explode(' ', trim((string) $request->input('name')), 2);
        $firstName = $names[0] ?? 'Prospective';
        $lastName = $names[1] ?? 'Student';

        $school = School::query()->first();
        $branch = Branch::query()->first();
        $session = AcademicSession::query()->where('is_current', true)->first()
            ?? AcademicSession::query()->first();

        $appNumber = CourseApplication::generateApplicationNumber();

        CourseApplication::create([
            'school_id' => $school?->id ?? 1,
            'branch_id' => $branch?->id ?? 1,
            'session_id' => $session?->id ?? 1,
            'class_id' => SchoolClass::query()->value('id'),
            'application_no' => $appNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $request->input('phone'),
            'source' => 'website',
            'status' => 'pending',
            'notes' => "Website Inquiry for service: " . $request->input('service') . "\nMessage: " . $request->input('message'),
        ]);

        return response()->json([
            'success' => true,
            'application_no' => $appNumber,
            'message' => "Thank you! Your message has been received. Our team will contact you shortly.",
        ]);
    }

    /** Public certificate verification by certificate number or QR code scan. */
    public function verifyCertificate(Request $request, string $code)
    {
        $cert = GeneratedCertificate::query()
            ->with(['student', 'certificateTemplate'])
            ->where('certificate_number', $code)
            ->first();

        if (! $cert) {
            if ($request->wantsJson()) {
                return response()->json([
                    'valid' => false,
                    'message' => "Certificate [{$code}] was not found in the official Maramo Beauty Institute registry.",
                ], 404);
            }
            abort(404, "Certificate [{$code}] was not found in the official registry.");
        }

        $studentName = $cert->student?->full_name ?? ($cert->data['variables']['student_name'] ?? 'Verified Graduate');
        $courseName = $cert->data['variables']['course_name'] ?? ($cert->data['variables']['class'] ?? 'Comprehensive Cosmetology');
        $admissionNo = $cert->student?->admission_no ?? ($cert->data['variables']['admission_no'] ?? 'MBI-GRAD');
        $issuedAt = $cert->issued_at?->format('d F Y') ?? $cert->created_at->format('d F Y');
        $certNumber = $cert->certificate_number;

        if ($request->wantsJson()) {
            return response()->json([
                'valid' => true,
                'certificate_number' => $certNumber,
                'student_name' => $studentName,
                'course' => $courseName,
                'issued_at' => $issuedAt,
                'institution' => 'Maramo Beauty Institute (Accra, Ghana)',
                'status' => 'Officially Verified & Authentic',
            ]);
        }

        return view('verify-certificate', compact('cert', 'studentName', 'courseName', 'admissionNo', 'issuedAt', 'certNumber'));
    }
}
