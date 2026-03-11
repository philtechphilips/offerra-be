<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\JobApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function stats()
    {
        $totalUsers = User::count();
        $totalJobs = JobApplication::count();
        $recentUsers = User::where('created_at', '>=', now()->subDays(7))->count();
        $activeUsers = User::has('jobApplications')->count();
        
        // Growth stats (last 30 days)
        $usersLastMonth = User::where('created_at', '>=', now()->subDays(30))->count();
        $jobsLastMonth = JobApplication::where('created_at', '>=', now()->subDays(30))->count();
        
        // Distribution by status
        $jobDistribution = JobApplication::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get();
            
        // Top 5 companies
        $topCompanies = JobApplication::select('company', DB::raw('count(*) as total'))
            ->groupBy('company')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        return response()->json([
            'stats' => [
                'total_users' => $totalUsers,
                'total_jobs' => $totalJobs,
                'recent_users_7d' => $recentUsers,
                'active_users' => $activeUsers,
                'growth' => [
                    'users_30d' => $usersLastMonth,
                    'jobs_30d' => $jobsLastMonth
                ]
            ],
            'distribution' => $jobDistribution,
            'top_companies' => $topCompanies
        ]);
    }

    public function users(Request $request)
    {
        $query = User::withCount('jobApplications');

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%");
            });
        }

        if ($request->has('role')) {
            $query->where('role', $request->get('role'));
        }

        $users = $query->latest()->paginate(20);

        return response()->json($users);
    }

    public function updateUserRole(Request $request, $id)
    {
        $request->validate([
            'role' => 'required|in:admin,user'
        ]);

        $user = User::findOrFail($id);
        $user->role = $request->role;
        $user->save();

        return response()->json([
            'message' => 'User role updated successfully',
            'user' => $user
        ]);
    }

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return response()->json(['error' => 'You cannot delete yourself'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}
