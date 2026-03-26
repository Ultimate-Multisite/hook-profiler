<?php
/**
 * Plugin Name: Hook Profiler
 * Plugin URI: https://github.com/user/wp-hook-profiler
 * Description: Advanced WordPress hook profiler that measures execution time of actions and filters to identify performance bottlenecks by plugin.
 * Version: 1.0.0
 * Author: Performance Analysis Tools
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Network: true
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('WP_HOOK_PROFILER_VERSION', '1.0.0');
define('WP_HOOK_PROFILER_FILE', __FILE__);
define('WP_HOOK_PROFILER_DIR', plugin_dir_path(__FILE__));
define('WP_HOOK_PROFILER_URL', plugin_dir_url(__FILE__));

class WP_Hook_Profiler {
    
    private static $instance = null;
    private WP_Hook_Profiler_Engine $profiler;
    
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    private function init() {
        $this->load_profiler();
        add_action('admin_bar_menu', [$this, 'add_admin_bar_menu'], 999);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_wp_hook_profiler_data', [$this, 'ajax_get_profiler_data']);
        add_action('wp_ajax_nopriv_wp_hook_profiler_data', [$this, 'ajax_get_profiler_data']);
        
        if (is_admin()) {
            add_action('admin_footer', [$this, 'render_debug_panel']);
        } else {
            add_action('wp_footer', [$this, 'render_debug_panel']);
        }

    }
    
    public function load_profiler() {
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-hook-profiler-engine.php';
        $this->profiler = new WP_Hook_Profiler_Engine();
        $this->profiler->start_profiling();
    }
    
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $hook_count = $this->profiler ? $this->profiler->get_hook_count() : 0;
        $total_time = $this->profiler ? $this->profiler->get_total_execution_time() : 0;
        
        $wp_admin_bar->add_menu([
            'id' => 'wp-hook-profiler',
            'title' => sprintf(
                '<span class="ab-icon dashicons dashicons-performance"></span> Hooks: %d (%sms)', 
                $hook_count, 
                number_format($total_time, 2)
            ),
            'href' => '#',
            'meta' => [
                'class' => 'wp-hook-profiler-toggle',
                'onclick' => 'WP_Hook_Profiler.toggle(); return false;'
            ]
        ]);
    }
    
    public function enqueue_assets() {
        wp_enqueue_style(
            'wp-hook-profiler', 
            WP_HOOK_PROFILER_URL . 'assets/profiler.css',
            [], 
            WP_HOOK_PROFILER_VERSION
        );
        
        wp_enqueue_script(
            'wp-hook-profiler',
            WP_HOOK_PROFILER_URL . 'assets/profiler.js',
            ['jquery'],
            WP_HOOK_PROFILER_VERSION,
            true
        );
        
        wp_localize_script('wp-hook-profiler', 'wpHookProfiler', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_hook_profiler_nonce')
        ]);
    }
    
    public function ajax_get_profiler_data() {
        check_ajax_referer('wp_hook_profiler_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized', 403);
        }
        
        if (!$this->profiler) {
            wp_send_json_error('Profiler not initialized');
        }
        
        wp_send_json_success($this->profiler->get_profile_data());
    }
    
    public function render_debug_panel() {
        if (!current_user_can('manage_options')) {
            return;
        }

		echo '<script>var hook_profiler_data = '.wp_json_encode($this->profiler->get_profile_data()).'</script>';

        
        include WP_HOOK_PROFILER_DIR . 'views/debug-panel.php';
    }
    
    /**
     * Plugin activation hook
     */
    public static function activate() {
		self::create_mu_plugin();
        self::modify_sunrise_php();
    }
    
    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
		self::delete_mu_plugin();
        self::restore_sunrise_php();
    }

	public static function create_mu_plugin() {
		if (!is_dir(WPMU_PLUGIN_DIR)) {
			mkdir(WPMU_PLUGIN_DIR);
		}

		copy(WP_HOOK_PROFILER_DIR . 'mu-plugin/aaaaa-hook-profiler-plugin-timing.php.txt', WPMU_PLUGIN_DIR .'/aaaaa-wp-hook-profiler-timing.php');

	}

	public static function delete_mu_plugin() {
		if (file_exists(WPMU_PLUGIN_DIR .'/aaaaa-wp-hook-profiler-timing.php')) {
			unlink(WPMU_PLUGIN_DIR .'/aaaaa-wp-hook-profiler-timing.php');
		}
	}
    
    /**
     * Modify sunrise.php to add timing code
     */
    private static function modify_sunrise_php() {
        $sunrise_path = WP_CONTENT_DIR . '/sunrise.php';
        
        if (!file_exists($sunrise_path)) {
            return;
        }
        
        // Read current sunrise.php content
        $content = file_get_contents($sunrise_path);
        
        // Check if our timing code is already present
        if (strpos($content, '$wp_hook_profiler_sunrise_start_time') !== false) {
            return; // Already modified
        }
        
        // Create backup
        file_put_contents($sunrise_path . '.wp-hook-profiler-backup', $content);
        
        // Add timing code at the beginning
        $timing_start = "<?php\n// WP Hook Profiler Timing - Start\nglobal \$wp_hook_profiler_sunrise_start_time;\n\$wp_hook_profiler_sunrise_start_time = hrtime(true);\n\n";
        
        // Add timing code at the end before the closing \?\> or at the very end
        $timing_end = "\n// WP Hook Profiler Timing - End\nglobal \$wp_hook_profiler_sunrise_end_time;\n\$wp_hook_profiler_sunrise_end_time = hrtime(true);";
        
        // Replace opening PHP tag with our timing start
        $content = preg_replace('/^<\?php/', $timing_start, $content, 1);
        
        // Add timing end before closing PHP tag or at the end
        if (preg_match('/\?>\s*$/', $content)) {
            $content = preg_replace('/\?>\s*$/', $timing_end . "\n?>", $content);
        } else {
            $content .= $timing_end;
        }
        
        // Write modified content back
        file_put_contents($sunrise_path, $content);
    }
    
    /**
     * Restore original sunrise.php
     */
    private static function restore_sunrise_php() {
        $sunrise_path = WP_CONTENT_DIR . '/sunrise.php';
        $backup_path = $sunrise_path . '.wp-hook-profiler-backup';
        
        if (file_exists($backup_path)) {
            // Restore from backup
            copy($backup_path, $sunrise_path);
            unlink($backup_path);
        }
    }
}

// Register activation and deactivation hooks
register_activation_hook(__FILE__, ['WP_Hook_Profiler', 'activate']);
register_deactivation_hook(__FILE__, ['WP_Hook_Profiler', 'deactivate']);

WP_Hook_Profiler::instance();