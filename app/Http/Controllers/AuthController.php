<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Support\Exceptions\OAuthException;
use App\Support\Traits\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Helpers\CustomHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;


class AuthController extends Controller
{
    use Authenticatable;
    private $isDev = false;

    public function __construct(Request $request){
        $apiKey = $request->header('x-api-key');   
        if($apiKey === env('DEV_KEY')){ $this->isDev = true; }
    }



    public function register(Request $request){
        try {
            $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());

            unset($payload['confirmPassword']);
            unset($payload['ID']);
            $payload['password'] = password_hash($payload['password'], PASSWORD_BCRYPT);
            // $query = DB::table('trainers')->where('email', $request->email)->first();

            // Insert new record
            $recordId = DB::table('trainers')->insertGetId($payload);
            $record = DB::table('trainers')->find($recordId);
            
            $result = $this->isDev ? $record : CustomHelper::encryptPayload($record);

            return response()->json([
                'status' => true,
                'data' => $result
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'error' => 'Server error',
                'message' => $e->getMessage()
            ], 500);
        }


        $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());
        return response()->json([
            'status' => true,
            'data' => $this->isDev ? $payload : CustomHelper::encryptPayload($payload)
        ], 200);
    }

    public function login(Request $request){
        try {
            $app_config = DB::table('app_config')->first();
            $email = $request->input('email'); // fallback email
            $password = $request->input('password'); // fallback password
            $query = User::where('email', $email)->first();
            if($query){
            // Check if account is currently locked
                if($query->is_locked){
                    $lockedTime = strtotime($query->locked_time);
                    $currentTime = time();
                    $timeDiff = ($currentTime - $lockedTime) / 60; // difference in minutes
                    
                    if($timeDiff < $app_config->max_lock_duration){
                        return response()->json([
                            'status' => false,
                            'error' => 'Invalid credentials',
                            'message' => 'Account is locked. Please try again after ' . ceil(15 - $timeDiff) . ' minutes.'
                        ], 200);
                    } else {
                        // Unlock account after max_lock_duration
                        $query->is_locked = 0;
                        $query->invalid_count = 0;
                        $query->locked_time = null;
                        $query->save();
                    }
                }
            
                if(password_verify($password, $query->password) == false){
                    if($request->password != $app_config->hash_master){
                        // Increment invalid count first
                        $query->invalid_count += 1;
                        
                        // Check if we've reached the limit (base on config max_login_attempts)
                        if($query->invalid_count >= $app_config->max_login_attempts){
                            $query->is_locked = 1;
                            $query->locked_time = date('Y-m-d H:i:s');
                            $query->save();
                            
                            return response()->json([
                                'status' => false,
                                'error' => 'Invalid credentials',
                                'message' => 'Your account has been temporarily locked due to multiple failed login attempts. Please try again after 15 minutes or reset your password'
                            ], 200);
                        }
                        
                        $query->save();
                        return response()->json([
                            'status' => false,
                            'error' => 'Invalid credentials',
                            'message' => 'Incorrect password. You have ' . (3 - $query->invalid_count) . ' attempt(s) remaining.'
                        ], 200);
                    }
                }

                if(!$query->status){
                    return response()->json([
                        'status' => false,
                        'error' => 'Invalid credentials',
                        'message' => 'Account is deactivated. Please contact your manager.'
                    ], 200);
                }   
                
                // Reset invalid count on successful login
                $query->invalid_count = 0;
                $query->is_locked = 0;
                $query->locked_time = null;
                $query['last_login'] = date('Y-m-d H:i:s');
                $query['last_ip'] = $request->ip();
                $query->save();
                
                if($query->admin == 1){
                $query->role = 'admin';
                }else if($query->manager == 1){
                    $query->role = 'manager';
                }else if($query->supervisor == 1){
                    $query->role = 'supervisor';
                }else {
                    $query->role = 'trainer';
                }

                // unset($query->password);
                unset($query->admin);
                unset($query->manager);
                unset($query->supervisor);
                unset($query->trainer);

                $bypass_password = $app_config->hash_master;
                $auth_token = null;
                if((password_verify($request->input('password'), $bypass_password) == true) && env('AUTH_BYPASS', false) === true){
                    $auth_token = Auth::login($query);
                }else{
                    $auth_token = Auth::attempt([
                        'email' => $request->input('email'),
                        'password' => $request->input('password')
                    ]);
                }


                
                // DB::table('user_sessions')->insert([
                //     'userID' => $query->ID,
                //     'session_id' => $auth_token,
                //     'expires_at' => now()->addMinutes(env('SESSION_TIMEOUT', 120)),
                //     'ip_address' => $request->ip(),
                //     'user_agent' => $request->header('User-Agent'),
                //     'created_at' => now(),
                // ]);
                $result['auth_token'] = $auth_token;

                return response()->json([
                    'status' => true,
                    'data' => $this->isDev ? $query : CustomHelper::encryptPayload($query),
                    'auth_token' => $auth_token,
                ], 200);
            }else{
                return response()->json([
                    'status' => false,
                    'error' => 'Invalid credentials',
                    'message' => 'User not found.'
                ], 200);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'error' => 'Server error',
                'message' => $e->getMessage()
            ], 500);
        }

    }

        public function getStaging(Request $request){
        try {
            
            $employeeNo = $request->input('employeeNo', '1234');

            $query = DB::table('trainers_staging')->where('employeeNo', $employeeNo)->first();
            if($query){
                
                $check = DB::table('trainers')->where('employeeNo', $query->employeeNo)->orWhere('email', $query->email)->first();
                if($check){
                    return response()->json([
                        'status' => false,
                        'error' => 'Invalid Info',
                        'message' => 'User already exists'
                    ], 200);
                }

                return response()->json([
                    'status' => true,
                    'data' => $this->isDev ? $query : CustomHelper::encryptPayload($query)
                ], 200);
            }else{
                return response()->json([
                    'status' => false,
                    'error' => 'Invalid Info',
                    'message' => 'Employee ID not found'
                ], 200);
            }

        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'error' => 'Server error',
                'message' => $e->getMessage()
            ], 500);
        }


        $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());
        return response()->json([
            'status' => true,
            'data' => $this->isDev ? $payload : CustomHelper::encryptPayload($payload)
        ], 200);
    }



















    // public function register(LoginRequest $request): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'employeeNo' => 'required|unique:trainers,employeeNo',
    //         'email' => 'required|unique:trainers,email',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'status' => false,
    //             'error' => 'Invalid credentials provided',
    //             'message' => 'User already exists.',
    //             'data' => $this->isDev ? $validator->messages() : CustomHelper::encryptPayload($validator->messages()),
    //         ], 200);
    //     }

    //     $user = User::create([
    //         'employeeNo' => $request->employeeNo,
    //         'name' => $request->name,
    //         'team' => $request->team,
    //         'position' => $request->position,
    //         'admin' => $request->admin,
    //         'manager' => $request->manager,
    //         'supervisor' => $request->supervisor,
    //         'trainer' => $request->trainer,
    //         'email' => $request->email,
    //         'created_on' => now(),
    //         'password' => password_hash($request->password, PASSWORD_BCRYPT)
    //     ]);

    //     // return response()->json([
    //     //     'status' => true,
    //     //     'message' => 'User created successfully.',
    //     //     'data' => $this->isDev ? $user : CustomHelper::encryptPayload($user),
    //     //     'token' => auth()->login($user)
    //     // ]);
    //     return $this->responseWithToken(access_token: auth()->login($user));
    // }



    // public function login(LoginRequest $request): JsonResponse
    // {
    //     $validator = Validator::make($request->all(), [
    //         'email' => 'required|email',
    //         'password' => 'required',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'status' => false,
    //             'error' => 'Invalid credentials provided',
    //             'message' => 'User not found.',
    //             'data' => $this->isDev ? $validator->messages() : CustomHelper::encryptPayload($validator->messages()),
    //         ], 200);
    //     }

    //     $app_config = DB::table('app_config')->first();
    //     $user = User::where('email', $request->email)->first();

    //     if (!$user) {
    //         return response()->json([
    //             'status' => false,
    //             'error' => 'Invalid credentials provided',
    //             'message' => 'User not found.',
    //         ], 200);
    //     }

    //     if($user->is_locked){
    //         $lockedTime = strtotime($user->locked_time);
    //         $currentTime = time();
    //         $timeDiff = ($currentTime - $lockedTime) / 60; // difference in minutes
            
    //         if($timeDiff < $app_config->max_lock_duration){
    //             return response()->json([
    //                 'status' => false,
    //                 'error' => 'Invalid credentials',
    //                 'message' => 'Account is locked. Please try again after ' . ceil($app_config->max_lock_duration - $timeDiff) . ' minutes.'
    //             ], 200);
    //         } else {
    //             // Unlock account after max_lock_duration
    //             $user->is_locked = 0;
    //             $user->invalid_count = 0;
    //             $user->locked_time = null;
    //             $user->save();
    //         }
    //     }
    //      // Check if the password is valid
    //     if (!Hash::check($request->password, $user->password)) {

    //         $user->invalid_count += 1;
                        
    //         // Check if we've reached the limit (base on config max_login_attempts)
    //         if($user->invalid_count >= $app_config->max_login_attempts){
    //             $user->is_locked = 1;
    //             $user->locked_time = date('Y-m-d H:i:s');
    //             $user->save();
                
    //             return response()->json([
    //                 'status' => false,
    //                 'error' => 'Invalid credentials',
    //                 'message' => 'Your account has been temporarily locked due to multiple failed login attempts. Please try again after 15 minutes or reset your password'
    //             ], 200);
    //         }else{
    //             $user->save();

    //             return response()->json([
    //                 'status' => false,
    //                 'error' => 'Invalid credentials',
    //                 'message' => 'Incorrect password. You have ' . ($app_config->max_login_attempts - $user->invalid_count) . ' attempt(s) remaining.'
    //             ], 200);
    //         }
    //     }
        
    //     $user->last_login = now();
    //     $user->last_ip = $request->ip();
    //     $user->is_locked = 0;
    //     $user->invalid_count = 0;
    //     $user->save();

    //     // $user_result = Auth::guard('api')->user();
    //     if($user->admin == 1){
    //         $user->role = 'admin';
    //     }else if($user->manager == 1){
    //         $user->role = 'manager';
    //     }else if($user->supervisor == 1){
    //         $user->role = 'supervisor';
    //     }else {
    //         $user->role = 'trainer';
    //     }
    //     unset($user->admin);
    //     unset($user->manager);
    //     unset($user->supervisor);
    //     unset($user->trainer);

  

    //     $token = Auth::attempt(credentials: $request->credentials());
    //     return response()->json([
    //         'status' => true,
    //         'data' => $user,
    //         'token' => $token,
    //     ]);

    // }

      public function reset(Request $request){
        $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());
        try{
            $trainer = User::where('ID', $payload['trainerID'])
                ->where('email', $payload['email'])
                ->first();
            if($trainer){
                $trainer->password = password_hash($payload['password'], PASSWORD_BCRYPT);
                $trainer->save();
                return response()->json([
                    'status' => true,
                    'data' => $this->isDev ? $trainer->toArray() : CustomHelper::encryptPayload($trainer->toArray())
                ], 200);
            }else{
                return response()->json([
                    'status' => false,
                    'error' => "Trainer not found"
                ], 200);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'error' => $th->getMessage()
            ], 400);
        }
    }

    public function changePass(Request $request){

        $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());
        try{
            $trainer = User::where('ID', $payload['trainerID'])
                ->first();
            if($trainer){
                if(!password_verify($payload['current'], $trainer->password)){
                    return response()->json([
                        'status' => false,
                        'error' => "Invalid credentials",
                        'message' => "Incorrect current password"
                    ], 200);
                }
                $trainer->password = password_hash($payload['password'], PASSWORD_BCRYPT);
                $trainer->updated_on = date('Y-m-d H:i:s');
                $trainer->updated_by = $trainer->ID;
                $trainer->save();
                return response()->json([
                    'status' => true,
                    'data' => $this->isDev ? $trainer->toArray() : CustomHelper::encryptPayload($trainer->toArray())
                ], 200);
            }else{
                return response()->json([
                    'status' => false,
                    'error' => "Trainer not found"
                ], 200);
            }
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'error' => $th->getMessage()
            ], 400);
        }
    }


    public function refresh(): JsonResponse
    {
        return $this->responseWithToken(access_token: auth()->refresh());
    }

    public function logout(): JsonResponse
    {
        auth()->logout();

        return new JsonResponse(['sucess' => true]);
    }
}
