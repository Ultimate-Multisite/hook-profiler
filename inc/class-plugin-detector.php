<?php

defined('ABSPATH') || exit;

/**
 * Identifies the WordPress plugin (or theme, or core) that owns a given callback.
 *
 * Uses PHP Reflection to obtain the source file of a callback, then matches
 * that file against the registered plugins list, the mu-plugins directory, the
 * active theme directory, and the WordPress core paths — in that order.
 *
 * Results from {@see get_plugins()} are cached for the lifetime of the request
 * to avoid repeated filesystem scans.
 *
 * @since 1.0.0
 */
class WP_Hook_Profiler_Plugin_Detector {
    
    /** @var array<string, array<string, mixed>>|null Cached result of get_plugins(). */
    private $plugins_cache = null;

    /** @var array<string, mixed>|null Reserved for future theme caching. */
    private $themes_cache = null;

    /** @var string Absolute path to the wp-includes directory. */
    private $wp_core_path = null;
    
    /**
     * Constructor.
     *
     * Initialises the WordPress core path used for source attribution.
     */
    public function __construct() {
        $this->wp_core_path = ABSPATH . 'wp-includes/';
    }
    
    /**
     * Identify the source (plugin, theme, or WordPress core) of a callback.
     *
     * Uses PHP Reflection to obtain the file that defines the callback, then
     * matches it against regular plugins, themes, mu-plugins, and WordPress core
     * — in that order. Returns an "unknown" source array if no match is found or
     * if reflection fails.
     *
     * @param callable $callback The callback to identify.
     * @return array{
     *   plugin: string,
     *   plugin_name: string,
     *   plugin_file: string|null,
     *   file: string|null,
     *   line: int|null
     * }
     */
    public function identify_callback_source($callback) {
        $source_info = [
            'plugin' => 'wordpress-core',
            'plugin_name' => 'WordPress Core',
            'plugin_file' => null,
            'file' => null,
            'line' => null
        ];
        
        try {
            $reflection = $this->get_callback_reflection($callback);
            
            if (!$reflection) {
                return $this->unknown_source();
            }
            
            $filename = $reflection->getFileName();
            $line = $reflection->getStartLine();
            
            if (!$filename) {
                return $this->unknown_source();
            }
            
            $source_info['file'] = $filename;
            $source_info['line'] = $line;
            
            $plugin_info = $this->match_file_to_plugin($filename);
            if ($plugin_info) {
                return array_merge($source_info, $plugin_info);
            }
            
            $theme_info = $this->match_file_to_theme($filename);
            if ($theme_info) {
                return array_merge($source_info, $theme_info);
            }

            // MU-plugins must be checked before the core fallback so they are
            // attributed to their own slug rather than bucketed as "WordPress Core".
            $mu_plugin_info = $this->match_file_to_mu_plugin($filename);
            if ($mu_plugin_info) {
                return array_merge($source_info, $mu_plugin_info);
            }
            
            if ($this->is_wordpress_core($filename)) {
                return $source_info;
            }
            
            return $this->unknown_source($filename, $line);
            
        } catch (Exception $e) {
            return $this->unknown_source();
        }
    }
    
    /**
     * Return a ReflectionFunctionAbstract for the given callback.
     *
     * Handles strings (function names), arrays (static/instance method pairs),
     * Closure objects, and invokable objects. Returns null if reflection fails
     * for any reason.
     *
     * @param callable $callback The callback to reflect.
     * @return \ReflectionFunctionAbstract|null
     */
    public function get_callback_reflection($callback) {
        try {
            if (is_string($callback)) {
                if (function_exists($callback)) {
                    return new ReflectionFunction($callback);
                }
            } elseif (is_array($callback) && count($callback) === 2) {
                $class = $callback[0];
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
     * Match a filename against the registered plugins list.
     *
     * First attempts an exact match against the registered plugin list from
     * {@see get_plugins()}. Falls back to a directory-prefix match for files
     * inside WP_PLUGIN_DIR that are not registered (e.g. inactive plugins).
     *
     * @param string $filename Absolute path to the source file.
     * @return array{plugin: string, plugin_name: string, plugin_file: string|null}|null
     *         Source info array, or null if the file is not inside WP_PLUGIN_DIR.
     */
    private function match_file_to_plugin($filename) {
        $plugins = $this->get_plugins_data();
        
        foreach ($plugins as $plugin_file => $plugin_data) {
            try {
                if (strpos($plugin_file, '/') === false) {
                    // Single-file plugin (e.g. hello.php): compare absolute paths
                    if ($filename === WP_PLUGIN_DIR . '/' . $plugin_file) {
                        return [
                            'plugin' => $this->get_plugin_slug($plugin_file),
                            'plugin_name' => $this->safe_get_plugin_name($plugin_data, $plugin_file),
                            'plugin_file' => $plugin_file
                        ];
                    }
                } else {
                    // Directory plugin: require trailing slash to prevent foo matching foo-bar
                    $plugin_dir = dirname(WP_PLUGIN_DIR . '/' . $plugin_file) . '/';
                    if (strpos($filename, $plugin_dir) === 0) {
                        return [
                            'plugin' => $this->get_plugin_slug($plugin_file),
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
        
        if (strpos($filename, WP_PLUGIN_DIR) === 0) {
            try {
                $relative_path = str_replace(WP_PLUGIN_DIR . '/', '', $filename);
                $path_parts = explode('/', $relative_path);
                $plugin_slug = $path_parts[0];
                
                return [
                    'plugin' => $plugin_slug,
                    'plugin_name' => ucwords(str_replace(['-', '_'], ' ', $plugin_slug)),
                    'plugin_file' => null
                ];
            } catch (Exception $e) {
                // If path parsing fails, continue to unknown source
            }
        }
        
        return null;
    }
    
    /**
     * Match a filename against the active and installed themes.
     *
     * @param string $filename Absolute path to the source file.
     * @return array{plugin: string, plugin_name: string, plugin_file: null}|null
     *         Source info array, or null if the file is not inside the theme root.
     */
    private function match_file_to_theme($filename) {
        try {
            $theme_root = get_theme_root();
            
            if (strpos($filename, $theme_root) === 0) {
                $relative_path = str_replace($theme_root . '/', '', $filename);
                $theme_slug = explode('/', $relative_path)[0];
                
                try {
                    $theme = wp_get_theme($theme_slug);
                    $theme_name = $theme->exists() ? $theme->get('Name') : ucwords(str_replace(['-', '_'], ' ', $theme_slug));
                } catch (Exception $e) {
                    $theme_name = ucwords(str_replace(['-', '_'], ' ', $theme_slug));
                }
                
                return [
                    'plugin' => 'theme-' . $theme_slug,
                    'plugin_name' => $theme_name,
                    'plugin_file' => null
                ];
            }
        } catch (Exception $e) {
            // If theme detection fails, return null and let it fall through to other detection methods
        }
        
        return null;
    }
    
    /**
     * Determine whether a filename belongs to WordPress core.
     *
     * Checks against both wp-includes and wp-admin paths.
     *
     * @param string $filename Absolute path to the source file.
     * @return bool True if the file is part of WordPress core.
     */
    private function is_wordpress_core($filename) {
        return strpos($filename, $this->wp_core_path) === 0 ||
               strpos($filename, ABSPATH . 'wp-admin/') === 0;
    }

    /**
     * Match a filename against the mu-plugins directory.
     *
     * mu-plugins are NOT WordPress Core — they are site-specific or third-party
     * code that happens to be loaded as must-use plugins.
     *
     * @param string $filename Absolute path to the source file.
     * @return array{plugin: string, plugin_name: string, plugin_file: null}|null
     *         Source info array, or null if not an mu-plugin file.
     */
    private function match_file_to_mu_plugin($filename) {
        $mu_plugin_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : ABSPATH . 'wp-content/mu-plugins';
        if (strpos($filename, $mu_plugin_dir . '/') !== 0) {
            return null;
        }
        $relative = substr($filename, strlen($mu_plugin_dir) + 1);
        $parts    = explode('/', $relative);
        $slug     = count($parts) > 1 ? $parts[0] : pathinfo($parts[0], PATHINFO_FILENAME);
        return [
            'plugin'      => 'mu-plugin-' . $slug,
            'plugin_name' => ucwords(str_replace(['-', '_'], ' ', $slug)) . ' (MU)',
            'plugin_file' => null,
        ];
    }
    
    /**
     * Return the cached plugin data from get_plugins(), loading it on first call.
     *
     * Requires and calls {@see get_plugins()} if it is not already loaded.
     * Returns an empty array if get_plugins() throws or returns unexpected data.
     *
     * @return array<string, array<string, mixed>> Map of plugin_file => plugin_data.
     */
    private function get_plugins_data() {
        if ($this->plugins_cache !== null) {
            return $this->plugins_cache;
        }
        
        try {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            
            $all_plugins = get_plugins();
            $this->plugins_cache = [];
            
            // Filter out plugins with invalid data structures
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                try {
                    // Test if we can safely access the plugin data
                    if (is_array($plugin_data)) {
                        $this->plugins_cache[$plugin_file] = $plugin_data;
                    }
                } catch (Exception $e) {
                    // Skip plugins with invalid data
                    continue;
                }
            }
        } catch (Exception $e) {
            $this->plugins_cache = [];
        }
        
        return $this->plugins_cache;
    }
    
    /**
     * Derive a plugin slug from a plugin file path.
     *
     * For directory-based plugins (e.g. "my-plugin/my-plugin.php") returns the
     * directory name. For single-file plugins (e.g. "hello.php") returns the
     * filename without extension.
     *
     * @param string $plugin_file Relative plugin file path as returned by get_plugins().
     * @return string Plugin slug.
     */
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
    
    /**
     * Safely extract the plugin display name from plugin data.
     *
     * Falls back to a humanised version of the plugin slug if the Name field is
     * absent or empty.
     *
     * @param array<string, mixed>|mixed $plugin_data Plugin data array from get_plugins().
     * @param string                     $plugin_file Relative plugin file path.
     * @return string Human-readable plugin name.
     */
    private function safe_get_plugin_name($plugin_data, $plugin_file) {
        try {
            if (is_array($plugin_data) && isset($plugin_data['Name']) && !empty($plugin_data['Name'])) {
                return $plugin_data['Name'];
            }
            
            // Fallback to a readable name based on the plugin file
            $slug = $this->get_plugin_slug($plugin_file);
            return ucwords(str_replace(['-', '_'], ' ', $slug));
        } catch (Exception $e) {
            return 'Unknown Plugin';
        }
    }
    
    /**
     * Return a generic "unknown source" info array.
     *
     * Used when reflection fails or the file cannot be matched to any known
     * plugin, theme, or WordPress core path. Includes special handling for
     * Multisite Ultimate files.
     *
     * @param string|null $file Optional source file path.
     * @param int|null    $line Optional source line number.
     * @return array{plugin: string, plugin_name: string, plugin_file: null, file: string|null, line: int|null}
     */
    private function unknown_source($file = null, $line = null) {
        $is_multisite_ultimate = $file && strpos($file, 'multisite-ultimate') !== false;
        return [
            'plugin'      => $is_multisite_ultimate ? 'multisite-ultimate' : 'unknown',
            'plugin_name' => $is_multisite_ultimate ? 'Multisite Ultimate' : 'unknown',
            'plugin_file' => null,
            'file'        => $file,
            'line'        => $line
        ];
    }
}
