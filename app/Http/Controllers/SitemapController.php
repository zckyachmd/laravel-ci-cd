<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Write code on Method
     *
     * @return \Illuminate\View\View
     */
    public function __invoke(Video $video): Response
    {
        $videos = $video->select('tweet_id', 'source', 'url', 'permalink', 'thumbnail', 'created_at')->where('censor', 0)->orderBy('created_at', 'desc')->get();

        return response()->view('sitemap', compact('videos'))->header('Content-Type', 'text/xml');
    }
}
