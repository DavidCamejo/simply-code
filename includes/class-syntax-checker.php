<?php

/**
 * Class to check PHP syntax before saving hook files
 */
class Simply_Syntax_Checker {
    
    /**
     * Check if PHP code has valid syntax
     *
     * @param string $code The PHP code to check
     * @return array ['valid' => bool, 'message' => string]
     */
    public static function check_php($code) {
        // Create a temporary file
        $temp_file = tempnam(sys_get_temp_dir(), 'sh_syntax_');
        file_put_contents($temp_file, $code);
        
        // Check syntax using PHP's built-in linter
        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg($temp_file) . " 2>&1", $output, $return_var);
        
        // Clean up
        unlink($temp_file);
        
        // Process result
        if ($return_var !== 0) {
            $error_msg = implode("\n", $output);
            // Clean up the error message to remove the temp filename
            $error_msg = preg_replace('/in ' . preg_quote($temp_file, '/') . ' on/', 'on', $error_msg);
            return [
                'valid' => false,
                'message' => $error_msg
            ];
        }
        
        return [
            'valid' => true,
            'message' => 'Syntax is valid'
        ];
    }
    
    /**
     * Validate a hook before saving
     *
     * @param string $php_code The PHP code to validate
     * @return array ['valid' => bool, 'message' => string]
     */
    public static function validate_php($php_code) {
        // Check if PHP code is empty or only whitespace
        if (empty(trim($php_code))) {
            return [
                'valid' => true,
                'message' => 'Empty PHP code is valid'
            ];
        }
        
        // If code doesn't start with <?php, add it for validation purposes
        if (strpos(trim($php_code), '<?php') !== 0) {
            $php_code = "<?php\n" . $php_code;
        }
        
        return self::check_php($php_code);
    }
}
