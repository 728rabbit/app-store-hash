<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Config;

class IntegrityChecker {
    // Key files and directories to monitor
    private $_monitoredPaths = [
        'app/Helpers/',
        'app/Http/Controllers/',
        'app/Http/Middleware/',
        'app/Models/',
        'public/assets/css',
        'public/assets/js',
        'resources/views'
    ];
    
    // Remote hash storage URL (your server)
    private $_remoteHashUrl = 'https://app-store-hash.netlify.app/';
    
    private $_currentDomain = '';
    private $_newHash = '';

    // Check file integrity
    private function checkIntegrity($forceReport = false) {
        try {
            $this->_currentDomain = $currentDomain = Request::getHost();
            $currentDomain = trim(str_replace('.', '-', strtolower(preg_replace('/(www\.)/', '', $currentDomain))));
            $this->_remoteHashUrl.= md5($currentDomain).'.json';
            
            $hashes = $this->calculateFileHashes();
            $currentHash = hash('sha256', ($currentDomain.'#'.Config::get('app_portal.application_uid').'#'.$this->hashOfHashes($hashes)));
            
            // Obtain remote check code
            $lastHash = '';
            $content = $this->curlGet($this->_remoteHashUrl);
            if (!empty($content)) {
                $content = $this->plainText($content);
                $lastHashData = json_decode($content, true);
                if(!empty($lastHashData) && !empty($lastHashData['vcode'])) {
                    $lastHash = $lastHashData['vcode'];
                }
            }
            
            if (trim(strtolower($lastHash)) !== trim(strtolower($currentHash))) {
                $this->_newHash = $currentHash;
                return false;
            }
            
            return true;
            
        } catch (\Exception $e) {
            throw $e;
        }
    }
    
    // CURL GET request
    private function curlGet(string $url): string {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Integrity Checker)'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($httpCode >= 200 && $httpCode < 300) ? $response : '';
    }
    
    // Calculate the hash value of all monitoring files
    private function calculateFileHashes() {
        $hashes = [];
        
        foreach ($this->_monitoredPaths as $path) {
            $fullPath = base_path($path);
            
            if (is_file($fullPath)) {
                $content = $this->plainText(file_get_contents($fullPath));
                if(!empty($content)) {
                    $hashes[$path] = hash('sha256', base64_encode($content));
                }
            } elseif (is_dir($fullPath)) {
                $files = $this->scanDirectory($fullPath);
                foreach ($files as $file) {
                    $content = $this->plainText(file_get_contents($file));
                    if(!empty($content)) {
                        $relativePath = str_replace(base_path() . '/', '', $file);
                        $hashes[$relativePath] = hash('sha256', base64_encode($content));
                    }
                }
            }
        }
        
        return $hashes;
    }
    
    // Scan all files in the directory
    private function scanDirectory($directory) {
        $files = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $this->shouldMonitorFile($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    // Determine whether this file should be monitored.
    private function shouldMonitorFile($filePath) {
        // Only monitor PHP, CSS & JS files
        if (!in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['php', 'css', 'js'])) {
            return false;
        }
        
        // Exclude some files that do not need to be monitored
        $excluded = [
            'vendor/',
            'node_modules/',
            'storage/',
            'tests/',
            '.git/'
        ];
        
        foreach ($excluded as $exclude) {
            if (strpos($filePath, $exclude) !== false) {
                return false;
            }
        }
        
        return true;
    }
    
    // Generate a total hash from all hash values.
    private function hashOfHashes($hashes) {
        ksort($hashes); // Sorting to ensure consistency
        $combined = '';
        
        foreach ($hashes as $path => $hash) {
            $combined .= $path . ':' . $hash . ';';
        }
        
        return hash('sha256', $combined);
    }
    
    // Convert to plant text
    protected function plainText(string $value = '', bool $singleLine = true): string {
        if(!empty($value)) {
            // Remove HTML tags and process entities
            $value = (trim(str_replace('&nbsp;', ' ', $value)));
            
            // Optional: Convert to a single line (first convert all whitespace characters to spaces)
            if (!empty($singleLine)) {
                $value = preg_replace('/[\n\r\t]/', ' ', $value);
            }
            
            // Remove the second start of consecutive blanks
            $value = trim(preg_replace('/\s(?=\s)/', '', $value));
        }
        
        return $value;
    }

    // Regular inspection
    public function periodicCheck() {
        // Check every 12 hours
        $lastCheck = Cache::get('integrity_last_check_time', 0);
        $max_diff_secs = 3600 * 12;
        if (time() - $lastCheck > $max_diff_secs) {
            if(empty($this->checkIntegrity())) {
                Cache::put('integrity_last_check_time', time() - $max_diff_secs, now()->addDays(2));
                $copy = json_encode([
                    'appuid'        =>  Config::get('app_portal.application_uid'),
                    'domain'        =>  $this->_currentDomain,
                    'vcode'         =>  $this->_newHash,
                    'generated_at'  =>  date('Y-m-d H:i:s')
                ]);
                
                echo <<<HTML
                <!DOCTYPE html>
                <html lang="zh-Hant">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>系統完整性檢查 / System Integrity Check</title>
                    <style>*{font-family:Arial,sans-serif;padding:0;margin:0}body{background:#ffffe0;display:flex;flex-direction:column;justify-content:center;align-items:center;height:100vh;padding:20px}.container{background:#fff;max-width:680px;padding:2rem;border-radius:10px;text-align:center;box-shadow:0 0 10px rgba(0,0,0,.5);line-height:2}h1{font-size:1.5rem;margin-bottom:1rem}p{margin-bottom:.5rem}code{background:#20b2aa;padding:4px;border-radius:4px;color:#fff;word-break:break-all;}.error{color:#721c24;background:#f8d7da;padding:.5rem;border-radius:5px;margin-top:.5rem}</style>
                </head>
                <body>
                    <div class="container">
                        <h1>** 系統完整性檢查 **<br/>System Integrity Check</h1>
                HTML;
                    echo "<p class='error'>新的驗證代碼 New Verification Code:<br/><code>{$copy}</code></p>";
                    echo "<p>系統核心代碼變更，當前網域驗證代碼已失效，請聯絡開發者更新。<br/>The core system code has been changed, and the domain verification code is no longer valid.<br/>Please contact the developer to update it.</p>";
                    echo <<<HTML
                    </div>
                </body>
                </html>
                HTML;
                exit;
            }
            else {
                Cache::put('integrity_last_check_time', time(), now()->addDays(2));
            }
        }
    }
}