# AGENTS.md - Hook Profiler

WordPress plugin that measures execution time of every action and filter callback to identify performance bottlenecks by plugin, theme, or core.

## Build Commands

```bash
# No build step required — PHP + vanilla JS/CSS, no compilation
# No package manager dependencies (no composer.json or package.json)
```

This is a zero-dependency WordPress plugin. There is no build, no Composer autoloader, and no npm/webpack pipeline. All PHP classes are loaded via `require_once` in the main plugin file and engine constructor.

## Testing

No test framework is configured. The plugin is tested manually:

1. Activate the plugin on a WordPress 5.0+ site (PHP 7.4+)
2. Visit any front-end or admin page as an administrator
3. Click the "Hooks" item in the admin bar to toggle the debug panel overlay
4. Verify data appears in all four tabs: Plugins Overview, Slowest Callbacks, Hook Details, Plugin Loading

## Linting

No linter is configured. Follow WordPress Coding Standards manually.

## Code Style

### PHP

- **No namespaces**: All classes use the global namespace with `WP_Hook_Profiler_` prefix
- **No strict types**: Files do not use `declare(strict_types=1)`
- **WordPress standards**: WordPress coding style (tabs for indentation, short array syntax `[]`)
- **ABSPATH guard**: Every PHP file starts with `defined('ABSPATH') || exit;`
- **Singleton pattern**: `WP_Hook_Profiler` main class uses `instance()` singleton
- **No Composer autoloader**: Classes are loaded via explicit `require_once`

### JavaScript

- **Vanilla JS**: No frameworks, no build step — plain JavaScript in `assets/profiler.js`
- **jQuery dependency**: Uses jQuery for DOM manipulation and AJAX
- **Global namespace**: Exposes `WP_Hook_Profiler` object on `window`
- **wp_localize_script**: AJAX URL and nonce passed via `wpHookProfiler` global

### CSS

- **Vanilla CSS**: Plain CSS in `assets/profiler.css`
- **BEM-like selectors**: Prefixed with `wp-hook-profiler-` (e.g. `.wp-hook-profiler-panel`, `.wp-hook-profiler-tab`)

### Naming Conventions

| Type | Convention | Example |
|------|------------|---------|
| PHP classes | `WP_Hook_Profiler_` prefix + `Upper_Snake_Case` | `WP_Hook_Profiler_Engine` |
| PHP methods | `snake_case` | `start_profiling()`, `get_profile_data()` |
| PHP variables | `$snake_case` | `$timing_data`, `$hook_count` |
| PHP constants | `UPPER_SNAKE_CASE` with `WP_HOOK_PROFILER_` prefix | `WP_HOOK_PROFILER_VERSION` |
| JS object | `WP_Hook_Profiler` | `WP_Hook_Profiler.toggle()` |
| CSS classes | `wp-hook-profiler-` prefix + kebab-case | `wp-hook-profiler-panel` |
| AJAX actions | `wp_hook_profiler_` prefix | `wp_hook_profiler_data` |

### File Organisation

```text
hook-profiler.php                              # Main plugin file, singleton bootstrap, hooks
inc/
  class-hook-profiler-engine.php               # Core profiling engine, hooks into 'all' pseudo-hook
  class-callback-wrapper.php                   # Wraps callbacks to measure execution time (__invoke)
  class-plugin-detector.php                    # Identifies source plugin/theme/core via Reflection
assets/
  profiler.css                                 # Debug panel styles
  profiler.js                                  # Debug panel UI logic (tabs, tables, sorting, search)
views/
  debug-panel.php                              # Debug panel HTML template (rendered in wp_footer/admin_footer)
mu-plugin/
  aaaaa-hook-profiler-plugin-timing.php.txt    # MU-plugin shim for early-boot plugin load timing
                                               # (.txt extension so WP doesn't detect it as primary plugin)
migrations/                                    # Empty (placeholder)
schemas/                                       # Empty (placeholder)
seeds/                                         # Empty (placeholder)
```

### Architecture

The profiling pipeline works as follows:

1. **`WP_Hook_Profiler`** (main class) instantiates the engine and registers WordPress hooks
2. **`WP_Hook_Profiler_Engine`** attaches to the `all` pseudo-hook at priority -999999
3. On each hook fire, the engine iterates the `$wp_filter` callbacks array and replaces untracked callbacks with **`WP_Hook_Profiler_Callback_Wrapper`** instances
4. Each wrapper's `__invoke()` calls `hrtime(true)` before/after the original callback and accumulates timing data on the engine
5. **`WP_Hook_Profiler_Plugin_Detector`** uses PHP Reflection to map each callback's source file to a plugin, theme, mu-plugin, or WordPress core
6. The debug panel renders in `wp_footer`/`admin_footer` with inline JSON data; AJAX endpoint provides on-demand refresh

### Activation / Deactivation

On activation:
- Copies `mu-plugin/aaaaa-hook-profiler-plugin-timing.php.txt` to `WPMU_PLUGIN_DIR/aaaaa-wp-hook-profiler-timing.php` (early-boot timing)
- Modifies `sunrise.php` to inject timing globals (creates backup first)

On deactivation:
- Removes the mu-plugin shim
- Restores `sunrise.php` from backup

### Key Constants

```php
WP_HOOK_PROFILER_VERSION    // Plugin version (currently '1.0.0')
WP_HOOK_PROFILER_FILE       // Main plugin file path (__FILE__)
WP_HOOK_PROFILER_DIR        // Plugin directory path (trailing slash)
WP_HOOK_PROFILER_URL        // Plugin directory URL
```

### Security

- **Capability checks**: All admin bar, debug panel, and AJAX handlers require `manage_options`
- **Nonce verification**: AJAX endpoint uses `wp_hook_profiler_nonce` via `check_ajax_referer()`
- **Admin-only UI**: Debug panel only renders for users with `manage_options` capability

### Error Handling

- The engine uses a recursion guard (`$recursion_guard`) to prevent re-entrant profiling
- Hook depth is capped at 500 (`$max_hook_depth`) to prevent stack overflows
- Plugin detection wraps Reflection calls in try/catch; on failure returns an `unknown` plugin marker (or `multisite-ultimate` if the file path contains `multisite-ultimate`)
- The callback wrapper guards against non-finite timing values

### WordPress Requirements

- **WordPress**: >= 5.0
- **PHP**: >= 7.4
- **Network**: Yes (multisite compatible, `Network: true` in plugin header)

### Commit Messages

Use conventional commits:

- `feat:` -- New feature
- `fix:` -- Bug fix
- `docs:` -- Documentation only
- `refactor:` -- Code change that neither fixes a bug nor adds a feature
- `chore:` -- Maintenance tasks

Example: `feat: add filtering by hook type in debug panel`
