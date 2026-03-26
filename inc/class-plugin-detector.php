<?php

defined('ABSPATH') || exit;

class WP_Hook_Profiler_Plugin_Detector {
    
    private $plugins_cache = null;
    private $themes_cache = null;
    private $wp_core_path = null;

    /**
     * Resolved (real) path of WP_PLUGIN_DIR, used for symlink-safe comparisons.
     *
     * @var string
     */
    private $real_plugin_dir = null;

    /**
     * Resolved (real) path of WPMU_PLUGIN_DIR, used for symlink-safe comparisons.
     *
     * @var string
     */
    private $real_mu_plugin_dir = null;
    
    public function __construct() {
        $this->wp_core_path    = ABSPATH . 'wp-includes/';
        $this->real_plugin_dir = $this->normalize_path( WP_PLUGIN_DIR );
        // WPMU_PLUGIN_DIR may not be defined on non-multisite installs.
        $this->real_mu_plugin_dir = defined( 'WPMU_PLUGIN_DIR' )
            ? $this->normalize_path( WPMU_PLUGIN_DIR )
            : null;
    }

    /**
     * Normalize a filesystem path: resolve symlinks when possible, ensure no
     * trailing slash, and use forward slashes.
     *
     * @param string $path
     * @return string
     */
    private function normalize_path( $path ) {
        $real = realpath( $path );
        if ( $real !== false ) {
            return rtrim( str_replace( '\\', '/', $real ), '/' );
        }
        return rtrim( str_replace( '\\', '/', $path ), '/' );
    }
    
    public function identify_callback_source($callback) {
        $source_info = [
            'plugin'      => 'wordpress-core',
            'plugin_name' => 'WordPress Core',
            'plugin_file' => null,
            'file'        => null,
            'line'        => null
        ];
        
        try {
            $reflection = $this->get_callback_reflection($callback);
            
            if (!$reflection) {
                return $this->unknown_source();
            }
            
            $filename = $reflection->getFileName();
            $line     = $reflection->getStartLine();
            
            if (!$filename) {
                return $this->unknown_source();
            }

            // Normalize the filename so symlink-based paths compare correctly.
            $filename_normalized = $this->normalize_path( $filename );
            
            $source_info['file'] = $filename;
            $source_info['line'] = $line;

            // Check mu-plugins first so they are attributed to their own plugin
            // rather than falling through to the WordPress Core bucket.
            $mu_info = $this->match_file_to_mu_plugin( $filename_normalized );
            if ( $mu_info ) {
                return array_merge( $source_info, $mu_info );
            }

            $plugin_info = $this->match_file_to_plugin( $filename_normalized );
            if ($plugin_info) {
                return array_merge($source_info, $plugin_info);
            }
            
            $theme_info = $this->match_file_to_theme( $filename_normalized );
            if ($theme_info) {
                return array_merge($source_info, $theme_info);
            }
            
            if ($this->is_wordpress_core( $filename_normalized )) {
                return $source_info;
            }
            
            return $this->unknown_source($filename, $line);
            
        } catch (Exception $e) {
            return $this->unknown_source();
        }
    }
    
    public function get_callback_reflection($callback) {
        try {
            if (is_string($callback)) {
                if (function_exists($callback)) {
                    return new ReflectionFunction($callback);
                }
            } elseif (is_array($callback) && count($callback) === 2) {
                $class  = $callback[0];
                $method = $callback[1];
                
                if (is_object($class)) {
                    return new ReflectionMethod($class, $method);
                } elseif (is_string($class) && class_exists($class)) {
                    return new ReflectionMethod($class, $method);
                }
            } elseif (is_object($callback)) {
                if ($callback instanceof Closure) {
                    return new ReflectionFunction($callback);
                } elseif (method_exists($callback, '__invoke')) {
                    return new ReflectionMethod($callback, '__invoke');
                }
            }
        } catch (ReflectionException $e) {
            // Return null if reflection fails
        } catch (Exception $e) {
            // Return null for any other errors
        }
        
        return null;
    }

    /**
     * Match a normalized filename against mu-plugins.
     *
     * mu-plugins are NOT WordPress Core — they are site-specific or third-party
     * code that happens to be loaded as must-use plugins.
     *
     * @param string $filename_normalized Normalized absolute path.
     * @return array|null Source info array, or null if not an mu-plugin file.
     */
    private function match_file_to_mu_plugin( $filename_normalized ) {
        if ( $this->real_mu_plugin_dir === null ) {
            return null;
        }

        if ( strpos( $filename_normalized, $this->real_mu_plugin_dir . '/' ) !== 0 ) {
            return null;
        }

        try {
            $relative  = substr( $filename_normalized, strlen( $this->real_mu_plugin_dir ) + 1 );
            $parts     = explode( '/', $relative );
            $slug      = pathinfo( $parts[0], PATHINFO_FILENAME );
            $name      = ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );

            return [
                'plugin'      => 'mu-' . $slug,
                'plugin_name' => $name . ' (MU)',
                'plugin_file' => null,
            ];
        } catch ( Exception $e ) {
            return null;
        }
    }
    
    private function match_file_to_plugin( $filename_normalized ) {
        $plugins = $this->get_plugins_data();
        
        foreach ($plugins as $plugin_file => $plugin_data) {
            try {
                if ( strpos( $plugin_file, '/' ) === false ) {
                    // Single-file plugin (e.g. "hello.php") — compare full absolute path.
                    $abs_plugin_file = $this->normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file );
                    if ( $filename_normalized === $abs_plugin_file ) {
                        return [
                            'plugin'      => $this->get_plugin_slug($plugin_file),
                            'plugin_name' => $this->safe_get_plugin_name($plugin_data, $plugin_file),
                            'plugin_file' => $plugin_file
                        ];
                    }
                } else {
                    // Directory-based plugin — check if the file lives inside the plugin dir.
                    $plugin_dir = $this->normalize_path( WP_PLUGIN_DIR . '/' . dirname( $plugin_file ) );
                    if ( strpos( $filename_normalized, $plugin_dir . '/' ) === 0 ) {
                        return [
                            'plugin'      => $this->get_plugin_slug($plugin_file),
                            'plugin_name' => $this->safe_get_plugin_name($plugin_data, $plugin_file),
                            'plugin_file' => $plugin_file
                        ];
                    }
                }
            } catch (Exception $e) {
                // Skip this plugin if there's an error processing it
                continue;
            }
        }

        // Fallback: file is inside WP_PLUGIN_DIR but wasn't matched to a registered plugin
        // (e.g. inactive plugin, or get_plugins() returned stale data).
        if ( strpos( $filename_normalized, $this->real_plugin_dir . '/' ) === 0 ) {
            try {
                $relative    = substr( $filename_normalized, strlen( $this->real_plugin_dir ) + 1 );
                $path_parts  = explode( '/', $relative );
                $plugin_slug = $path_parts[0];
                
                return [
                    'plugin'      => $plugin_slug,
                    'plugin_name' => ucwords(str_replace(['-', '_'], ' ', $plugin_slug)),
                    'plugin_file' => null
                ];
            } catch (Exception $e) {
                // If path parsing fails, continue to unknown source
            }
        }
        
        return null;
    }
    
    private function match_file_to_theme( $filename_normalized ) {
        try {
            $theme_root      = $this->normalize_path( get_theme_root() );
            
            if ( strpos( $filename_normalized, $theme_root . '/' ) === 0 ) {
                $relative   = substr( $filename_normalized, strlen( $theme_root ) + 1 );
                $theme_slug = explode('/', $relative)[0];
                
                try {
                    $theme      = wp_get_theme($theme_slug);
                    $theme_name = $theme->exists() ? $theme->get('Name') : ucwords(str_replace(['-', '_'], ' ', $theme_slug));
                } catch (Exception $e) {
                    $theme_name = ucwords(str_replace(['-', '_'], ' ', $theme_slug));
                }
                
                return [
                    'plugin'      => 'theme-' . $theme_slug,
                    'plugin_name' => $theme_name . ' (Theme)',
                    'plugin_file' => null
                ];
            }
        } catch (Exception $e) {
            // If theme detection fails, return null and let it fall through to other detection methods
        }
        
        return null;
    }
    
    private function is_wordpress_core( $filename_normalized ) {
        $core_paths = [
            $this->normalize_path( ABSPATH . 'wp-includes' ) . '/',
            $this->normalize_path( ABSPATH . 'wp-admin' ) . '/',
        ];

        foreach ( $core_paths as $core_path ) {
            if ( strpos( $filename_normalized, $core_path ) === 0 ) {
                return true;
            }
        }

        return false;
    }
    
    private function get_plugins_data() {
        if ($this->plugins_cache !== null) {
            return $this->plugins_cache;
        }
        
        try {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            
            $all_plugins         = get_plugins();
            $this->plugins_cache = [];
            
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                try {
                    if (is_array($plugin_data)) {
                        $this->plugins_cache[$plugin_file] = $plugin_data;
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        } catch (Exception $e) {
            $this->plugins_cache = [];
        }
        
        return $this->plugins_cache;
    }
    
    private function get_plugin_slug($plugin_file) {
        try {
            if (strpos($plugin_file, '/') !== false) {
                return dirname($plugin_file);
            }
            
            return pathinfo($plugin_file, PATHINFO_FILENAME);
        } catch (Exception $e) {
            return 'unknown-plugin';
        }
    }
    
    private function safe_get_plugin_name($plugin_data, $plugin_file) {
        try {
            if (is_array($plugin_data) && isset($plugin_data['Name']) && !empty($plugin_data['Name'])) {
                return $plugin_data['Name'];
            }
            
            $slug = $this->get_plugin_slug($plugin_file);
            return ucwords(str_replace(['-', '_'], ' ', $slug));
        } catch (Exception $e) {
            return 'Unknown Plugin';
        }
    }
    
    private function unknown_source($file = null, $line = null) {
        return [
            'plugin'      => 'unknown',
            'plugin_name' => 'Unknown',
            'plugin_file' => null,
            'file'        => $file,
            'line'        => $line
        ];
    }
}
