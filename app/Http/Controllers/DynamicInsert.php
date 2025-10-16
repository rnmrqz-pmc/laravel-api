<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Helpers\CustomHelper;

class DynamicInsert extends Controller
{
    private $isDev = false;
    
    public function __construct(Request $request){
        $apiKey = $request->header('x-api-key');   
        if($apiKey == env('DEV_KEY')){ $this->isDev = true; }
    }

    public function upsertData(Request $payload, string $table): JsonResponse
    {
        try {
            $request = $this->isDev ? $payload->all() : CustomHelper::decryptPayload($payload->all());

            // define allowed tables (whitelist approach for security)
            $allowedTables = config('dynamic.allowed_tables', []);
            // check if table is allowed
            if (!in_array($table, $allowedTables)) {      
                return response()->json([
                    'status' => false,
                    'error' => 'Table not allowed',
                    'message' => "Table '{$table}' is not allowed"
                ], 403);
            }

            // Validate table exists
            if (!Schema::hasTable($table)) {
                return response()->json([
                    'status' => false,
                    'error' => 'Table not found',
                    'message' => "Table '{$table}' does not exist"
                ], 404);
            }

            // Debug: Let's see what columns actually exist
            $actualColumns = Schema::getColumnListing($table);

            // Get unique fields
            $uniqueFields = isset($request['unique_by']) ? json_decode($request['unique_by'], true) : ['ID'];

            // Check if unique fields exist in the table
            foreach ($uniqueFields as $field) {
                if (!in_array($field, $actualColumns)) {
                    return response()->json([
                        'status' => false,
                        'error' => 'Invalid unique field',
                        'message' => "Field '{$field}' does not exist in table '{$table}'. Available columns: " . implode(', ', $actualColumns)
                    ], 400);
                }
            }

            // Build where conditions for checking existing record
            $whereConditions = [];
            foreach ($uniqueFields as $field) {
                if ($request[$field] !== null && in_array($field, $actualColumns) && !empty($field)) {
                    $value = $request[$field] ?? null;
                    if ($value !== null) {
                        $whereConditions[$field] = $value;
                    }
                }
            }

            if (empty($whereConditions)) {
                $whereConditions['ID'] = null;
            }

            // Check if record exists
            $existingRecord = DB::table($table)->where($whereConditions)->first();

            // Store original data for audit trail
            $originalData = $existingRecord ? (array)$existingRecord : null;

            // Prepare data
            unset($request['unique_by']);
            $data = $request;
            
            // Remove null values and non-existent columns
            $cleanData = [];
            foreach ($data as $key => $value) {
                if (in_array($key, $actualColumns) && $value !== null) {
                    $cleanData[$key] = $value;
                }
            }

            if (empty($cleanData)) {
                return response()->json([
                    'status' => false,
                    'error' => 'No valid data provided'
                ], 400);
            }

            $operation = $existingRecord ? 'update' : 'insert';
            
            // Add timestamps if columns exist
            $now = now();
            if (in_array('updated_at', $actualColumns)) {
                $cleanData['updated_at'] = $now;
            }
            if ($operation === 'insert' && in_array('created_at', $actualColumns)) {
                $cleanData['created_at'] = $now;
            }

            DB::beginTransaction();

            if ($existingRecord) {
                // Update existing record
                DB::table($table)->where($whereConditions)->update($cleanData);
                $record = DB::table($table)->where($whereConditions)->first();
                
                // Get record ID
                $recordId = $this->getRecordId($record, $actualColumns);
                $message = 'Record updated successfully';
                
            } else {
                // Insert new record
                $recordId = DB::table($table)->insertGetId($cleanData);
                $record = DB::table($table)->find($recordId);
                
                // If find() doesn't work, try using where conditions
                if (!$record) {
                    $primaryKey = $this->findPrimaryKeyColumn($table, $actualColumns);
                    $record = DB::table($table)->where($primaryKey, $recordId)->first();
                }
                
                $message = 'Record created successfully';
            }

            // Create audit trail entry
            // CustomHelper::createAuditTrail($table, $recordId, $operation, $originalData, (array) $record, $payload, $this->isDev);

            DB::commit();

            return response()->json([
                'status' => true,
                'operation' => $operation,
                'data' => $this->isDev ? $record : CustomHelper::encryptPayload($record),
            ], $operation === 'insert' ? 201 : 200);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => false,
                'error' => 'Server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get record ID from record object
     */
    private function getRecordId($record, array $actualColumns)
    {
        if (!$record) return null;
        
        foreach ($actualColumns as $column) {
            if (in_array(strtolower($column), ['id', 'primary_id', 'pk_id']) || 
                $column === 'ID' || 
                stripos($column, 'id') !== false) {
                if (isset($record->$column)) {
                    return $record->$column;
                }
            }
        }
        
        return null;
    }

    private function findPrimaryKeyColumn(string $table, array $columns): string
    {
        // Check common primary key names in order of preference
        $primaryKeyNames = ['id', 'ID', 'pk_id', 'primary_id', $table . '_id'];
        
        foreach ($primaryKeyNames as $pkName) {
            if (in_array($pkName, $columns)) {
                return $pkName;
            }
        }
        
        // If none found, try to detect from database
        try {
            if (config('database.default') === 'mysql') {
                $result = DB::select("SHOW COLUMNS FROM `{$table}` WHERE `Key` = 'PRI'");
                if (!empty($result)) {
                    return $result[0]->Field;
                }
            }
        } catch (\Exception $e) {
            Log::warning("Could not detect primary key for table {$table}");
        }
        
        // Default fallback
        return 'ID';
    }
}