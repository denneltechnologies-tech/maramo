<?php

namespace Tests\Feature\Scenarios;

use Classivo\Library\Exceptions\LibraryException;
use Classivo\Library\LibraryPlugin;
use Classivo\Library\Models\Book;
use Classivo\Library\Models\BookFine;
use Classivo\Library\Models\BookIssue;
use Classivo\Library\Models\LibraryMember;
use Classivo\Library\Services\LibraryService;
use Classivo\Students\Models\Student;

/** A seeded student borrows a book, returns it late and pays a fine. */
class LibraryScenarioTest extends ScenarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requirePackages(LibraryPlugin::class);
    }

    public function test_issue_and_late_return_charges_the_fine_and_frees_the_copy(): void
    {
        $this->bootDemo();
        $this->login('admin@example.com');

        $rule = BookFine::current();
        $rule->forceFill(['fine_per_day' => 3, 'grace_days' => 0])->save();

        $student = Student::orderBy('id')->firstOrFail();
        $member = LibraryMember::firstOrCreate(['student_id' => $student->id], ['max_books' => 3, 'status' => 'active']);
        $book = Book::create(['title' => 'Atlas of Everything', 'book_no' => 'SC-0001', 'quantity' => 1, 'available_qty' => 1, 'status' => true]);

        $service = app(LibraryService::class);
        $issue = $service->issue($book, $member, ['issue_date' => '2030-03-01', 'due_date' => '2030-03-08']);
        $this->assertSame(0, $book->fresh()->available_qty);

        // The only copy is out: a second student cannot take it.
        $second = LibraryMember::create(['student_id' => Student::orderBy('id', 'desc')->value('id'), 'max_books' => 3, 'status' => 'active']);
        try {
            $service->issue($book->fresh(), $second);
            $this->fail('issuing an unavailable book must throw');
        } catch (LibraryException) {
            $this->assertTrue(true);
        }

        $returned = $service->returnBook($issue, ['return_date' => '2030-03-12']);
        $this->assertSame(BookIssue::STATUS_RETURNED, $returned->status);
        $this->assertSame('12.00', $returned->fine); // 4 days x 3
        $this->assertSame(1, $book->fresh()->available_qty);

        // Returning twice is refused.
        $this->expectException(LibraryException::class);
        $service->returnBook($returned);
    }
}
