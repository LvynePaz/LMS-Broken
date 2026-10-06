<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_login_returns_token(): void
    {
        $user = User::factory()->create([
            'email' => 'apiuser@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'apiuser@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_unauthenticated_api_request_returns_401(): void
    {
        $response = $this->getJson('/api/courses');

        $response->assertStatus(401);
    }

    public function test_authenticated_api_request_returns_course_list(): void
    {
        $user = User::factory()->create();
        Course::factory()->create(['lecturer_id' => $user->id, 'status' => 'active']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/courses');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_api_store_returns_201_created(): void
    {
        $dosen = User::factory()->create(['role' => 'dosen']);

        $response = $this->actingAs($dosen, 'sanctum')->postJson('/api/courses', [
            'code' => 'API101',
            'name' => 'API Course Test',
            'sks' => 3,
            'lecturer_id' => $dosen->id,
            'status' => 'active',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'API101');
    }

    public function test_api_login_invalid_credentials_returns_uniform_error_message(): void
    {
        $user = User::factory()->create([
            'email' => 'registered@test.com',
            'password' => bcrypt('correct-password'),
        ]);

        // Skenario 1: Email tidak terdaftar di sistem
        $responseNonExistent = $this->postJson('/api/login', [
            'email' => 'unregistered@test.com',
            'password' => 'anypassword',
        ]);

        // Skenario 2: Email terdaftar, namun password salah
        $responseWrongPassword = $this->postJson('/api/login', [
            'email' => 'registered@test.com',
            'password' => 'wrongpassword',
        ]);

        $expectedMessage = 'Email atau password yang Anda masukkan salah.';

        $responseNonExistent->assertStatus(401)
            ->assertJson(['message' => $expectedMessage]);

        $responseWrongPassword->assertStatus(401)
            ->assertJson(['message' => $expectedMessage]);
    }

    public function test_api_login_rate_limiting_throttles_after_five_failed_attempts(): void
    {
        $email = 'bruteforce@test.com';

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => $email,
                'password' => 'wrong-guess-'.$i,
            ]);
            $response->assertStatus(401);
        }

        // Percobaan ke-6 harus di-throttle dengan status HTTP 429
        $throttledResponse = $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'wrong-guess-6',
        ]);

        $throttledResponse->assertStatus(429)
            ->assertJsonStructure(['message']);
    }

    public function test_unauthenticated_course_show_returns_401(): void
    {
        $course = Course::factory()->create(['status' => 'active']);

        $response = $this->getJson("/api/courses/{$course->id}");

        $response->assertStatus(401);
    }

    public function test_api_show_returns_course_resource_with_relations(): void
    {
        $lecturer = User::factory()->create(['role' => 'dosen']);
        $course = Course::factory()->create([
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($lecturer, 'sanctum')->getJson("/api/courses/{$course->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'code',
                    'name',
                    'description',
                    'sks',
                    'status',
                    'lecturer' => [
                        'id',
                        'name',
                        'email',
                    ],
                ],
            ]);
    }

    public function test_api_update_returns_course_resource(): void
    {
        $lecturer = User::factory()->create(['role' => 'dosen']);
        $course = Course::factory()->create([
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($lecturer, 'sanctum')->putJson("/api/courses/{$course->id}", [
            'code' => $course->code,
            'name' => 'Updated Course Name',
            'sks' => 3,
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'name', 'code']])
            ->assertJsonPath('data.name', 'Updated Course Name');
    }

    public function test_api_destroy_returns_204_no_content(): void
    {
        $lecturer = User::factory()->create(['role' => 'dosen']);
        $course = Course::factory()->create([
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($lecturer, 'sanctum')->deleteJson("/api/courses/{$course->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
    }

    public function test_api_authorization_failure_returns_403_forbidden_not_401(): void
    {
        $mahasiswa = User::factory()->create(['role' => 'mahasiswa']);
        $lecturer = User::factory()->create(['role' => 'dosen']);
        $course = Course::factory()->create([
            'lecturer_id' => $lecturer->id,
            'status' => 'active',
        ]);

        // Mahasiswa mencoba menghapus course milik dosen -> harus 403 Forbidden
        $response = $this->actingAs($mahasiswa, 'sanctum')->deleteJson("/api/courses/{$course->id}");

        $response->assertStatus(403);
    }
}
