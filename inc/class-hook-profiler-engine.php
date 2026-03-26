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

    public function __construct() {
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-plugin-detector.php';
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-callback-wrapper.php';
        $this->plugin_detector = new WP_Hook_Profiler_Plugin_Detector();
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
        
        return [
            'plugins' => $this->timing_data,
            'callbacks' => array_slice($callback_data, 0, 150),
            'plugin_loading' => $plugin_loading_data,
            'total_hooks' => $this->hook_count,
            'total_execution_time' => $this->total_execution_time,
        ];
    }
    
    public function get_hook_count() {
        return $this->hook_count;
    }
    
    public function get_total_execution_time() {
        return $this->total_execution_time;
    }
}