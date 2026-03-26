<?php

defined('ABSPATH') || exit;

/**
 * Wraps a WordPress hook callback to measure its execution time.
 *
 * An instance of this class replaces the original callback function in the
 * {@see WP_Hook} callbacks array. When WordPress fires the hook, PHP invokes
 * {@see WP_Hook_Profiler_Callback_Wrapper::__invoke()}, which records timing
 * data in the engine before and after delegating to the original function.
 *
 * Metadata (callback name, plugin info, aggregate key) is resolved once in the
 * constructor and cached on the instance to avoid repeated lookups on the hot
 * path when the same callback fires many times.
 *
 * @since 1.0.0
 */
class WP_Hook_Profiler_Callback_Wrapper {
    
    /** @var callable The original callback being wrapped. */
    private $original_function;

    /** @var string The name of the hook this wrapper is attached to. */
    private $hook_name;

    /** @var int The priority at which this callback is registered. */
    private $priority;

    /** @var int The number of arguments the callback accepts. */
    private $accepted_args;

    /** @var WP_Hook_Profiler_Engine The profiling engine used to record timing data. */
    private WP_Hook_Profiler_Engine $engine;

    /** @var string Cached human-readable callback name. */
    private $callback_name;

    /**
     * Cached plugin identification info for this callback.
     *
     * @var array{plugin: string, plugin_name: string, plugin_file: string|null, file: string|null}
     */
    private $plugin_info;

    /** @var string Cached aggregate key: "{callback_name}|{hook_name}|{priority}". */
    private $callback_key;

    /**
     * Constructor.
     *
     * Resolves and caches callback metadata (name, plugin info, aggregate key)
     * once at registration time so that per-invocation overhead is minimal.
     *
     * @param callable                  $original_function The original WordPress hook callback.
     * @param string                    $hook_name         The hook name (action or filter tag).
     * @param int                       $priority          The callback's registered priority.
     * @param int                       $accepted_args     Number of arguments the callback accepts.
     * @param WP_Hook_Profiler_Engine   $engine            The profiling engine instance.
     */
    public function __construct($original_function, $hook_name, $priority, $accepted_args, $engine) {
        $this->original_function = $original_function;
        $this->hook_name = $hook_name;
        $this->priority = $priority;
        $this->accepted_args = $accepted_args;
        $this->engine = $engine;

        // Resolve and cache metadata once; key includes priority so the same
        // callback registered at two different priorities gets separate rows.
        $this->callback_name = $engine->get_callback_name($original_function);
        $this->plugin_info   = $engine->get_plugin_info_safe($original_function);
        $this->callback_key  = $this->callback_name . '|' . $hook_name . '|' . $priority;
    }
    
    /**
     * Invoke the wrapped callback and record its execution time.
     *
     * Delegates all arguments to the original function unchanged and returns
     * its return value unmodified, preserving filter chain behaviour.
     *
     * Timing data is accumulated in the engine's callback_aggregates and
     * timing_data maps. Plugin totals are updated on every invocation.
     *
     * @param mixed ...$args Arguments forwarded from the WordPress hook dispatcher.
     * @return mixed The return value of the original callback.
     */
    public function __invoke(...$args ) {

        $callback_key = $this->callback_key;

        if (!isset($this->engine->callback_aggregates[$callback_key])) {
            $this->engine->callback_aggregates[$callback_key] = [
                'hook' => $this->hook_name,
                'callback' => $this->callback_name,
                'plugin' => $this->plugin_info['plugin'],
                'plugin_name' => $this->plugin_info['plugin_name'],
                'source_file' => $this->plugin_info['file'],
                'total_time' => 0,
                'call_count' => 0,
                'average_time' => 0,
                'priority' => $this->priority,
                'accepted_args' => $this->accepted_args
            ];
        }
        $start = hrtime(true);

		$original_function = $this->original_function;
		$result = $original_function(...$args);
        $end = hrtime(true);
        $eta = $end - $start;
        $eta /= 1e+6; // nanoseconds to milliseconds
        
        if (is_finite($eta) && $eta >= 0) {
            $this->engine->callback_aggregates[$callback_key]['total_time'] += $eta;
            $this->engine->callback_aggregates[$callback_key]['call_count']++;
            $this->engine->total_execution_time += $eta;
            
            if ($this->engine->callback_aggregates[$callback_key]['call_count'] > 0) {
                $this->engine->callback_aggregates[$callback_key]['average_time'] = 
                    $this->engine->callback_aggregates[$callback_key]['total_time'] / 
                    $this->engine->callback_aggregates[$callback_key]['call_count'];
            }

            // Update plugin totals (guarded: only accumulate finite, non-negative values)
            $plugin_key = $this->plugin_info['plugin'];
            if (!isset($this->engine->timing_data[$plugin_key])) {
                $this->engine->timing_data[$plugin_key] = [
                    'total_time' => 0,
                    'hook_count' => 0,
                    'callback_count' => 0,
                    'hooks' => [],
                    'plugin_name' => $this->plugin_info['plugin_name'],
                    'plugin_file' => $this->plugin_info['plugin_file']
                ];
            }

            $this->engine->timing_data[$plugin_key]['total_time'] += $eta;
            $this->engine->timing_data[$plugin_key]['callback_count']++;

            if (!in_array($this->hook_name, $this->engine->timing_data[$plugin_key]['hooks'])) {
                $this->engine->timing_data[$plugin_key]['hooks'][] = $this->hook_name;
                $this->engine->timing_data[$plugin_key]['hook_count']++;
            }
        }
        
        return $result;
    }
}
