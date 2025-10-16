<?php


namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\User; 
use App\Helpers\CustomHelper;
use App\Models\TwoFactorModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class Auth2FA extends Controller{
    private $isDev;
    public function __construct(Request $request){
        $apiKey = $request->header('x-api-key');   
        if($apiKey == env('DEV_KEY')){ $this->isDev = true; }
    }

    function status(Request $request){

            $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());
            $result = TwoFactorModel::where('userID', $payload['userID'])->first();
            $user = User::where('ID', $payload['userID'])->first();
            if($user['with_2fa'] == 0){
                $res = [
                    'status' => true,
                ];
                return response()->json($res);
            }
            if($result){
                // if($request->ip() != $result['USER_IP']){
                    if($payload['UUID'] != $result['uuid'] || $payload['USER_AGENT'] != $result['user_agent']){
                        $this->genOtp($user);                        
                        $res = [
                            'status' => false,
                            'data' => $this->isDev ? $result->toArray() : CustomHelper::encryptPayload($result->toArray())
                        ];
                        return response()->json($res);
                    }else{
                        $result->USER_IP = $request->ip();
                        $result->save();
                        $res = [
                            'status' => true,
                            'data' => $this->isDev ? $result->toArray() : CustomHelper::encryptPayload($result->toArray())
                        ];
                        return response()->json($res);
                    }

                // }else{
                //     $res = [
                //         'status' => true,
                //         'data' => $this->isDev ? $result->toArray() : CustomHelper::encryptPayload($result->toArray())
                //     ];
                //     return response()->json($res);
                // }
            }else{
                $twoFactor = new TwoFactorModel();
                $twoFactor->userID = $payload['userID'];
                // $twoFactor->UUID = $payload['UUID'];
                // $twoFactor->USER_IP = $request->ip();
                // $twoFactor->USER_AGENT = $payload['USER_AGENT'];
                $twoFactor->created_on = date('Y-m-d H:i:s');
                $twoFactor->created_by = 'AUTO';
                $twoFactor->save();
                $this->genOtp($user , $request);     
                $result = [
                    'status' => false,
                    'message' => "Two factor initiated",
                ];
                return response()->json($result);
            }
    }
    function verify(Request $request){
        $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());

        $result = TwoFactorModel::where('userID', $payload['userID'])->first();

        if(password_verify($payload['otp'], $result->two_factor_secret)){
            $tokenTime = strtotime($result->token_timestamp);
            $currentTime = time();
            $timeDiff = ($currentTime - $tokenTime) / 60; // difference in minutes
            
            if($timeDiff > 10){ // 10 minutes
                $res = [
                    'status' => false,
                    'error' => "OTP expired",
                    'message' => "OTP has expired. Please request for a new one.",
                    // 'data' => $this->isDev ? $result->toArray() : CustomHelper::encryptPayload($result->toArray())
                ];
                return response()->json($res);
            }
            $result->UUID = $payload['UUID'];
            $result->USER_IP = $request->ip();
            $result->USER_AGENT = $payload['USER_AGENT'];
            // $result->two_factor_secret = ;
            $result->save();
            $res = [
                'status' => true,
                'data' => $this->isDev ? $result->toArray() : CustomHelper::encryptPayload($result->toArray())
            ];
            return response()->json($res);
        }else{
            $res = [
                'status' => false,
                'error' => "Invalid OTP",
                'message' => "Invalid OTP code. Please try again.",
                // 'data' => $this->isDev ? $result->toArray() : CustomHelper::encryptPayload($result->toArray())
            ];
            return response()->json($res);
        }
    }

    private function genOtp($payload){
        $result = TwoFactorModel::where('userID', $payload['ID'])->first();
        
        if($result){
            $otp = rand(100000, 999999);
            $hash = password_hash($otp, PASSWORD_BCRYPT);
            $result->two_factor_secret = $hash;
            $result->token_timestamp = date('Y-m-d H:i:s');
            $result->save();
            $receiver = env('APP_ENV') == 'production' ? $payload['email'] : env('DEV_EMAIL');
            $subject = 'One-Time Password (OTP) for Account Verification';
            $body = "<div class=' style='padding: 20px; margin: auto;'>
                        <img class=' height='60px' style=' margin: auto; display: block;'
                            src='https://res.cloudinary.com/dulsddsiu/image/upload/v1756881356/dark_over_white_bg_a0gc9y.png' alt='Basecamp Icon' />
                            <div style='margin: auto; max-width: 550px;
                            margin-top: 40px; padding: 20px 30px; border: 1px solid #e5e5e5; border-radius: 20px; background-color: white'>
                                <h2 style='text-align: center; margin-bottom: 20px;'>Verify it's you</h2>
                                <p>
                                    Your One-Time Password (OTP) is: 
                                </p>
                                <div style='display: flex; justify-content: center; '>
                                <b style='font-size: 26px; border: 1px solid #424242ff;
                                letter-spacing: 10px; background-color: #c6f590; padding: 15px; color: blue; margin: 20px 0px;'>"
                                . $otp .
                                "</b>
                                </div>
                                <p>
                                This code is valid for 10 minutes. Do not share this code with anyone.
                                </p>
                                <p>Questions? Contact us at <b>support@basecamp.com.ph</b></p>
                                <i>This is an automated message. Please do not reply. </i>
                            </div>
                        </div>";

               try {
                    Mail::send([], [], function ($message) use ($receiver, $subject, $body) {
                        $message->to($receiver)
                                ->subject($subject)
                                ->from(config('mail.from.address'), config('mail.from.name'))
                                ->html($body); 
                    });
                    return (['status' => true, 'message' => "Email sent to {$receiver} successfully"]);
                } catch (\Exception $e) {
                    return (['success' => false, 'error' => $e->getMessage()]);
                }
        }
    }


}