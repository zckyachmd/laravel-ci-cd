<?php

namespace App\Http\Controllers\Video;

use App\Http\Controllers\Controller;
use App\Models\Config;
use App\Jobs\Webhook;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class VideoSystemController extends Controller
{
    /**
     * Webhook system for twitter endpoint
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Config  $config
     * @return \Illuminate\Http\JsonResponse
     */
    public function webhook(Request $request, Config $config): JsonResponse|abort
    {
        // Get the crc_token from the request
        $crcToken = $request->input('crc_token');

        // If crc_token is present, respond to the request
        if ($crcToken) {
            try {
                // Create or update webhook token to database
                $config->updateOrCreate(
                    ['name'  => 'TOKEN_WEBHOOK'],
                    ['value' => $crcToken]
                );

                // Create a sha256 hash of the token using your consumer secret as the key
                $signature  = hash_hmac('sha256', $crcToken, config('api.twitter.consumer_secret'), true);

                // Return the crc_token and the response_token in the body of the response
                return response()->json([
                    'response_token' => 'sha256=' . base64_encode($signature)
                ]);
            } catch (\Exception $e) {
                // Log error
                info($e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());

                // Return if action not handle properly
                return response()->json([
                    'status'    => false,
                    'message'   => env('APP_DEBUG') == ('true' || true) ? $e->getMessage() : 'Oopss! Something when wrong. Try again later!'
                ], $e->getCode() ?: Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        try {
            // Get webhook data from the request
            $webhookData = $request->getContent();

            if (!$webhookData) {
                throw new \Exception('Webhook data is empty!');
            }

            // Parse the webhook data
            $webhook = json_decode($webhookData);

            // Send data to check webhook worker
            dispatch(new Webhook($webhook));

            // Return 200 status code
            return response()->json([
                'status'    => true,
                'message'   => 'Success catch data!'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            // Log error
            info($e->getMessage() . ' on line ' . $e->getLine() . ' in ' . $e->getFile());

            // Return 404
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}
