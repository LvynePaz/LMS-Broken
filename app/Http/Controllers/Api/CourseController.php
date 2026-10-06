<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CourseController extends Controller
{
    public function index(Request $request)
    {
        // Eager load lecturer & student count to prevent N+1 queries
        $courses = Course::with('lecturer')
            ->withCount('students')
            ->where('status', 'active')
            ->paginate(10);

        return CourseResource::collection($courses);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Course::class);

        $validated = $request->validate([
            'code' => 'required|string|unique:courses,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sks' => 'required|integer|min:1|max:6',
            'lecturer_id' => 'required|exists:users,id',
            'status' => 'required|in:draft,active,archived',
        ]);

        $course = Course::create($validated);
        $course->load('lecturer');

        return (new CourseResource($course))->response()->setStatusCode(201);
    }

    public function show(Course $course)
    {
        Gate::authorize('view', $course);

        $course->load(['lecturer', 'materials', 'assignments']);

        return new CourseResource($course);
    }

    public function update(Request $request, Course $course)
    {
        Gate::authorize('update', $course);

        $validated = $request->validate([
            'code' => 'required|string|unique:courses,code,'.$course->id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sks' => 'required|integer|min:1|max:6',
            'lecturer_id' => 'required|exists:users,id',
            'status' => 'required|in:draft,active,archived',
        ]);

        $course->update($validated);

        return new CourseResource($course);
    }

    public function destroy(Course $course)
    {
        Gate::authorize('delete', $course);

        $course->delete();

        return response()->json(null, 204);
    }
}
