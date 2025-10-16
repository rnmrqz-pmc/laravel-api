<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Helpers\CustomHelper;


class DynamicDataController extends Controller
{
    private $isDev = false;
    public function __construct(Request $request){
        $apiKey = $request->header('x-api-key');   
        if($apiKey == env('DEV_KEY')){ $this->isDev = true; }
    }

    // this function will fetch data dynamically from any table with filtering, pagination, and count
    public function fetchData(Request $request, string $table): JsonResponse
    {
        try {
            // define allowed tables (whitelist approach for security)
            $allowedTables = config('dynamic.allowed_tables', []);
            // check if table is allowed
            if (!in_array($table, $allowedTables)) {      
                return response()->json([
                    'error' => 'Table not allowed',
                    'message' => "Table '{$table}' is not allowed"
                ], 403);
            }


            // Define default pagination parameters
            $limit = $request->input('limit', 10);
            $offset = $request->input('offset', 0);
            $page = $request->input('page', 1);
            // if page is provided, calculate limit and offset
            if ($request->has('page')) {               
                $offset = ($page - 1) * $limit;
            }
            $limit = max(1, min($limit, 100));
            $offset = max(0, $offset);


            // Build query and count query
            $query = DB::table($table);
            $countQuery = DB::table($table);


            // Apply filters (if any)
            $filters = $this->buildFilters($request, $table);
            if (!empty($filters)) {
                $query = $this->applyFilters($query, $filters);
                $countQuery = $this->applyFilters($countQuery, $filters);
            }


            // Get total count of filtered records
            $totalCount = $countQuery->count();


            // Apply sorting (if any)
            $orderBy = $request->input('order_by', 'id');
            $orderDirection = $request->input('order_direction', 'desc');

            // Split by comma
            $orderBy = is_array($orderBy) ? $orderBy : explode(',', $orderBy);
            $orderDirection = is_array($orderDirection) ? $orderDirection : explode(',', $orderDirection);

            foreach ($orderBy as $index => $column) {
                $column = trim($column); // Remove whitespace
                $direction = trim($orderDirection[$index] ?? 'desc');
                $direction = in_array(strtolower($direction), ['asc', 'desc']) 
                    ? strtolower($direction) 
                    : 'desc';
                if (Schema::hasColumn($table, $column)) {
                    $query->orderBy($column, $direction);
                }
            }

            $data = $query->limit($limit)->offset($offset)->get();


            // Calculate pagination metadata
            $totalPages = ceil($totalCount / $limit);
            $currentPage = floor($offset / $limit) + 1;
            // $hasMore = $offset + $limit < $totalCount;

            return response()->json([
                'status' => true,
                'data' => $this->isDev == true ? $data : CustomHelper::encryptPayload($data->toArray()),
                'meta' => [
                    'total_count' => $totalCount,
                    'current_page' => $currentPage,
                    'per_page' => $limit,
                    'total_pages' => $totalPages,
                    'from' => $offset + 1,
                    'to' => min($offset + $limit, $totalCount),
                    // 'has_more' => $hasMore,
                    // 'filters_applied' => !empty($filters)
                ],
                // 'filters' => $filters
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => 'Server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function getTrainer(Request $request){
        try {
            
            $query = DB::table('trainers');
            $filters = $this->buildFilters($request, 'trainers');
            if (!empty($filters)) {
                $query = $this->applyFilters($query, $filters);
            }
            if($filters){
                $query = $this->applyFilters($query, $filters);
            }

            $trainers = $query->get();

            if($trainers){
                return response()->json([
                    'status' => true,
                    'data' => $this->isDev ? $trainers : CustomHelper::encryptPayload($trainers->toArray())
                ],200);
            }else{
                return response()->json([
                    'status' => false,
                    'error' => "No data found"
                ],400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => 'Server error',
                'message' => $e->getMessage()
            ]);
        }
    }


 
    private function getTableColumnInfo(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        $columnInfo = [];

        foreach ($columns as $column) {
            $columnType = Schema::getColumnType($table, $column);
            $columnInfo[] = [
                'name' => $column,
                'type' => $columnType
            ];
        }

        return $columnInfo;
    }


    // this function will build filters from request parameters
    private function buildFilters(Request $request, string $table): array
    {
        $filters = [];
        $tableColumns = Schema::getColumnListing($table);

        // Basic field filters (exact match)
        foreach ($tableColumns as $column) {
            if ($request->has($column) && $request->input($column) !== null) {
                $filters[] = [
                    'field' => $column,
                    'operator' => '=',
                    'value' => $request->input($column)
                ];
            }
        }

        // Search functionality
        if ($request->has('search') && !empty($request->input('search'))) {
            $searchValue = $request->input('search');
            $searchFields = $request->input('search_fields', $tableColumns);
            
            $searchFilters = [];
            foreach ($searchFields as $field) {
                if (in_array($field, $tableColumns)) {
                    $searchFilters[] = [
                        'field' => $field,
                        'operator' => 'like',
                        'value' => "%{$searchValue}%"
                    ];
                }
            }
            
            if (!empty($searchFilters)) {
                $filters[] = [
                    'type' => 'or_group',
                    'conditions' => $searchFilters
                ];
            }
        }

        $operators = [
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like',
            'in' => 'in',
            'not_in' => 'not_in'
        ];
        foreach ($tableColumns as $column) {
            foreach ($operators as $operator => $dbOperator) {
                $inputName = $column . '_' . $operator ;
                if ($request->has($inputName) && !empty($request->input($inputName))) {
                    $value = $request->input($inputName);
                    $filters[] = [
                        'field' => $column,
                        'operator' => $dbOperator,
                        'value' => $value
                    ];
                }
            }
        }

        // FIND_IN_SET filters
        foreach ($tableColumns as $column) {
            if ($request->has($column . '_find_in_set') && !empty($request->input($column . '_find_in_set'))) {
                $value = $request->input($column . '_find_in_set');
                $filters[] = [
                    'field' => $column,
                    'operator' => 'find_in_set',
                    'value' => $value
                ];
            }
        }

         // FIND_IN_SET with multiple values (OR condition)
        foreach ($tableColumns as $column) {
            if ($request->has($column . '_find_in_set_any') && !empty($request->input($column . '_find_in_set_any'))) {
                $values = is_array($request->input($column . '_find_in_set_any')) 
                    ? $request->input($column . '_find_in_set_any')
                    : explode(',', $request->input($column . '_find_in_set_any'));
                
                $findInSetConditions = [];
                foreach ($values as $value) {
                    $value = trim($value);
                    if (!empty($value)) {
                        $findInSetConditions[] = [
                            'field' => $column,
                            'operator' => 'find_in_set',
                            'value' => $value
                        ];
                    }
                }
                
                if (!empty($findInSetConditions)) {
                    $filters[] = [
                        'type' => 'or_group',
                        'conditions' => $findInSetConditions
                    ];
                }
            }
        }

        // GROUP BY support
        if ($request->has('group_by') && !empty($request->input('group_by'))) {
            $groupByColumns = is_array($request->input('group_by'))
                ? $request->input('group_by')
                : explode(',', $request->input('group_by'));

            foreach ($groupByColumns as $col) {
                $col = trim($col);
                if (in_array($col, $tableColumns)) {
                    $filters[] = [
                        'operator' => 'group_by',
                        'value' => $col
                    ];
                }
            }
        }

        return $filters;
    }


    // this function will apply filters to query builder
    private function applyFilters($query, array $filters)
    {
        foreach ($filters as $filter) {
            if (isset($filter['type']) && $filter['type'] === 'or_group') {
                $query->where(function ($q) use ($filter) {
                    foreach ($filter['conditions'] as $condition) {
                        if ($condition['operator'] === 'find_in_set') {
                            $q->orWhereRaw("FIND_IN_SET(?, {$condition['field']})", [$condition['value']]);
                        } else {
                            $q->orWhere($condition['field'], $condition['operator'], $condition['value']);
                        }
                    }
                });
            } else {
                switch ($filter['operator']) {
                    case 'like':
                        $query->where($filter['field'], 'like', $filter['value']);
                        break;
                    case 'find_in_set':
                        $query->whereRaw("FIND_IN_SET(?, {$filter['field']})", [$filter['value']]);
                        break;
                    case 'in':
                            $values = explode(',', $filter['value']);
                            $query->whereIn($filter['field'], $values);
                        break;
                    case 'not_in':
                        if (is_array($filter['value'])) {
                            $query->whereNotIn($filter['field'], $filter['value']);
                        }
                        break;
                    case 'group_by':
                        $query->groupBy($filter['value']);
                        break;
                    default:
                        $query->where($filter['field'], $filter['operator'], $filter['value']);
                        break;
                }
            }
        }

        return $query;
    }


    public function getTableInfo(string $table): JsonResponse
    {
        try {
            if (!Schema::hasTable($table)) {
                return response()->json([
                    'error' => 'Table not found',
                    'message' => "Table '{$table}' does not exist"
                ], 404);
            }

            $columnDetails = $this->getTableColumnInfo($table);

            return response()->json([
                'success' => true,
                'table' => $table,
                'columns' => $columnDetails,
                'total_columns' => count($columnDetails),
                'api_endpoints' => [
                    'fetch' => "GET /api/data/{$table}",
                    'insert' => "POST /api/data/{$table}",
                    'update' => "PUT /api/data/{$table}/{id}",
                    'upsert' => "POST /api/data/{$table}/upsert",
                    'delete' => "DELETE /api/data/{$table}/{id}",
                    'bulk_insert' => "POST /api/data/{$table}/bulk"
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}