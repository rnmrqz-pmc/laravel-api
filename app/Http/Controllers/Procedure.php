<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Helpers\CustomHelper;


class Procedure extends Controller{
    private $isDev = false;

    public function __construct(Request $request){
        $apiKey = $request->header('x-api-key');   
        if($apiKey == env('DEV_KEY')){ $this->isDev = true; }
    }

    public function callProcedure(Request $request)
    {
        $payload = $this->isDev ? $request->all() : CustomHelper::decryptPayload($request->all());
        $procName = $payload['procName'];
        $dataParams = $payload['dataParams']; 
        $allowedProcNames = [
            'get_trainerCompleted',
            'get_kpi',
            'get_availability',
        ];
        if (!in_array($procName, $allowedProcNames)) {
            abort(400, 'Invalid procedure name');
        }

        if(!is_array($dataParams)){
            abort(400, 'Invalid data params');
        }
        try {
            $results = DB::select('CALL '.$procName.'(' . implode(',', array_fill(0, count($dataParams), '?')) . ')', $dataParams);
            return response()->json([
                'status' => true,
                'data' => $this->isDev ? $results : CustomHelper::encryptPayload($results)
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => false,
                'error' => $th->getMessage()
            ], 400);
        }
    }
    
    
    
    
    

}
