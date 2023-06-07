<?php

namespace App\Http\Controllers\Video;

use App\Models\Video;
use App\Models\Config;
use App\Helpers\Twitter;
use App\Services\CredentialService;
use App\Models\DownloadLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VideoController extends Controller
{
    /**
     * Get videos from database by username
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request, Video $videoModel): JsonResponse
    {
        // Check if request is ajax
        if (!$request->ajax()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Get request data
        $search     = $request->input('search') ?? $request->session()->get('search') ?? '';
        $retrieve   = [];

        try {
            // Validate request
            $validate = Validator::make(['search' => str_replace([' ', '\t', '\n', '\r'], '', $search)], [
                'search' => 'required|string|max:100|not_regex:/^(' . preg_quote('@' . config('web.author.twitter.username'), '/') . ')$/i'
            ]);

            // Return error if validation failed
            if ($validate->fails()) {
                throw new \Exception('Search is required and must be less than 100 characters!', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            // Check if search is username
            if (preg_match('/^@/', $search)) {
                // Remove @ from search
                $search = str_replace('@', '', $search);

                // Create where condition
                $retrieve = [
                    ['users.username', '=', $search],
                    ['videos.source', '=', $search]
                ];
            } else {
                // Get video id from url
                $search = parse_url($search, PHP_URL_PATH);
                $search = explode('/', $search);
                $search = end($search);
                $search = intval($search);

                // Create where condition
                $retrieve = [
                    ['videos.permalink', '=', $search],
                    ['videos.tweet_id', '=', $search]
                ];
            }

            // Check video is available
            $checkVideo = $videoModel
                ->join('users', 'users.id', '=', 'videos.user_id')
                ->where('users.username', '=', $search)
                ->orWhere('videos.permalink', '=', $search)
                ->orWhere('videos.source', '=', $search)
                ->orWhere('videos.tweet_id', '=', $search)
                ->exists();

            // Check if video is not available
            if (!$checkVideo && is_int($search)) {
                // Get random admin or staff from database
                $credential = CredentialService::get(true);

                // Get video from twitter
                Twitter::saveVideo($credential['connection'], $search);
            }

            // Check if session retrieve is not empty
            if ($request->session()->has('retrieve')) {
                // Merge session retrieve to where condition
                $retrieve = array_merge($retrieve, $request->session()->get('retrieve'));
            }

            // Check if data retrieve is not empty
            if (!$retrieve) {
                throw new \Exception('Oops! Something went wrong. Please try again later!', Response::HTTP_NOT_FOUND);
            }

            // Get videos pagination from Database
            $getVideo = $videoModel->getVideoList($retrieve, $request->input('page') ?: 1);

            // Check if video is not available
            if ((!$getVideo || $getVideo->count() <= 0)) {
                throw new \Exception('Video not found! Make sure you\'re using the correct username or video url!', Response::HTTP_NOT_FOUND);
            }

            // Check if new video found
            if ($request->session()->has('videos') && count($request->session()->get('videos')) === $getVideo->count()) {
                throw new \Exception('No new videos found. That\'s all I\'ve!', Response::HTTP_NOT_FOUND);
            }

            // Save temp data to session
            $request->session()->put('search', $search);
            $request->session()->put('page', $getVideo->currentPage());
            $request->session()->put('retrieve', $retrieve);
            $request->session()->put('videos', $getVideo);

            // Return video data
            return response()->json([
                'message'   => 'Video successfully retrieved!',
                'data'      => $getVideo
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            // Log the error
            info($e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());

            // Return if action not handle properly
            return response()->json([
                'message' => $e->getMessage() ?: 'Video not found!'
            ], $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get videos from database
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \App\Models\Video $video
     * @param  string $username
     * @param  string $permalink
     * @return \Illuminate\Http\RedirectResponse
     */
    public function retrieve(Request $request, Video $video, $username, $permalink): RedirectResponse
    {
        // Check video is available
        $checkVideo = $video
            ->join('users', 'users.id', '=', 'videos.user_id')
            ->where([
                ['videos.permalink', '=', $permalink],
                ['users.username', '=', $username]
            ])
            ->exists();

        // Check if video is not available
        if ($checkVideo) {
            // Create where condition
            $retrieve = [
                ['videos.permalink', '=', $permalink]
            ];

            // Check if session retrieve is not empty
            if ($request->session()->has('retrieve')) {
                // Merge session retrieve to where condition
                $retrieve = array_merge($retrieve, $request->session()->get('retrieve'));
            }

            // Get videos pagination from Database
            $getVideo = $video->getVideoList($retrieve);

            // Check if videos is more than 1
            if ($getVideo->count() >= 1) {
                // Save temp data to session
                $request->session()->put('retrieve', $retrieve);
                $request->session()->put('videos', $getVideo);

                // Return videos data if available
                return redirect()->to('/#videos');
            }
        }

        // Return error if videos not available
        return redirect()->to('/')->with('error', 'Video not found!');
    }

    /**
     * Get video from database by username and permalink
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \App\Models\Video $video
     * @param  string $permalink
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function video(Request $request, Video $video, $permalink): View|RedirectResponse
    {
        // Get video from database
        $getVideo = $video->where('permalink', $permalink)->first();

        if (!$getVideo) {
            // Return not found
            return redirect()->to('/')->with('error', 'No videos found!');
        }

        try {
            // Check video is available
            $checkVideo = Http::get($getVideo->url);

            // Check if video is not available
            if ($checkVideo->status() != 200) {
                // Delete video from database
                $getVideo->delete();

                // Retrieve video from database
                $videos = $video->getVideoList($request->session()->get('retrieve'), $request->session()->get('page') ?: 1);

                // Save temp data to session
                $request->session()->put('videos', $videos);

                // Return not found
                return redirect()->to('/')->with('error', 'No videos found!');
            }
        } catch (\Exception $e) {
            // Log the error
            info($e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());

            // Create message
            $message = env('APP_DEBUG') == ('true' || true) ? $e->getMessage() : 'Video not found';

            // Return if action not handle properly
            return redirect()->to('/')->with('error', $message);
        }

        // Return videos data if available and video is available
        return view('video', [
            'title'     => '@' . $getVideo->source,
            'video'     => $getVideo,
            'wait'      => Config::select('value')->where('name', 'DOWNLOAD_WAIT')->first()->value ?? 0,
        ]);
    }

    /**
     * Download video from storage by username and permalink
     *
     * @param  \App\Models\Video $video
     * @param  \App\Models\DownloadLog $downloadLog
     * @param  string $permalink
     * @return Symfony\Component\HttpFoundation\BinaryFileResponse|Illuminate\Http\RedirectResponse
     */
    public function download(Video $video, DownloadLog $downloadLog, string $permalink): BinaryFileResponse|RedirectResponse
    {
        // Get video from database
        $getVideo = $video->where('permalink', $permalink)->firstorFail();

        // Meta data video
        $fileVideo = $permalink . '.mp4';
        $nameVideo = config('web.config.title') . '_' . $fileVideo;

        try {
            // Check video is available
            $checkVideo = Http::get($getVideo->url);

            // Check if video is not available
            if ($checkVideo->status() != 200) {
                // Delete video from database
                $getVideo->delete();

                // Return not found
                throw new \Exception('Video not found');
            }

            // Check if video is not exist in storage
            if (!Storage::disk('public')->exists($fileVideo)) {
                // Save video to storage
                Storage::disk('public')->put($fileVideo, file_get_contents($getVideo->url));
            }

            // Check log download
            $checkLog = $downloadLog->where([
                ['user_id', '=', $getVideo->user_id],
                ['video_id', '=', $getVideo->id],
            ])->exists();

            // Save log download
            !$checkLog ? $downloadLog->create([
                'user_id' => $getVideo->user_id,
                'video_id' => $getVideo->id,
                'count' => 1,
            ]) : $downloadLog->where([
                ['user_id', '=', $getVideo->user_id],
                ['video_id', '=', $getVideo->id],
            ])->increment('count');

            // Get video from storage
            $getVideo = storage_path('app/public/' . $fileVideo);

            // Return video to download
            return response()->download($getVideo, $nameVideo, [
                'Content-Type' => 'video/mp4',
                'Accept-Ranges' => 'bytes',
                'Content-Length' => filesize($getVideo)
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            // Create message
            $message = env('APP_DEBUG') == ('true' || true) ? $e->getMessage() : 'Video not found';

            // Log the error
            info($e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());

            // Return if action not handle properly
            return redirect()->to('/')->with('error', $message);
        }
    }
}
