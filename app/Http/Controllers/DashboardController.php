<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $totalUsers = User::count();
            $totalCourses = Course::count();
            $recentCourses = Course::with('lecturer')->latest()->take(5)->get();

            return view('dashboard.admin', compact('totalUsers', 'totalCourses', 'recentCourses'));
        }

        if ($user->role === 'dosen') {
            $courses = Course::where('lecturer_id', $user->id)->withCount('students')->get();
            $recentSubmissions = Assignment::whereIn('course_id', $courses->pluck('id'))
                ->with(['submissions.student', 'submissions.assignment'])
                ->latest()
                ->take(10)
                ->get();

            return view('dashboard.dosen', compact('courses', 'recentSubmissions'));
        }

        // Mahasiswa
        $enrolledCourses = $user->courses()->with('lecturer')->get();
        $upcomingAssignments = Assignment::whereIn('course_id', $enrolledCourses->pluck('id'))
            ->where('due_at', '>=', now())
            ->orderBy('due_at')
            ->get();

        return view('dashboard.mahasiswa', compact('enrolledCourses', 'upcomingAssignments'));
    }
}
