<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Material;
use App\Models\Submission;
use App\Models\Assignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityW07Test extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Pesan login tidak membocorkan keberadaan email (No User Enumeration)
     */
    public function test_login_does_not_reveal_email_existence(): void
    {
        $response = $this->post('/login', [
            'email' => 'unregistered_user@kampuslms.test',
            'password' => 'anypassword',
        ]);

        $response->assertSessionHasErrors([
            'email' => 'Email atau password yang Anda masukkan salah.',
        ]);
    }

    /**
     * 2. Route courses.create dilindungi middleware auth
     */
    public function test_guest_cannot_access_courses_create_route(): void
    {
        $response = $this->get('/courses/create');

        $response->assertRedirect('/login');
    }

    /**
     * 3. Mahasiswa tidak mengalami kebocoran daftar mata kuliah
     */
    public function test_student_only_sees_enrolled_courses(): void
    {
        $student = User::factory()->create(['role' => 'mahasiswa']);
        $lecturer = User::factory()->create(['role' => 'dosen']);

        $enrolledCourse = Course::create([
            'code' => 'SI101',
            'name' => 'Pemrograman Web',
            'sks' => 3,
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);
        $unEnrolledCourse = Course::create([
            'code' => 'SI999',
            'name' => 'Mata Kuliah Rahasia Lain',
            'sks' => 3,
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $student->courses()->attach($enrolledCourse->id);

        $response = $this->actingAs($student)->get('/courses');

        $response->assertStatus(200);
        $response->assertSee('SI101');
        $response->assertDontSee('SI999');
    }

    /**
     * 4. Mahasiswa tidak bisa update/delete course (@can + Gate::authorize)
     */
    public function test_mahasiswa_cannot_update_or_delete_course(): void
    {
        $student = User::factory()->create(['role' => 'mahasiswa']);
        $lecturer = User::factory()->create(['role' => 'dosen']);

        $course = Course::create([
            'code' => 'SI102',
            'name' => 'Struktur Data',
            'sks' => 3,
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $updateResponse = $this->actingAs($student)->put("/courses/{$course->id}", [
            'code' => 'SI102',
            'name' => 'Nama Dirusak Mahasiswa',
            'sks' => 3,
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $updateResponse->assertStatus(403);

        $deleteResponse = $this->actingAs($student)->delete("/courses/{$course->id}");
        $deleteResponse->assertStatus(403);
    }

    /**
     * 5. Mahasiswa tidak bisa menghapus materi
     */
    public function test_mahasiswa_cannot_delete_material(): void
    {
        $student = User::factory()->create(['role' => 'mahasiswa']);
        $lecturer = User::factory()->create(['role' => 'dosen']);

        $course = Course::create([
            'code' => 'SI103',
            'name' => 'Jaringan Komputer',
            'sks' => 3,
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $material = Material::create([
            'course_id' => $course->id,
            'uploaded_by' => $lecturer->id,
            'title' => 'Modul 1',
            'type' => 'link',
            'external_url' => 'https://example.com',
        ]);

        $response = $this->actingAs($student)->delete("/materials/{$material->id}");
        $response->assertStatus(403);
    }

    /**
     * 6. SubmissionPolicy tidak mengizinkan mahasiswa melihat submission mahasiswa lain
     */
    public function test_student_cannot_view_other_student_submission(): void
    {
        $student1 = User::factory()->create(['role' => 'mahasiswa']);
        $student2 = User::factory()->create(['role' => 'mahasiswa']);
        $lecturer = User::factory()->create(['role' => 'dosen']);

        $course = Course::create([
            'code' => 'SI104',
            'name' => 'Kecerdasan Buatan',
            'sks' => 3,
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $assignment = Assignment::create([
            'course_id' => $course->id,
            'title' => 'Tugas 1',
            'due_at' => now()->addDays(7),
            'max_score' => 100,
        ]);

        $submission = Submission::create([
            'assignment_id' => $assignment->id,
            'user_id' => $student2->id,
            'file_path' => 'submissions/fake.pdf',
            'original_name' => 'fake.pdf',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($student1)->get("/submissions/{$submission->id}/download");
        $response->assertStatus(403);
    }

    /**
     * 7. User tidak bisa mengubah role sendiri via update
     */
    public function test_user_cannot_escalate_their_own_role(): void
    {
        $student = User::factory()->create(['role' => 'mahasiswa']);

        $response = $this->actingAs($student)->put("/users/{$student->id}", [
            'name' => 'Mahasiswa Jadi Admin',
            'email' => $student->email,
            'role' => 'admin',
        ]);

        $response->assertStatus(302);
        $student->refresh();
        $this->assertEquals('mahasiswa', $student->role);
    }

    /**
     * 8. Non-admin tidak bisa membuka index users
     */
    public function test_non_admin_cannot_access_user_index(): void
    {
        $student = User::factory()->create(['role' => 'mahasiswa']);

        $response = $this->actingAs($student)->get('/users');
        $response->assertStatus(403);
    }
}
