<?php

defined('ABSPATH') || exit;

/**
 * Core profiling engine for WP Hook Profiler.
 *
 * Hooks into the WordPress 'all' pseudo-hook to intercept every action and
 * filter fired during a page request. For each hook it wraps the registered
 * callbacks in {@see WP_Hook_Profiler_Callback_Wrapper} instances that measure
 * individual execution times and aggregate the results by plugin.
 *
 * A hook-depth guard ({@see WP_Hook_Profiler_Engine::$max_hook_depth}) prevents
 * stack overflows caused by deeply recursive hook chains.
 *
 * @since 1.0.0
 */
class WP_Hook_Profiler_Engine {
    
    /**
     * Per-plugin timing aggregates, keyed by plugin slug.
     *
     * Each entry contains: total_time, hook_count, callback_count, hooks[],
     * plugin_name, plugin_file.
     *
     * @var array<string, array<string, mixed>>
     */
    public $timing_data = [];

    /**
     * Per-callback timing aggregates, keyed by "{callback_name}|{hook_name}|{priority}".
     *
     * Each entry contains: hook, callback, plugin, plugin_name, source_file,
     * total_time, call_count, average_time, priority, accepted_args.
     *
     * @var array<string, array<string, mixed>>
     */
    public $callback_aggregates = [];

    /** @var WP_Hook_Profiler_Plugin_Detector|null Plugin detector instance. */
    private $plugin_detector = null;

    /** @var bool Whether profiling is currently active. */
    private $profiling_active = false;

    /** @var int Total number of unique hooks profiled so far. */
    private $hook_count = 0;

    /**
     * Cumulative execution time of all profiled callbacks in milliseconds.
     *
     * Declared public so {@see WP_Hook_Profiler_Callback_Wrapper} can update it
     * directly without an additional method call on the hot path.
     *
     * @var float
     */
    public $total_execution_time = 0;

    /** @var bool Recursion guard to prevent re-entrant profiling. */
    private $recursion_guard = false;

    /** @var int Current hook nesting depth. */
    private $hook_depth = 0;

    /** @var int Maximum allowed hook nesting depth before profiling is skipped. */
    private $max_hook_depth = 500;

    /**
     * Constructor.
     *
     * Loads the plugin detector and callback wrapper dependencies.
     */
    public function __construct() {
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-plugin-detector.php';
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-callback-wrapper.php';
        $this->plugin_detector = new WP_Hook_Profiler_Plugin_Detector();
    }
    
    /**
     * Begin profiling by attaching to the WordPress 'all' pseudo-hook.
     *
     * Calling this method more than once is safe — subsequent calls are no-ops.
     *
     * @return void
     */
    public function start_profiling() {
        if ($this->profiling_active) {
            return;
        }
        
        $this->profiling_active = true;
        
        add_action('all', [$this, 'on_hook_start'], -999999);
    }
    
    /**
     * Callback fired for every WordPress hook via the 'all' pseudo-hook.
     *
     * Guards against recursion and excessive nesting depth before delegating
     * to {@see WP_Hook_Profiler_Engine::profile_hook_callbacks()}.
     *
     * @param string $hook_name The name of the hook being fired.
     * @return void
     */
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
    
    /**
     * Determine whether a hook name belongs to the profiler itself.
     *
     * Profiler-internal hooks are excluded from profiling to prevent infinite
     * recursion.
     *
     * @param string $hook_name The hook name to test.
     * @return bool True if the hook should be skipped.
     */
    private function is_profiler_hook($hook_name) {
        $profiler_hooks = [
            'wp_ajax_wp_hook_profiler_data',
            'wp_ajax_nopriv_wp_hook_profiler_data',
            'all'
        ];
        
        return in_array($hook_name, $profiler_hooks, true);
    }
    
    /**
     * Wrap all untracked callbacks on a given hook with timing wrappers.
     *
     * Iterates over the {@see WP_Hook} callbacks array for the given hook and
     * replaces any callback that is not already a
     * {@see WP_Hook_Profiler_Callback_Wrapper} with a new wrapper instance.
     *
     * @param string $hook_name The hook whose callbacks should be wrapped.
     * @return void
     */
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
     * Safely identify the plugin that registered a given callback.
     *
     * Wraps {@see WP_Hook_Profiler_Plugin_Detector::identify_callback_source()} with
     * a recursion guard and exception handler so that plugin detection errors
     * never interrupt the hook being profiled.
     *
     * @param callable $callback The callback whose source should be identified.
     * @return array{plugin: string, plugin_name: string, plugin_file: string|null, file: string|null}
     */
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
    
    /**
     * Return a human-readable name for a callback.
     *
     * Handles strings (function names), arrays (static/instance method pairs),
     * Closure objects, and invokable objects.
     *
     * @param callable $callback The callback to name.
     * @return string A descriptive name such as "ClassName::methodName" or "Closure".
     */
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
    
    /**
     * Return a ReflectionFunctionAbstract for the given callback.
     *
     * Delegates to {@see WP_Hook_Profiler_Plugin_Detector::get_callback_reflection()}.
     *
     * @param callable $callback The callback to reflect.
     * @return \ReflectionFunctionAbstract|null Reflection object, or null on failure.
     */
    public function get_callback_reflection($callback) {
        return $this->plugin_detector->get_callback_reflection($callback);
    }
    
    /**
     * Return a snapshot of all profiling data collected so far.
     *
     * Plugins are sorted by total execution time (descending). Callbacks are
     * sorted by total execution time (descending).
     *
     * @return array{
     *   plugins: array<string, array<string, mixed>>,
     *   callbacks: list<array<string, mixed>>,
     *   plugin_loading: array<string, mixed>,
     *   total_hooks: int,
     *   total_execution_time: float
     * }
     */
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
            'callbacks' => $callback_data,
            'plugin_loading' => $plugin_loading_data,
            'total_hooks' => $this->hook_count,
            'total_execution_time' => $this->total_execution_time,
        ];
    }
    
    /**
     * Return the total number of unique hooks profiled during this request.
     *
     * @return int
     */
    public function get_hook_count() {
        return $this->hook_count;
    }
    
    /**
     * Return the cumulative execution time of all profiled callbacks in milliseconds.
     *
     * @return float
     */
    public function get_total_execution_time() {
        return $this->total_execution_time;
    }
}
