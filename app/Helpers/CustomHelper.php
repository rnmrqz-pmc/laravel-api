<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomHelper
{
    /**
     * Format phone number to international format
     * Example: 09171234567 → +639171234567
     */
    public static function formatPhone($phone)
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (substr($phone, 0, 1) === '0') {
            return '+63' . substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Decrypt an encrypted payload
     * 
     * @param array $encrypted Array with 'iv', 'data', 'mac' keys
     * @return array Decrypted data
     * @throws \Exception
     */
    public static function decryptPayload($encrypted)
    {
        $key = self::getEncryptionKey();

        // Extract components
        $ivB64 = $encrypted['iv'] ?? null;
        $dataB64 = $encrypted['data'] ?? null;
        $macB64 = $encrypted['mac'] ?? null;

        if (!$ivB64 || !$dataB64 || !$macB64) {
            throw new \Exception("Invalid encrypted payload");
        }

        // Decode base64
        $iv = base64_decode($ivB64, true);
        $ct = base64_decode($dataB64, true);
        $mac = base64_decode($macB64, true);

        if ($iv === false || $ct === false || $mac === false || strlen($iv) !== 16) {
            throw new \Exception("Invalid encrypted payload");
        }

        // Verify HMAC
        $calcMac = hash_hmac('sha256', $iv . $ct, $key, true);
        if (!hash_equals($calcMac, $mac)) {
            throw new \Exception("Invalid encrypted payload");
        }

        // Decrypt
        $decrypted = openssl_decrypt(
            $ct,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new \Exception("Invalid encrypted payload");
        }

        $json = json_decode($decrypted, true);
        if (!is_array($json)) {
            throw new \Exception("Invalid encrypted payload");
        }

        return $json;
    }

    /**
     * Encrypt a payload
     * 
     * @param array $data Data to encrypt
     * @return array Array with 'iv', 'data', 'mac' keys
     * @throws \Exception
     */
    public static function encryptPayload($data)
    {
        $key = self::getEncryptionKey();

        $iv = random_bytes(16);
        $plaintext = json_encode($data);

        $ciphertext = openssl_encrypt(
            $plaintext,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new \Exception("Encryption failed");
        }

        // HMAC for integrity verification
        $mac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        return [
            'iv' => base64_encode($iv),
            'data' => base64_encode($ciphertext),
            'mac' => base64_encode($mac),
        ];
    }

    /**
     * Create an audit trail entry
     * 
     * @param string $table Table name
     * @param mixed $recordId Record ID
     * @param string $operation Operation type (insert, update, delete)
     * @param array|null $oldValues Old values (for updates)
     * @param array $newValues New values
     * @param Request $request HTTP request object
     * @return void
     */
    public static function createAuditTrail(
        string $table,
        $recordId,
        string $operation,
        ?array $oldValues,
        array $newValues,
        Request $request
    ) {
        try {
            // Decrypt payload if encrypted
            $payload = self::getRequestPayload($request);

            $auditData = [
                'table_name' => $table,
                'record_id' => $recordId,
                'operation' => $operation,
                'old_values' => $oldValues ? json_encode($oldValues) : null,
                'new_values' => json_encode($newValues),
                'user_id' => self::getAuthenticatedUserId($payload),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'api_key_used' => $request->header('x-api-key') ? 'yes' : 'no',
                'created_at' => now(),
            ];

            // Track only changed fields for updates
            if ($operation === 'update' && $oldValues) {
                $changedFields = [];
                foreach ($newValues as $key => $value) {
                    if (isset($oldValues[$key]) && $oldValues[$key] != $value) {
                        $changedFields[$key] = [
                            'old' => $oldValues[$key],
                            'new' => $value,
                        ];
                    }
                }
                $auditData['changed_fields'] = json_encode($changedFields);
            }

            DB::table('audit_logs')->insert($auditData);

        } catch (\Exception $e) {
            Log::error("Failed to create audit trail: " . $e->getMessage(), [
                'table' => $table,
                'record_id' => $recordId,
                'operation' => $operation,
            ]);
        }
    }

    /**
     * Get the encryption key with validation
     * 
     * @return string
     * @throws \Exception
     */
    private static function getEncryptionKey()
    {
        $key = env('API_SECRET_KEY');

        if (!$key) {
            throw new \Exception("Encryption key not configured");
        }

        // Support both raw binary (32 bytes) and hex-encoded (64 chars)
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            $key = hex2bin($key);
        }

        if (strlen($key) !== 32) {
            throw new \Exception("Encryption key must be 32 bytes");
        }

        return $key;
    }

    /**
     * Get the request payload (decrypt if needed)
     * 
     * @param Request $request
     * @return array
     */
    private static function getRequestPayload(Request $request)
    {
        $data = $request->all();

        // Check if data is encrypted (has iv, data, mac keys)
        if (isset($data['iv']) && isset($data['data']) && isset($data['mac'])) {
            try {
                return self::decryptPayload($data);
            } catch (\Exception $e) {
                // If decryption fails, assume it's plain data
                Log::warning("Failed to decrypt request payload: " . $e->getMessage());
                return $data;
            }
        }

        return $data;
    }

    /**
     * Get authenticated user ID
     * Prioritizes Laravel's auth system, falls back to payload
     * 
     * @param array $payload
     * @return int|null
     */
    private static function getAuthenticatedUserId(array $payload)
    {
        // First priority: Laravel authentication
        if (auth()->check()) {
            return auth()->id();
        }

        // Second priority: Payload user_id (for API authentication)
        if (isset($payload['user_id']) && is_numeric($payload['user_id'])) {
            return (int) $payload['user_id'];
        }

        return null;
    }
}