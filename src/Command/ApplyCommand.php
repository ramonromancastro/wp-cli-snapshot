<?php
namespace WPCliSnapshot\Command;

use WP_CLI;
use WP_CLI_Command;
use Exception;

class ApplyCommand extends WP_CLI_Command {

    /**
     * Applies the declarative state from a JSON snapshot to the current WordPress environment.
     *
     * ## OPTIONS
     *
     * [--file=<file>]
     * : The path to the JSON snapshot file. Default: snapshot.json.
     *
     * [--dry-run]
     * : Simulates the deployment process without making any actual changes to the filesystem or database.
     *
     * [--force]
     * : Bypasses safety checks. Allows downgrading plugins, themes, or core if the currently installed version is higher than the target version in the snapshot.
     *
     * ## EXAMPLES
     *
     * # Apply state from the default snapshot.json
     * wp snapshot apply
     *
     * # Simulate applying a specific snapshot file
     * wp snapshot apply --file=production-state.json --dry-run
     *
     * # Force state application (allowing downgrades)
     * wp snapshot apply --force
     */
    public function __invoke( $args, $assoc_args ) {
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 0 );
        }

        $file_path  = WP_CLI\Utils\get_flag_value( $assoc_args, 'file', 'snapshot.json' );
        $is_dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
        $is_force   = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

        if ( ! file_exists( $file_path ) ) {
            WP_CLI::error( "Snapshot file not found: {$file_path}" );
        }

        $snapshot = json_decode( file_get_contents( $file_path ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            WP_CLI::error( "Invalid or corrupted JSON file: " . json_last_error_msg() );
        }

        if ( $is_dry_run ) {
            WP_CLI::log( WP_CLI::colorize( "%Y--- DRY RUN MODE: Simulating deployment ---%n" ) );
        }
        if ( $is_force ) {
            WP_CLI::log( WP_CLI::colorize( "%R--- WARNING: --force mode active. Downgrades are permitted ---%n" ) );
        }

        WP_CLI::log( "Reading snapshot from {$file_path}..." );

        if ( isset( $snapshot['core'] ) )    $this->process_core( $snapshot['core'], $is_dry_run, $is_force );
        if ( isset( $snapshot['themes'] ) )  $this->process_themes( $snapshot['themes'], $is_dry_run, $is_force );
        if ( isset( $snapshot['plugins'] ) ) $this->process_plugins( $snapshot['plugins'], $is_dry_run, $is_force );

        $this->update_translations( $is_dry_run );

        WP_CLI::success( $is_dry_run ? "Dry run completed successfully." : "Snapshot applied successfully. Environment is synchronized." );
    }

    /**
     * ==========================================
     * CORE LOGIC
     * ==========================================
     */
    private function process_core( $target_version, $is_dry_run, $is_force ) {
        $target_version = $this->sanitize_version( $target_version );
        global $wp_version;

        $version_diff = version_compare( $wp_version, $target_version );

        if ( $version_diff === 0 ) {
            WP_CLI::debug( "WordPress Core already at target version ({$target_version})." );
            return;
        }

        if ( $version_diff === 1 ) {
            if ( ! $is_force ) {
                WP_CLI::warning( "Current Core version ({$wp_version}) is greater than snapshot ({$target_version}). Skipped. Use --force to allow downgrade." );
                return;
            }
            WP_CLI::log( WP_CLI::colorize( "%RDowngrading WordPress Core: {$wp_version} -> {$target_version}...%n" ) );
        } else {
            WP_CLI::log( "Updating WordPress Core: {$wp_version} -> {$target_version}..." );
        }
        
        if ( ! $is_dry_run ) {
            $this->run_cli( ['core', 'update', "--version={$target_version}", '--force'] );
            $this->run_cli( ['core', 'update-db'] );
        }
    }

    /**
     * ==========================================
     * THEME LOGIC
     * ==========================================
     */
    private function process_themes( array $themes_manifest, $is_dry_run, $is_force ) {
        $target_active_theme = $themes_manifest['active'] ?? '';
        $installed_targets   = $themes_manifest['installed'] ?? [];

        $current_themes = wp_get_themes();

        foreach ( $installed_targets as $theme ) {
            $slug           = $this->sanitize_slug( $theme['slug'] );
            $target_version = $this->sanitize_version( $theme['version'] );

            $is_installed    = array_key_exists( $slug, $current_themes );
            $current_version = $is_installed ? $current_themes[ $slug ]->get('Version') : null;

            if ( ! $is_installed ) {
                WP_CLI::log( "Installing Theme '{$slug}' (v{$target_version})..." );
                if ( ! $is_dry_run ) $this->run_cli( ['theme', 'install', $slug, "--version={$target_version}"] );
                continue;
            }

            $version_diff = version_compare( $current_version, $target_version );

            if ( $version_diff === 0 ) {
                WP_CLI::debug( "Theme '{$slug}' already at target version ({$target_version})." );
                continue;
            }

            if ( $version_diff === 1 ) {
                if ( ! $is_force ) {
                    WP_CLI::warning( "Theme '{$slug}' version ({$current_version}) is greater than snapshot ({$target_version}). Skipped. Use --force to downgrade." );
                    continue;
                }
                WP_CLI::log( WP_CLI::colorize( "%RDowngrading Theme '{$slug}': {$current_version} -> {$target_version}...%n" ) );
            } else {
                WP_CLI::log( "Updating Theme '{$slug}': {$current_version} -> {$target_version}..." );
            }

            if ( ! $is_dry_run ) {
                $this->run_cli( ['theme', 'install', $slug, "--version={$target_version}", '--force'] );
            }
        }

        if ( ! empty( $target_active_theme ) ) {
            $safe_active_slug = $this->sanitize_slug( $target_active_theme );
            if ( wp_get_theme()->get_stylesheet() !== $safe_active_slug ) {
                WP_CLI::log( "Activating primary Theme: '{$safe_active_slug}'..." );
                if ( ! $is_dry_run ) $this->run_cli( ['theme', 'activate', $safe_active_slug] );
            }
        }

        if ( ! $is_dry_run ) wp_cache_flush();
    }

    /**
     * ==========================================
     * PLUGIN LOGIC
     * ==========================================
     */
    private function process_plugins( array $plugins_manifest, $is_dry_run, $is_force ) {
        if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';

        foreach ( $plugins_manifest as $plugin ) {
            $slug           = $this->sanitize_slug( $plugin['slug'] );
            $target_version = $this->sanitize_version( $plugin['version'] );
            $target_status  = $plugin['status'];
            $is_custom      = $plugin['type'] === 'custom';

            $installed_plugins = get_plugins();
            $plugin_file       = $this->get_plugin_file_by_slug( $slug, $installed_plugins );

            if ( $is_custom ) {
                if ( ! $plugin_file ) {
                    WP_CLI::warning( "Custom dependency missing: Plugin '{$slug}' is not present in the filesystem." );
                } else {
                    $this->ensure_plugin_activation( $slug, $plugin_file, $target_status, $is_dry_run );
                }
                continue; 
            }

            try {
                if ( ! $plugin_file ) {
                    WP_CLI::log( "Installing Plugin '{$slug}' (v{$target_version})..." );
                    if ( ! $is_dry_run ) {
                        $this->run_cli( ['plugin', 'install', $slug, "--version={$target_version}"] );
                        $plugin_file = $this->get_plugin_file_by_slug( $slug, get_plugins() );
                    }
                } else {
                    $current_version = $installed_plugins[ $plugin_file ]['Version'];
                    $version_diff    = version_compare( $current_version, $target_version );

                    if ( $version_diff !== 0 ) {
                        if ( $version_diff === 1 ) {
                            if ( ! $is_force ) {
                                WP_CLI::warning( "Plugin '{$slug}' version ({$current_version}) is greater than snapshot ({$target_version}). Skipped. Use --force to downgrade." );
                            } else {
                                WP_CLI::log( WP_CLI::colorize( "%RDowngrading Plugin '{$slug}': {$current_version} -> {$target_version}...%n" ) );
                                if ( ! $is_dry_run ) $this->run_cli( ['plugin', 'install', $slug, "--version={$target_version}", '--force'] );
                            }
                        } else {
                            WP_CLI::log( "Updating Plugin '{$slug}': {$current_version} -> {$target_version}..." );
                            if ( ! $is_dry_run ) $this->run_cli( ['plugin', 'install', $slug, "--version={$target_version}", '--force'] );
                        }
                    } else {
                        WP_CLI::debug( "Plugin '{$slug}' already at target version ({$target_version})." );
                    }
                }

                if ( $plugin_file || $is_dry_run ) {
                    $this->ensure_plugin_activation( $slug, $plugin_file, $target_status, $is_dry_run );
                }

            } catch ( Exception $e ) {
                WP_CLI::warning( "Failed to process plugin '{$slug}': " . $e->getMessage() );
            }

            if ( ! $is_dry_run ) wp_cache_flush();
        }
    }

    /**
     * ==========================================
     * TRANSLATION LOGIC
     * ==========================================
     */
    private function update_translations( $is_dry_run ) {
        WP_CLI::log( "Updating translations for Core, Plugins, and Themes..." );

        if ( ! $is_dry_run ) {
            // Update Core translations
            $this->run_cli( ['language', 'core', 'update'] );
            
            // Update all Plugin translations
            $this->run_cli( ['language', 'plugin', 'update', '--all'] );
            
            // Update all Theme translations
            $this->run_cli( ['language', 'theme', 'update', '--all'] );
        } else {
            WP_CLI::log( "-> [Dry Run] Translation updates skipped." );
        }
    }
    
    /**
     * ==========================================
     * HELPERS & DEFENSIVE MEASURES
     * ==========================================
     */

    private function ensure_plugin_activation( $slug, $plugin_file, $target_status, $is_dry_run ) {
        $is_active = $plugin_file ? is_plugin_active( $plugin_file ) : false;
        if ( $target_status === 'active' && ! $is_active ) {
            WP_CLI::log( "Activating '{$slug}'..." );
            if ( ! $is_dry_run ) $this->run_cli( ['plugin', 'activate', $slug] );
        } elseif ( $target_status === 'inactive' && $is_active ) {
            WP_CLI::log( "Deactivating '{$slug}'..." );
            if ( ! $is_dry_run ) $this->run_cli( ['plugin', 'deactivate', $slug] );
        }
    }

    private function get_plugin_file_by_slug( $slug, $installed_plugins ) {
        foreach ( $installed_plugins as $file => $data ) {
            $current_slug = dirname($file) === '.' ? basename($file, '.php') : dirname($file);
            if ( $current_slug === $slug ) return $file;
        }
        return false;
    }

    private function run_cli( array $args ) {
        WP_CLI::run_command( $args, [
            'return'     => false,
            'exit_error' => false, 
        ]);
    }

    private function sanitize_slug( $slug ) {
        if ( ! preg_match( '/^[a-zA-Z0-9\-\_]+$/', $slug ) ) {
            WP_CLI::error( "Aborting: Malicious or malformed slug detected ({$slug})." );
        }
        return $slug;
    }

    private function sanitize_version( $version ) {
        if ( ! preg_match( '/^[a-zA-Z0-9\.\-\_]+$/', $version ) ) {
            WP_CLI::error( "Aborting: Malicious or malformed version detected ({$version})." );
        }
        return $version;
    }
}
