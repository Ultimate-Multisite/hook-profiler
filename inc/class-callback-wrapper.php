<?php

defined('ABSPATH') || exit;

class WP_Hook_Profiler_Callback_Wrapper {
    
    private $original_function;
    private $hook_name;
    private $priority;
    private $accepted_args;
    private WP_Hook_Profiler_Engine $engine;

    // Cached per-registration metadata (computed once in constructor)
    private $callback_name;
    private $plugin_info;
    private $callback_key;

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