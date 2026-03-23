<?php
namespace WPCliSnapshot\Command;

use WP_CLI;
use WP_CLI_Command;

class ExportCommand extends WP_CLI_Command {

    /**
     * Exports the current state of Core, Themes, and Plugins to a JSON snapshot file.
     *
     * ## OPTIONS
     *
     * [--file=<file>]
     * : The path where the JSON snapshot will be saved. Default: snapshot.json.
     *
     * [--custom-prefix=<prefixes>]
     * : Comma-separated list of prefixes to identify custom/private plugins. 
     * Example: acme-,client_
     *
     * ## EXAMPLES
     *
     * # Export current state to default file
     * wp snapshot export
     *
     * # Export defining custom prefixes for agency plugins
     * wp snapshot export --custom-prefix=myagency-,core_
     */
    public function __invoke( $args, $assoc_args ) {
        $file_path = WP_CLI\Utils\get_flag_value( $assoc_args, 'file', 'snapshot.json' );
        
        $raw_prefixes    = isset($assoc_args['custom-prefix']) ? explode(',', $assoc_args['custom-prefix']) : [];
        $custom_prefixes = array_filter( array_map( 'trim', $raw_prefixes ) );

        WP_CLI::log( "Analyzing current WordPress environment..." );

        $snapshot = [
            'core'    => get_bloginfo('version'),
            'themes'  => $this->get_themes_data( $custom_prefixes ),
            'plugins' => $this->get_plugins_data( $custom_prefixes ),
            'mu_plugins' => $this->get_mu_plugins_data(),
            'dropins'    => $this->get_dropins_data(),
        ];

        $json_data = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if ( file_put_contents( $file_path, $json_data ) === false ) {
            WP_CLI::error( "Critical failure: Unable to write to {$file_path}. Check filesystem permissions." );
        }

        WP_CLI::success( "Snapshot successfully exported to: {$file_path}" );
    }

    private function get_themes_data( array $custom_prefixes ) {
        $active_theme = wp_get_theme();
        $all_themes   = wp_get_themes();
        
        $parent_themes = [];
        $child_themes  = [];

        foreach ( $all_themes as $slug => $theme ) {
            $type      = 'wporg';
            
            foreach ( $custom_prefixes as $prefix ) {
                if ( str_starts_with( $slug, $prefix ) ) {
                    $type = 'custom';
                    break; 
                }
            }
            $theme_data = [
                'slug'    => $slug,
                'version' => $theme->get('Version'),
                'type'    => $type,
            ];
            
            // If the theme returns an object for parent(), it's a child theme.
            if ( $theme->parent() ) {
                $child_themes[] = $theme_data;
            } else {
                // It's a standalone or parent theme.
                $parent_themes[] = $theme_data;
            }
        }

        return [
            'active'    => $active_theme->get_stylesheet(),
            // array_merge guarantees all parents appear in the JSON before any child
            'installed' => array_merge( $parent_themes, $child_themes ),
        ];
    }

    private function get_plugins_data( array $custom_prefixes ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins  = get_plugins();
        $plugins_data = [];

        foreach ( $all_plugins as $file => $plugin_data ) {
            $slug = dirname($file) === '.' ? basename($file, '.php') : dirname($file);
            
            $is_active = is_plugin_active( $file );
            $type      = 'wporg';

            foreach ( $custom_prefixes as $prefix ) {
                if ( str_starts_with( $slug, $prefix ) ) {
                    $type = 'custom';
                    break; 
                }
            }

            $plugins_data[] = [
                'slug'    => $slug,
                'version' => $plugin_data['Version'],
                'status'  => $is_active ? 'active' : 'inactive',
                'type'    => $type,
            ];
        }

        return $plugins_data;
    }
/**
     * Retrieves Must-Use Plugins (MU-Plugins) data.
     */
    private function get_mu_plugins_data() {
        if ( ! function_exists( 'get_mu_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $mu_plugins = get_mu_plugins();
        $mu_data    = [];

        foreach ( $mu_plugins as $file => $plugin_data ) {
            $mu_data[] = [
                'file'    => $file,
                'name'    => $plugin_data['Name'] ?? $file,
                'version' => $plugin_data['Version'] ?? 'unknown',
            ];
        }

        return $mu_data;
    }

    /**
     * Retrieves Drop-ins data.
     */
    private function get_dropins_data() {
        if ( ! function_exists( 'get_dropins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // get_dropins() devuelve un array donde la clave es el archivo (ej. 'advanced-cache.php')
        // y el valor es un array estructurado por el core: [0 => 'Nombre', 1 => 'Propósito']
        $dropins      = get_dropins();
        $dropins_data = [];

        foreach ( $dropins as $file => $data ) {
            $dropins_data[] = [
                'file'    => $file,
                'name'    => $data[0] ?? $file,
                'purpose' => $data[1] ?? 'unknown',
            ];
        }

        return $dropins_data;
    }
}