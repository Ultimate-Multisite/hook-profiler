<?php

defined('ABSPATH') || exit;

class WP_Hook_Profiler_Engine {
    
    public $timing_data = [];
    public $callback_aggregates = [];
    private $plugin_detector = null;
    private $profiling_active = false;
    private $hook_count = 0;
    public $total_execution_time = 0;
    private $recursion_guard = false;
    private $hook_depth = 0;
    private $max_hook_depth = 500;

    /**
     * Maximum number of unique callback+hook pairs to track.
     * Prevents unbounded growth of callback_aggregates on sites with many hooks.
     * Configurable via the 'wp_hook_profiler_max_callbacks' filter.
     */
    private $max_callbacks = 500;

    /**
     * Maximum number of unique hook names stored per plugin in timing_data['hooks'].
     * Prevents O(hooks × plugins) memory growth on sites with many hooks/plugins.
     * Configurable via the 'wp_hook_profiler_max_hooks_per_plugin' filter.
     */
    private $max_hooks_per_plugin = 100;

    /**
     * Memory usage threshold (fraction of PHP memory_limit) at which profiling
     * is automatically paused to prevent OOM. E.g. 0.80 = pause at 80% usage.
     * Configurable via the 'wp_hook_profiler_memory_threshold' filter.
     */
    private $memory_threshold = 0.80;

    /**
     * Whether profiling was paused due to memory pressure.
     */
    public $memory_paused = false;

    /**
     * PHP memory limit in bytes (resolved once at construction time).
     */
    private $php_memory_limit = 0;

    public function __construct() {
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-plugin-detector.php';
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-callback-wrapper.php';
        $this->plugin_detector = new WP_Hook_Profiler_Plugin_Detector();
        $this->php_memory_limit = $this->resolve_memory_limit();

        // Allow site owners to tune limits without editing source.
        $this->max_callbacks        = (int) apply_filters( 'wp_hook_profiler_max_callbacks',        $this->max_callbacks );
        $this->max_hooks_per_plugin = (int) apply_filters( 'wp_hook_profiler_max_hooks_per_plugin', $this->max_hooks_per_plugin );
        $this->memory_threshold     = (float) apply_filters( 'wp_hook_profiler_memory_threshold',   $this->memory_threshold );
    }
    
    public function start_profiling() {
        if ($this->profiling_active) {
            return;
        }
        
        $this->profiling_active = true;
        
        add_action('all', [$this, 'on_hook_start'], -999999);
    }
    
    public function on_hook_start($hook_name) {
        if (!$this->profiling_active || $this->is_profiler_hook($hook_name)) {
            return;
        }

        if ($this->recursion_guard) {
            return;
        }

        // Pause profiling if memory usage is approaching the PHP limit.
        if ($this->is_memory_critical()) {
            $this->memory_paused = true;
            return;
        }
        
        $this->hook_depth++;
        
        if ($this->hook_depth > $this->max_hook_depth) {
            $this->hook_depth--;
            return;
        }
        
        $this->profile_hook_callbacks($hook_name);
        
        $this->hook_count++;
        $this->hook_depth--;
    }
    
    private function is_profiler_hook($hook_name) {
        $profiler_hooks = [
            'wp_ajax_wp_hook_profiler_data',
            'wp_ajax_nopriv_wp_hook_profiler_data',
            'all'
        ];
        
        return in_array($hook_name, $profiler_hooks, true);
    }
    
    private function profile_hook_callbacks($hook_name) {
        if ($this->recursion_guard) {
            return;
        }

		global $wp_filter;

        if (!isset($wp_filter[$hook_name])) {
			return;
		}

        $hook_object = $wp_filter[ $hook_name ];

        if ($hook_object instanceof WP_Hook) {
			foreach ($hook_object->callbacks as $priority => &$priority_callbacks) {
				foreach ($priority_callbacks as $idx => &$callback_data) {
					if (! $callback_data['function'] instanceof WP_Hook_Profiler_Callback_Wrapper) {
						$callback_data['function'] = new WP_Hook_Profiler_Callback_Wrapper(
							$callback_data['function'],
							$hook_name,
							$priority,
							$callback_data['accepted_args'],
							$this
						);
					}
				}
			}
		}
    }

    /**
     * Returns true when memory usage has exceeded the configured threshold.
     * Uses a fast integer comparison; no string parsing at call time.
     */
    private function is_memory_critical() {
        if ($this->php_memory_limit <= 0) {
            return false;
        }
        return memory_get_usage(true) >= (int) ($this->php_memory_limit * $this->memory_threshold);
    }

    /**
     * Resolve the PHP memory_limit ini value to bytes.
     * Returns 0 if the limit is -1 (unlimited) or cannot be parsed.
     */
    private function resolve_memory_limit() {
        $raw = ini_get('memory_limit');
        if ($raw === false || $raw === '' || $raw === '-1') {
            return 0;
        }
        $raw   = trim($raw);
        $value = (int) $raw;
        $unit  = strtolower(substr($raw, -1));
        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        return $value;
    }
    
    
    public function get_plugin_info_safe($callback) {

        $this->recursion_guard = true;
        
        try {
            $plugin_info = $this->plugin_detector->identify_callback_source($callback);
        } catch (Exception $e) {
            $plugin_info = [
                'plugin' => 'error',
                'plugin_name' => 'Error: ' . $e->getMessage(),
                'plugin_file' => null,
                'file' => null
            ];
        }
        
        $this->recursion_guard = false;
        return $plugin_info;
    }
    
    public function get_callback_name($callback) {
        if (is_string($callback)) {
            return $callback;
        }
        
        if (is_array($callback) && count($callback) === 2) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            return $class . '::' . $callback[1];
        }
        
        if (is_object($callback)) {
            if ($callback instanceof Closure) {
                return 'Closure';
            }
            return get_class($callback) . '->__invoke()';
        }
        
        return 'Unknown Callback';
    }
    
    public function get_callback_reflection($callback) {
        return $this->plugin_detector->get_callback_reflection($callback);
    }

    /**
     * Returns true when the callback_aggregates map has reached its cap.
     * Called by WP_Hook_Profiler_Callback_Wrapper before creating a new entry.
     */
    public function is_callbacks_cap_reached() {
        return count($this->callback_aggregates) >= $this->max_callbacks;
    }

    /**
     * Returns true when the hooks list for a given plugin has reached its cap.
     * Called by WP_Hook_Profiler_Callback_Wrapper before appending a hook name.
     */
    public function is_hooks_per_plugin_cap_reached($plugin_key) {
        if (!isset($this->timing_data[$plugin_key])) {
            return false;
        }
        return count($this->timing_data[$plugin_key]['hooks']) >= $this->max_hooks_per_plugin;
    }
    
    public function get_profile_data() {
        uasort($this->timing_data, function($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });
        
        $callback_data = array_values($this->callback_aggregates);
        usort($callback_data, function($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });
        
        // Get plugin loading timing data
        $plugin_loading_data = [];
        if (function_exists('wp_hook_profiler_get_timing_data')) {
            $plugin_loading_data = wp_hook_profiler_get_timing_data();
        }

        // Strip the hooks array from plugin data before returning — it can be
        // very large and is not needed by the UI (the callbacks tab covers it).
        $plugins_summary = [];
        foreach ($this->timing_data as $key => $data) {
            $plugins_summary[$key] = [
                'total_time'     => $data['total_time'],
                'hook_count'     => $data['hook_count'],
                'callback_count' => $data['callback_count'],
                'plugin_name'    => $data['plugin_name'],
                'plugin_file'    => $data['plugin_file'],
            ];
        }
        
        return [
            'plugins'           => $plugins_summary,
            'callbacks'         => array_slice($callback_data, 0, 150),
            'plugin_loading'    => $plugin_loading_data,
            'total_hooks'       => $this->hook_count,
            'total_execution_time' => $this->total_execution_time,
            'memory_paused'     => $this->memory_paused,
            'callbacks_capped'  => count($this->callback_aggregates) >= $this->max_callbacks,
            'max_callbacks'     => $this->max_callbacks,
        ];
    }
    
    public function get_hook_count() {
        return $this->hook_count;
    }
    
    public function get_total_execution_time() {
        return $this->total_execution_time;
    }
}
