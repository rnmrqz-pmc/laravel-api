<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Helpers\CustomHelper;
use App\Models\User;

class MailController extends Controller
{
    private $isDev = false;
    public function __construct(Request $request){
        $apiKey = $request->header('x-api-key');   
        if($apiKey == env('DEV_KEY')){ $this->isDev = true; }
    }


    public function sendMail(Request $request){
        $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());

        $receiver = env('APP_ENV') == 'production' ? $payload['receiver'] : env('DEV_EMAIL');
        $subject = $payload['subject'];
        $body = $payload['body'];

        try {
            Mail::send([], [], function ($message) use ($receiver, $subject, $body) {
                $message->to($receiver)
                        ->subject($subject)
                        ->from(config('mail.from.address'), config('mail.from.name'))
                        ->html($body); 
            });
            return response()->json(['status' => true, 'message' => "Email sent to {$receiver} successfully"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function sendResetPasswordEmail(Request $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'success' => false, 
                'error' => 'User not found',
                'message' => "User with email {$request->email} not found"
            ]);
        }

        // $to = $request->input('email', 'user@example.com'); // fallback email
        $resetLink = $request->input('link', 'https://google.com');

        $htmlContent = '
            <style>
                a {
                    color: #3f86ff;
                    text-decoration: none;
                }
                a:hover {
                    text-decoration: underline;
                }
            </style>

            <p>Click the link below to reset your password.</p>
            <p><a href="' . $resetLink . '">' . $resetLink . '</a></p>
            <p>If the link doesn\'t work, copy and paste the link into your browser.</p>
        ';

        try {
            Mail::send([], [], function ($message) use ($to, $htmlContent) {
                $message->to($to)
                        ->subject('Password Reset Request')
                        ->from(config('mail.from.address'), config('mail.from.name'))
                        ->html($htmlContent); 
            });

            return response()->json(['success' => true, 'message' => "Email sent to {$to}"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }


    }

}
