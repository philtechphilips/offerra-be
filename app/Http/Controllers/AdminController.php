<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\JobApplication;
use App\Models\Transaction;
use App\Models\CreditLog;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function stats()
    {
        return $this->safeCall(function () {
            $totalUsers = User::count();
            $totalJobs = JobApplication::count();
            $recentUsers = User::where('created_at', '>=', now()->subDays(7))->count();
            $activeUsers = User::has('jobApplications')->count();

            $totalRevenue = Transaction::where('status', 'success')->sum('amount');
            $monthlyRevenue = Transaction::where('status', 'success')
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('amount');

            $usersLastMonth = User::where('created_at', '>=', now()->subDays(30))->count();
            $jobsLastMonth = JobApplication::where('created_at', '>=', now()->subDays(30))->count();

            $jobDistribution = JobApplication::select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get();

            $popularPlans = Plan::withCount(['transactions' => function ($q) {
                    $q->where('status', 'success');
                }])
                ->orderByDesc('transactions_count')
                ->limit(5)
                ->get();

            $dailyRevenue = Transaction::where('status', 'success')
                ->where('created_at', '>=', now()->subDays(30))
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(amount) as total'))
                ->groupBy('date')
                ->get();

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
                    'total_revenue' => $totalRevenue,
                    'monthly_revenue' => $monthlyRevenue,
                    'growth' => [
                        'users_30d' => $usersLastMonth,
                        'jobs_30d' => $jobsLastMonth
                    ]
                ],
                'distribution' => $jobDistribution,
                'popular_plans' => $popularPlans,
                'daily_revenue' => $dailyRevenue,
                'top_companies' => $topCompanies
            ]);
        }, 'AdminController@stats');
    }

    public function transactions(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $query = Transaction::with(['user:id,name,email', 'plan:id,name']);

            if ($request->has('search')) {
                $search = $request->get('search');
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%");
                })->orWhere('reference', 'like', "%$search%");
            }

            if ($request->has('status')) {
                $query->where('status', $request->get('status'));
            }

            $transactions = $query->latest()->paginate(20);

            return response()->json($transactions);
        }, 'AdminController@transactions');
    }

    public function updateCredits(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
            $request->validate([
                'amount' => 'required|integer',
                'type' => 'required|string',
                'description' => 'nullable|string'
            ]);

            $user = User::findOrFail($id);
            $amount = $request->amount;

            $user->credits += $amount;
            $user->save();

            $user->logCreditChange($amount, 'admin_adj', $request->description ?: "Admin Adjustment: " . $request->type);

            return response()->json([
                'message' => 'User credits updated successfully',
                'new_credits' => $user->credits
            ]);
        }, 'AdminController@updateCredits');
    }

    public function users(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $query = User::withCount('jobApplications');

            if ($request->has('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%");
                });
            }

            if ($request->has('role')) {
                $query->where('role', $request->get('role'));
            }

            $perPage = (int) $request->query('per_page', 20);
            $users = $query->latest()->paginate($perPage);

            return response()->json($users);
        }, 'AdminController@users');
    }

    public function updateUserRole(Request $request, $id)
    {
        return $this->safeCall(function () use ($request, $id) {
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
        }, 'AdminController@updateUserRole');
    }

    public function deleteUser($id)
    {
        return $this->safeCall(function () use ($id) {
            $user = User::findOrFail($id);

            if ($user->id === Auth::id()) {
                return response()->json(['error' => 'You cannot delete yourself'], 403);
            }

            $user->delete();

            return response()->json(['message' => 'User deleted successfully']);
        }, 'AdminController@deleteUser');
    }

    public function userActivity($id)
    {
        return $this->safeCall(function () use ($id) {
            $user = User::findOrFail($id);

            $jobs = $user->jobApplications()
                ->latest()
                ->limit(10)
                ->get(['id', 'title', 'company', 'status', 'created_at'])
                ->map(function ($job) {
                    return [
                        'type' => 'job',
                        'title' => "Job {$job->status}",
                        'description' => "{$job->title} at {$job->company}",
                        'created_at' => $job->created_at,
                        'meta' => [
                            'status' => $job->status,
                        ],
                    ];
                });

            $credits = $user->creditLogs()
                ->latest()
                ->limit(10)
                ->get(['id', 'amount', 'type', 'description', 'created_at'])
                ->map(function ($log) {
                    return [
                        'type' => 'credit',
                        'title' => "Credit {$log->type}",
                        'description' => $log->description ?: "Credit change: {$log->amount}",
                        'created_at' => $log->created_at,
                        'meta' => [
                            'amount' => $log->amount,
                        ],
                    ];
                });

            $transactions = $user->transactions()
                ->with('plan:id,name')
                ->latest()
                ->limit(10)
                ->get(['id', 'plan_id', 'amount', 'currency', 'provider', 'status', 'created_at'])
                ->map(function ($tx) {
                    return [
                        'type' => 'payment',
                        'title' => "Payment {$tx->status}",
                        'description' => ($tx->plan?->name ?: 'Plan purchase') . " via {$tx->provider}",
                        'created_at' => $tx->created_at,
                        'meta' => [
                            'amount' => $tx->amount,
                            'currency' => $tx->currency,
                        ],
                    ];
                });

            $notifications = DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'data', 'read_at', 'created_at'])
                ->map(function ($n) {
                    $data = json_decode($n->data ?? '{}', true);
                    return [
                        'type' => 'notification',
                        'title' => $data['title'] ?? 'Notification',
                        'description' => $data['message'] ?? 'User notification triggered',
                        'created_at' => $n->created_at,
                        'meta' => [
                            'read' => !empty($n->read_at),
                        ],
                    ];
                });

            $timeline = collect()
                ->concat($jobs)
                ->concat($credits)
                ->concat($transactions)
                ->concat($notifications)
                ->sortByDesc(function ($item) {
                    return Carbon::parse($item['created_at'])->timestamp;
                })
                ->take(30)
                ->values();

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'summary' => [
                    'jobs_count' => $user->jobApplications()->count(),
                    'credit_logs_count' => $user->creditLogs()->count(),
                    'transactions_count' => $user->transactions()->count(),
                    'notifications_count' => DB::table('notifications')
                        ->where('notifiable_type', User::class)
                        ->where('notifiable_id', $user->id)
                        ->count(),
                ],
                'timeline' => $timeline,
            ]);
        }, 'AdminController@userActivity');
    }
}
