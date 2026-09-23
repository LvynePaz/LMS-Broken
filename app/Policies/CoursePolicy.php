<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Course $course): bool
    {
        if ($user->role === 'admin' || $course->lecturer_id === $user->id) {
            return true;
        }

        return $course->students()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'dosen']);
    }

    public function update(User $user, Course $course): bool
    {
        return $user->role === 'admin' || $course->lecturer_id === $user->id;
    }

    public function delete(User $user, Course $course): bool
    {
        return $user->role === 'admin' || $course->lecturer_id === $user->id;
    }
}
