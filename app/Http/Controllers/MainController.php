<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\View\View;
use Illuminate\Http\Request;

class MainController extends Controller
{
    /**
     * Show the application dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Video  $video
     * @return \Illuminate\View\View
     */
    public function index(Request $request, Video $video): View
    {
        // Check if trending videos
        $getTrending = $video->getTrends();

        // Data to be passed to the view
        $data = [
            'videos' => $request->session()->get('videos') ?? null,
            'trends' => $getTrending->count() === 3 ? $getTrending : null
        ];

        // Return view
        return view('home', $data);
    }
}
