<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Helpers\CustomHelper;
use Nette\Utils\Image;
use Illuminate\Support\Facades\Log;

class Upload extends Controller
{
    private $isDev = false;

    public function __construct(Request $request){
        $apiKey = $request->header('x-api-key');   
        if($apiKey == env('DEV_KEY')){ $this->isDev = true; }
    }

    
    public function imgUpload(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,gif,webp,svg|max:5120', // 5MB in KB
            'filePath' => 'nullable|string|max:255'
        ], [
            'image.max' => 'The image must not be larger than 5 MB.',
            'image.mimes' => 'The image must be a file of type: JPG, JPEG, PNG, GIF, WEBP, or SVG.',
        ]);

        $file = $request->file('image');
        
        // Generate secure filename
        $filename = now()->format('YmdHis') . '_' . Str::random(12) . '.' . $file->getClientOriginalExtension();
        $filePath = $request->input('filePath', 'images');
        
        // Sanitize file path to prevent directory traversal
        $filePath = str_replace(['..', '\\'], '', $filePath);
        
        try {
            // Use putFileAs for better performance
            $path = Storage::disk('uploads')->putFileAs(
                $filePath,
                $file,
                $filename
            );

            // Optional: Optimize image after upload
            $this->optimizeImage(Storage::disk('uploads')->path($path));

            $data = [
                'name' => $file->getClientOriginalName(),
                'path' => Storage::disk('uploads')->url($path),
                'filename' => $filename,
                // 'size' => $file->getSize(),
                // 'mime_type' => $file->getMimeType(),
                // 'dimensions' => $this->getImageDimensions($file)
            ];

            return response()->json([
                'status' => true,
                'data' => $this->isDev ? $data : CustomHelper::encryptPayload($data),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Image upload failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => false,
                'error' => 'Failed to upload image. Please try again.'
            ], 500);
        }
    }

    // Helper method to get image dimensions
    private function getImageDimensions($file)
    {
        try {
            if (in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                list($width, $height) = getimagesize($file->getRealPath());
                return ['width' => $width, 'height' => $height];
            }
        } catch (\Exception $e) {
            Log::warning('Could not get image dimensions: ' . $e->getMessage());
        }
        return null;
    }

    // Optional: Image optimization method using GD/Imagick (built-in PHP)
    private function optimizeImage($path)
    {
        try {
            $imageInfo = getimagesize($path);
            if (!$imageInfo) return;
            
            $mimeType = $imageInfo['mime'];
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            
            // Only optimize if image is too large
            if ($width <= 1920 && $height <= 1920) return;
            
            // Calculate new dimensions
            $ratio = min(1920 / $width, 1920 / $height);
            $newWidth = (int)($width * $ratio);
            $newHeight = (int)($height * $ratio);
            
            // Load image based on type
            switch ($mimeType) {
                case 'image/jpeg':
                    $source = imagecreatefromjpeg($path);
                    break;
                case 'image/png':
                    $source = imagecreatefrompng($path);
                    break;
                case 'image/gif':
                    $source = imagecreatefromgif($path);
                    break;
                case 'image/webp':
                    $source = imagecreatefromwebp($path);
                    break;
                default:
                    return;
            }
            
            // Create new image
            $destination = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG and GIF
            if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                imagealphablending($destination, false);
                imagesavealpha($destination, true);
            }
            
            // Resize
            imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            
            // Save optimized image
            switch ($mimeType) {
                case 'image/jpeg':
                    imagejpeg($destination, $path, 85);
                    break;
                case 'image/png':
                    imagepng($destination, $path, 8);
                    break;
                case 'image/gif':
                    imagegif($destination, $path);
                    break;
                case 'image/webp':
                    imagewebp($destination, $path, 85);
                    break;
            }
            
            // Free memory
            imagedestroy($source);
            imagedestroy($destination);
            
        } catch (\Exception $e) {
            Log::warning('Image optimization failed: ' . $e->getMessage());
        }
    }

    // Alternative: Upload with automatic WebP conversion using built-in GD
    public function imgUploadOptimized(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:5120',
            'filePath' => 'nullable|string|max:255',
            'convert_webp' => 'nullable|boolean'
        ]);

        $file = $request->file('image');
        $convertToWebp = $request->input('convert_webp', false);
        
        $filePath = str_replace(['..', '\\'], '', $request->input('filePath', 'images'));
        
        try {
            // Get original image info
            $imageInfo = getimagesize($file->getRealPath());
            $mimeType = $imageInfo['mime'];
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            
            // Load source image
            switch ($mimeType) {
                case 'image/jpeg':
                    $source = imagecreatefromjpeg($file->getRealPath());
                    break;
                case 'image/png':
                    $source = imagecreatefrompng($file->getRealPath());
                    break;
                case 'image/gif':
                    $source = imagecreatefromgif($file->getRealPath());
                    break;
                case 'image/webp':
                    $source = imagecreatefromwebp($file->getRealPath());
                    break;
                default:
                    throw new \Exception('Unsupported image type');
            }
            
            // Calculate new dimensions if image is too large
            $newWidth = $width;
            $newHeight = $height;
            
            if ($width > 1920 || $height > 1920) {
                $ratio = min(1920 / $width, 1920 / $height);
                $newWidth = (int)($width * $ratio);
                $newHeight = (int)($height * $ratio);
            }
            
            // Create optimized image
            $destination = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency
            if ($mimeType === 'image/png' || $mimeType === 'image/gif' || $convertToWebp) {
                imagealphablending($destination, false);
                imagesavealpha($destination, true);
            }
            
            // Resize
            imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            
            // Generate filename
            $extension = $convertToWebp ? 'webp' : $file->getClientOriginalExtension();
            $filename = now()->format('YmdHis') . '_' . Str::random(12) . '.' . $extension;
            
            // Create full path
            $fullPath = Storage::disk('uploads')->path($filePath);
            if (!file_exists($fullPath)) {
                mkdir($fullPath, 0755, true);
            }
            
            $destinationPath = $fullPath . '/' . $filename;
            
            // Save optimized image
            if ($convertToWebp) {
                imagewebp($destination, $destinationPath, 85);
                $finalMimeType = 'image/webp';
            } else {
                switch ($mimeType) {
                    case 'image/jpeg':
                        imagejpeg($destination, $destinationPath, 85);
                        $finalMimeType = 'image/jpeg';
                        break;
                    case 'image/png':
                        imagepng($destination, $destinationPath, 8);
                        $finalMimeType = 'image/png';
                        break;
                    case 'image/gif':
                        imagegif($destination, $destinationPath);
                        $finalMimeType = 'image/gif';
                        break;
                    case 'image/webp':
                        imagewebp($destination, $destinationPath, 85);
                        $finalMimeType = 'image/webp';
                        break;
                }
            }
            
            // Free memory
            imagedestroy($source);
            imagedestroy($destination);
            
            $storagePath = $filePath . '/' . $filename;
            
            $data = [
                'name' => $file->getClientOriginalName(),
                'path' => Storage::disk('uploads')->url($storagePath),
                'filename' => $filename,
                'size' => filesize($destinationPath),
                'mime_type' => $finalMimeType,
                'dimensions' => ['width' => $newWidth, 'height' => $newHeight],
                'optimized' => true
            ];

            return response()->json([
                'status' => true,
                'data' => CustomHelper::encryptPayload($data),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Image upload failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => false,
                'error' => 'Failed to upload image. Please try again.'
            ], 500);
        }
    }

    public function docUpload(Request $request)
    {
    $request->validate([
        'document' => 'required|file|mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt,rtf|max:15360'
    ], [
        'document.max' => 'The document must not be larger than 15 MB.',
        'document.mimes' => 'The document must be a file of type: PDF, DOC, DOCX, PPT, or PPTX.',
    ]);

    $file = $request->file('document');
    
    // Generate secure filename
    $filename = now()->format('YmdHis') . '_' . Str::random(12) . '.' . $file->getClientOriginalExtension();
    $filePath = $request->input('filePath', 'documents');
    
    // Sanitize file path to prevent directory traversal
    $filePath = str_replace(['..', '\\'], '', $filePath);
    
    try {
        // Use putFileAs for better performance (single operation)
        $path = Storage::disk('uploads')->putFileAs(
            $filePath,
            $file,
            $filename,
            'private' // using private visibility
        );

        return response()->json([
            'status' => true,
            // 'path' => Storage::disk('uploads')->url($path),
            'filename' => $filename,
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType()
        ]);
        
    } catch (\Exception $e) {
        Log::error('Document upload failed: ' . $e->getMessage());
        
        return response()->json([
            'status' => false,
            'error' => 'Failed to upload document. Please try again.'
        ], 500);
    }
}
}
