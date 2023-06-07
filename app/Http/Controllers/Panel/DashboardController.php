<?php

namespace App\Http\Controllers\Panel;

use App\Helpers\Custom;
use App\Models\User;
use App\Models\Video;
use Illuminate\View\View;
use App\Models\DownloadLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index(): View
    {
        return view('panel.dashboard', [
            'title' => 'Dashboard'
        ]);
    }

    /**
     * Get summary data.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function summary(): JsonResponse
    {
        $request    = Video::whereDate('created_at', date('Y-m-d'))->count();
        $downloads  = DownloadLog::sum('count');
        $videos     = Video::count();
        $users      = User::count();

        return response()->json([
            'request'   => $request ? Custom::formatNumberShorten($request) : 0,
            'downloads' => $downloads ? Custom::formatNumberShorten($downloads) : 0,
            'videos'    => $videos ? Custom::formatNumberShorten($videos) : 0,
            'users'     => $users ? Custom::formatNumberShorten($users) : 0
        ]);
    }
}
