<?php
/**
 * Plugin Name: Hook Profiler
 * Plugin URI: https://github.com/user/wp-hook-profiler
 * Description: Advanced WordPress hook profiler that measures execution time of actions and filters to identify performance bottlenecks by plugin.
 * Version: 1.1.0
 * Author: Performance Analysis Tools
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Network: true
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('WP_HOOK_PROFILER_VERSION', '1.1.0');
define('WP_HOOK_PROFILER_FILE', __FILE__);
define('WP_HOOK_PROFILER_DIR', plugin_dir_path(__FILE__));
define('WP_HOOK_PROFILER_URL', plugin_dir_url(__FILE__));

/**
 * Main plugin class for WP Hook Profiler.
 *
 * Bootstraps the profiling engine, registers WordPress hooks for the admin bar
 * menu, asset enqueueing, AJAX data endpoint, and the debug panel overlay.
 * Also handles plugin activation and deactivation lifecycle (mu-plugin copy and
 * sunrise.php modification for early-boot timing).
 *
 * @since 1.0.0
 */
class WP_Hook_Profiler {
    
    /** @var WP_Hook_Profiler|null Singleton instance. */
    private static $instance = null;

    /** @var WP_Hook_Profiler_Engine The profiling engine instance. */
    private WP_Hook_Profiler_Engine $profiler;
    
    /**
     * Return (and lazily create) the singleton instance.
     *
     * @return WP_Hook_Profiler
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor — use {@see WP_Hook_Profiler::instance()} instead.
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Register all WordPress hooks required by the plugin.
     *
     * @return void
     */
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
    
    /**
     * Require and instantiate the profiling engine, then start profiling.
     *
     * @return void
     */
    public function load_profiler() {
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-hook-profiler-engine.php';
        $this->profiler = new WP_Hook_Profiler_Engine();
        $this->profiler->start_profiling();
    }
    
    /**
     * Add a summary node to the WordPress admin bar (admins only).
     *
     * Displays the total number of profiled hooks and their cumulative
     * execution time in milliseconds. Clicking the node toggles the debug panel.
     *
     * @param WP_Admin_Bar $wp_admin_bar The admin bar instance provided by WordPress.
     * @return void
     */
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
    
    /**
     * Enqueue the plugin's stylesheet and JavaScript on both front-end and admin pages.
     *
     * The script is localised with the AJAX URL and a nonce so the debug panel
     * can fetch profiling data on demand.
     *
     * @return void
     */
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
    
    /**
     * Handle the AJAX request that returns profiling data to the debug panel.
     *
     * Verifies the nonce and capability before delegating to the engine.
     * Responds with JSON via {@see wp_send_json_success()} or {@see wp_send_json_error()}.
     *
     * @return void
     */
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
    
    /**
     * Output the debug panel HTML in the page footer (admins only).
     *
     * Inlines the current profile data as a JSON script tag so the panel has
     * immediate access to the data without an additional AJAX round-trip.
     *
     * @return void
     */
    public function render_debug_panel() {
        if (!current_user_can('manage_options')) {
            return;
        }

		echo '<script>var hook_profiler_data = '.wp_json_encode($this->profiler->get_profile_data()).'</script>';

        
        include WP_HOOK_PROFILER_DIR . 'views/debug-panel.php';
    }
    
    /**
     * Plugin activation hook.
     *
     * Copies the mu-plugin timing shim and optionally modifies sunrise.php to
     * capture early-boot timing data.
     *
     * @return void
     */
    public static function activate() {
		self::create_mu_plugin();
        self::modify_sunrise_php();
    }
    
    /**
     * Plugin deactivation hook.
     *
     * Removes the mu-plugin timing shim and restores the original sunrise.php.
     *
     * @return void
     */
    public static function deactivate() {
		self::delete_mu_plugin();
        self::restore_sunrise_php();
    }

    /**
     * Copy the mu-plugin timing shim into WPMU_PLUGIN_DIR.
     *
     * Creates the mu-plugins directory if it does not already exist.
     *
     * @return void
     */
	public static function create_mu_plugin() {
		if (!is_dir(WPMU_PLUGIN_DIR)) {
			mkdir(WPMU_PLUGIN_DIR);
		}

		copy(WP_HOOK_PROFILER_DIR . 'mu-plugin/aaaaa-hook-profiler-plugin-timing.php.txt', WPMU_PLUGIN_DIR .'/aaaaa-wp-hook-profiler-timing.php');

	}

    /**
     * Remove the mu-plugin timing shim from WPMU_PLUGIN_DIR if it exists.
     *
     * @return void
     */
	public static function delete_mu_plugin() {
		if (file_exists(WPMU_PLUGIN_DIR .'/aaaaa-wp-hook-profiler-timing.php')) {
			unlink(WPMU_PLUGIN_DIR .'/aaaaa-wp-hook-profiler-timing.php');
		}
	}
    
    /**
     * Modify sunrise.php to add timing code at the beginning and end of the file.
     *
     * A backup of the original file is created before any modifications are made.
     * If sunrise.php does not exist, or if the timing code is already present,
     * this method returns early without making changes.
     *
     * @return void
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
     * Restore the original sunrise.php from the backup created during activation.
     *
     * If no backup exists this method is a no-op.
     *
     * @return void
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
