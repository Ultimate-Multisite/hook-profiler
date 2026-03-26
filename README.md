# WordPress Hook Profiler

A powerful WordPress plugin that profiles every action and filter hook to identify performance bottlenecks and measure execution time by plugin.

## Features

- **Comprehensive Hook Profiling**: Measures execution time of all WordPress actions and filters
- **Plugin Attribution**: Identifies which plugin or theme owns each callback
- **Real-time Performance Data**: Live profiling with minimal overhead
- **Interactive Debug Panel**: Rich interface with sorting, filtering, and detailed breakdowns
- **Multiple Views**: 
  - Plugins overview with total execution times
  - Slowest callbacks identification
  - Hook-by-hook detailed analysis

## Installation

1. Copy the `wp-hook-profiler` directory to your WordPress `wp-content/plugins/` directory
2. Or upload the zip on the Add Plugin page.
2. Activate the plugin through the WordPress admin or network admin (for multisite)
3. The profiler will automatically start collecting data

## Usage

### Accessing the Profiler

Once activated, you'll see a new "Hooks" indicator in your admin bar showing:
- Total number of hooks executed
- Total execution time

Click the admin bar item to open the detailed profiling panel.

### Profiler Interface

The debug panel provides three main views:

#### 1. Plugins Overview
- Shows total execution time per plugin
- Displays hook count and callback count
- Calculates average execution time per callback
- Sortable and searchable

#### 2. Slowest Callbacks  
- Lists individual callbacks by execution time
- Shows which hook and plugin each callback belongs to
- Helps identify specific performance bottlenecks

#### 3. Hook Details
- Groups callbacks by hook name
- Shows total time per hook
- Allows filtering by plugin
- Detailed breakdown of each hook's callbacks

### Performance Impact

The profiler is designed to have minimal impact on your site's performance.
But it is only recommended to have it active while actively testing. Deactivate it once done.

## Technical Details

### How It Works

1. **Hook Interception**: Uses WordPress's `all` hook to intercept every action and filter
2. **Timing Measurement**: Records start and end times for each hook execution
3. **Callback Attribution**: Uses PHP reflection to identify the source file and plugin for each callback
4. **Data Aggregation**: Processes timing data to provide meaningful insights

### Architecture

- `WP_Hook_Profiler_Engine`: Core profiling logic and timing measurement
- `WP_Hook_Profiler_Plugin_Detector`: Identifies plugin ownership of callbacks
- Interactive frontend with AJAX data loading
- Responsive CSS interface with sorting and filtering

### Requirements

- WordPress 5.0+
- PHP 7.4+
- User must have `manage_options` capability to view profiling data

## Security

- Only users with `manage_options` capability can access profiling data
- AJAX requests are nonce-protected
- No data is stored permanently - all profiling data is session-based

## Troubleshooting

### Plugin Not Showing Data
- Ensure you have sufficient permissions (`manage_options` capability)
- Check that JavaScript is enabled in your browser
- Verify that AJAX requests are working (check browser console for errors)

### High Memory Usage / PHP Fatal: Allowed memory size exhausted

The profiler collects data in PHP memory during the request. On sites with many
plugins and hooks this can exhaust the default 256 MB limit. Several safeguards
are built in:

**Automatic memory guard** — profiling pauses automatically when PHP memory
usage reaches 80 % of `memory_limit`. A warning banner appears in the panel
when this happens. You can tune the threshold:

```php
// In your theme's functions.php or a mu-plugin:
add_filter( 'wp_hook_profiler_memory_threshold', fn() => 0.70 ); // pause at 70 %
```

**Callback cap** — only the first 500 unique callback+hook pairs are tracked.
Once the cap is reached, new callbacks are still executed and their time is
counted in the total, but no new per-callback entry is created. A warning
banner appears in the panel when the cap is hit. Raise it if needed:

```php
add_filter( 'wp_hook_profiler_max_callbacks', fn() => 1000 );
```

**Per-plugin hook list cap** — each plugin's hook list is capped at 100 entries
to prevent O(hooks × plugins) memory growth. Raise it if needed:

```php
add_filter( 'wp_hook_profiler_max_hooks_per_plugin', fn() => 200 );
```

**Lazy data loading** — the profiling panel no longer embeds the full dataset
as inline JSON on every page load. Data is fetched via AJAX only when you open
the panel, eliminating the serialisation overhead that previously caused OOM
errors at render time.

If you still hit memory limits after tuning the filters, increase PHP's
`memory_limit` in `php.ini` or `wp-config.php`:

```php
define( 'WP_MEMORY_LIMIT', '512M' );
```

### Performance Impact
- The profiler adds minimal overhead but does measure every hook
- Use primarily in development/staging environments
- The estimated overhead is displayed in the profiling panel

## Contributing

This plugin is designed for development and debugging purposes. Feel free to extend or modify it for your specific profiling needs.

## License

GPL v2 or later