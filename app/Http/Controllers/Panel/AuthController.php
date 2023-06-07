<?php

namespace App\Http\Controllers\Panel;

use App\Models\User;
use App\Models\Config;
use Illuminate\View\View;
use App\Models\CookieUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Abraham\TwitterOAuth\TwitterOAuth;

class AuthController extends Controller
{
    // Global variable for this class
    protected $consumerKey, $consumerSecret, $callbackUrl;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        // Twitter API Key
        $this->consumerKey      = config('api.twitter.consumer_key');
        $this->consumerSecret   = config('api.twitter.consumer_secret');
        $this->callbackUrl      = config('api.twitter.callback_url');
    }

    /**
     * Show the login page.
     *
     * @return \Illuminate\View\View
     */
    public function login(): View
    {
        return view('panel.login', [
            'title' => 'Login'
        ]);
    }

    /**
     * Connect to Twitter App.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function connect(): RedirectResponse
    {
        // Check if the Twitter API keys are set
        if (!$this->consumerKey || !$this->consumerSecret) {
            throw new \Exception('Twitter API keys are missing. Please check your .env file.');
        }

        // Check if the Twitter callback URL is set to the correct url
        if (!$this->callbackUrl || $this->callbackUrl != route('callback')) {
            throw new \Exception('Twitter callback URL is missing or not set to ' . route('callback') . '. Please check your .env file. ');
        }

        try {
            // Get request token
            $connection = new TwitterOAuth($this->consumerKey, $this->consumerSecret);
            $requestToken = $connection->oauth('oauth/request_token', ['oauth_callback' => route('callback')]);

            // Save token to session
            $userToken = [
                'oauth_token'           => $requestToken['oauth_token'],
                'oauth_token_secret'    => $requestToken['oauth_token_secret']
            ];

            // Return to Twitter App
            return redirect()->away($connection->url('oauth/authorize', ['oauth_token' => $userToken['oauth_token']]))->with('user_token', $userToken);
        } catch (\Exception $e) {
            // Return if action not handle properly
            $message = env('APP_DEBUG') == ('true' || true) ? $e->getMessage() : 'Could not connect to Twitter. Refresh the page or try again later!';

            // Log error
            info('Error:' . $message);

            return redirect()->route('login')->with('error', $message);
        }
    }

    /**
     * Callback from Twitter App.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \App\Models\User $user
     * @param  \App\Models\CookieUser $cookieUser
     * @param  \App\Models\Config $config
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback(Request $request, User $user, CookieUser $cookieUser, Config $config): RedirectResponse
    {
        try {
            // Get oauth token and verifier
            $oauthToken         = $request->input('oauth_token');
            $oauthVerifier      = $request->input('oauth_verifier');
            $accessToken        = $request->session()->get('user_token')['oauth_token'];
            $accessTokenSecret  = $request->session()->get('user_token')['oauth_token_secret'];

            // Check if request not denied
            if ($request->input('denied')) {
                throw new \Exception('Permission was denied. Please start over.');
            }

            // Check if oauth token is not expired
            if ($oauthToken && $accessToken !== $oauthToken) {
                throw new \Exception('The token has expired. Try again!');
            }

            // Get access token
            $connection     = new TwitterOAuth($this->consumerKey, $this->consumerSecret, $accessToken, $accessTokenSecret);
            $accessToken    = (object) $connection->oauth('oauth/access_token', ['oauth_verifier' => $oauthVerifier]);

            // Check verify credentials
            if ($connection->getLastHttpCode() != 200) {
                throw new \Exception('Could not connect to Twitter. Refresh the page or try again later!');
            }

            // Data user
            $data = [
                'user_id'               => $accessToken->user_id,
                'username'              => $accessToken->screen_name,
                'access_token'          => encrypt($accessToken->oauth_token),
                'access_token_secret'   => encrypt($accessToken->oauth_token_secret)
            ];

            // Start DB transaction
            DB::beginTransaction();

            // Get current mode
            $getCurrentMode = $config->where('name', 'MODE_WEBHOOK')->first()->value ?? '0';

            // Check is system in lock mode
            if ($getCurrentMode == ('1' || 1)) {
                // Get user data
                $getUserData = $user->where('user_id', $data['user_id'])->first();

                // Check if user not allowed to access this system
                if (!$getUserData) {
                    throw new \Exception('Your account is not allowed to access this system. Please contact the administrator.');
                }
            }

            // Save data user
            $user->updateOrCreate(['user_id' => $data['user_id']], $data);

            // Check if user id = config admin id
            if (config('web.author.twitter.id') !== $data['user_id']) {
                throw new \Exception('Your account is not allowed to access this system. Please contact the administrator.');
            }

            // Get user id
            $userId = $user->where('user_id', $data['user_id'])->first()->id;

            // Cookie value
            $cookieValue = encrypt($userId);

            // Save cookie user
            $cookieUser->updateOrCreate(['user_id' => $userId], [
                'user_id'       => $userId,
                'token'         => $cookieValue,
                'ip_address'    => $request->ip()
            ]);

            // Commit DB transaction
            DB::commit();

            // Set cookie
            $cookieTime = time() + (60 * 60 * 24 * 30); // 30 days
            $cookie     = cookie('token', $cookieValue, $cookieTime);

            // Redirect to dashboard
            return redirect()->route('dashboard')->withCookie($cookie)->with('success', 'Welcome back, ' . $data['username'] . '!');
        } catch (\Exception $e) {
            // Rollback DB transaction
            DB::rollBack();

            // Message error
            $message = env('APP_DEBUG') == ('true' || true) ? $e->getMessage() : 'Oopss! Something when wrong. Try again later!';

            // Log error
            info('Error:' . $message);

            // Redirect to login page
            return redirect()->route('login')->with('error', $message);
        }
    }

    /**
     * Logout from web.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \App\Models\CookieUser $cookieUser
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request, CookieUser $cookieUser): RedirectResponse
    {
        // Remove cookie on database
        $cookieUser->where('user_id', $request->session()->get('user')['id'])->delete();

        // Remove session
        $request->session()->forget('user');

        // Remove cookie
        cookie()->forget('token');

        // Redirect to login page
        return redirect()->route('login')->with('success', 'You have been logged out!');
    }
}
