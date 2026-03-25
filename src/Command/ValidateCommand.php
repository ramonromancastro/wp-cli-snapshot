<?php
namespace WPCliSnapshot\Command;

use WP_CLI;
use WP_CLI_Command;

class ValidateCommand extends WP_CLI_Command {

    /**
     * Validates the snapshot.json file against the WordPress.org APIs.
     * Checks for outdated versions, insecure core releases, and abandoned (stale) or removed packages.
     * Does NOT modify the local installation.
     *
     * ## OPTIONS
     *
     * [--file=<file>]
     * : The path to the JSON snapshot file. Default: snapshot.json.
     *
     * [--stale-days=<days>]
     * : Number of days without an update before a package is flagged as abandoned. Default: 730.
     *
     * [--strict]
     * : Return a non-zero exit code if ANY package is outdated or stale. 
     * By default, only critical security issues (Insecure Core, Removed packages) return an error code.
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *  - table
     *  - json
     *  - csv
     *  - yaml
     *  - count
     * ---
     *
     * [--fields=<fields>]
     * : Limit the output to specific object fields. Comma-separated list.
     * ---
     * default: name,type,update,version,update_version,status
     * ---
     *
     * @when before_wp_load
     *
     * ## EXAMPLES
     *
     * # Validate and display as a table (default)
     * wp snapshot validate
     *
     * # Export results to a JSON file for CI/CD pipelines
     * wp snapshot validate --format=json > report.json
     *
     * # Export results to a CSV file
     * wp snapshot validate --format=csv > report.csv
     */
    public function __invoke( $args, $assoc_args ) {
        $file_path  = WP_CLI\Utils\get_flag_value( $assoc_args, 'file', 'snapshot.json' );
        $stale_days = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'stale-days', 730 );
        $format     = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $is_strict  = WP_CLI\Utils\get_flag_value( $assoc_args, 'strict', false );

        // 1. Extraer y limpiar los campos solicitados por el usuario
        $fields_raw = WP_CLI\Utils\get_flag_value( $assoc_args, 'fields', 'name,type,update,version,update_version,status' );
        $fields     = array_filter( array_map( 'trim', explode( ',', $fields_raw ) ) );

        if ( ! file_exists( $file_path ) ) {
            WP_CLI::error( "Snapshot file not found: {$file_path}" );
        }

        $snapshot = json_decode( file_get_contents( $file_path ), true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            WP_CLI::error( "Invalid JSON file: " . json_last_error_msg() );
        }

        // SILENCE IS GOLDEN: Only print conversational logs if the format is human-readable
        if ( $format === 'table' ) {
            $strict_msg = $is_strict ? ' [STRICT MODE]' : '';
            WP_CLI::log( "Auditing snapshot against WordPress.org APIs (Stale threshold: {$stale_days} days){$strict_msg}...\n" );
        }

        $results = [];
        $return_code = 0; // 0 = Success, 1 = Pipeline Failure

        // 1. Audit Core
        if ( isset( $snapshot['core'] ) ) {
            $core_data = $this->check_core( $snapshot['core'] );
            $results[] = $core_data;
            $return_code = $this->evaluate_exit_code( $core_data['status'], $return_code, $is_strict );
        }

        // 2. Audit Themes
        if ( isset( $snapshot['themes']['installed'] ) ) {
            foreach ( $snapshot['themes']['installed'] as $theme ) {
                if ( $theme['type'] === 'custom' ) {
                    continue;
                }
                $theme_data = $this->check_theme( $theme, $stale_days );
                $results[]  = $theme_data;
                $return_code = $this->evaluate_exit_code( $theme_data['status'], $return_code, $is_strict );
            }
        }

        // 3. Audit Plugins
        if ( isset( $snapshot['plugins'] ) ) {
            foreach ( $snapshot['plugins'] as $plugin ) {
                if ( $plugin['type'] === 'custom' ) {
                    continue;
                }
                $plugin_data = $this->check_plugin( $plugin, $stale_days );
                $results[]   = $plugin_data;
                $return_code = $this->evaluate_exit_code( $plugin_data['status'], $return_code, $is_strict );
            }
        }

        // Output the data FIRST (so the CI pipeline can save the JSON/CSV artifact)
        WP_CLI\Utils\format_items( $format, $results, $fields );

        // Halt the execution with an error code if issues were found
        if ( $return_code !== 0 ) {
            // We use halt() instead of error() to prevent dumping a generic text error into STDOUT if the user requested JSON
            if ( $format === 'table' ) {
                WP_CLI::log( WP_CLI::colorize( "%RAudit failed. Critical security issues or strict policy violations detected.%n" ) );
            }
            WP_CLI::halt( 1 );
        }
    }

    /**
     * Check WordPress Core version for updates and security warnings.
     */
    private function check_core( $current_version ) {
        $update_version = 'unknown';
        $status = 'unknown';
        $update = 'unknown';
        
        $url= "https://api.wordpress.org/core/stable-check/1.0/";
        $response = WP_CLI\Utils\http_request( 'GET', $url );
        
        if ( $response->status_code === 200 ) {
            $data = json_decode( $response->body, true );
            $update_version = (end($data) === 'latest') ? array_key_last($data) : 'unknown';
                       
            if ( isset( $data[$current_version] ) ) {
                $status = $data[$current_version];
                if ( $data[$current_version] === 'insecure' ) {
                    $update = 'available';
                } elseif ( $data[$current_version] === 'outdated' ) {
                    $update = 'available';
                } else {
                    $update = 'none';
                    $update_version = '';
                }
            }
        }

        return [
            'name'     => 'wordpress core',
            'type'     => 'core',
            'update'   => $update,
            'version' => $current_version,
            'update_version' => $update_version,
            'status'   => $status,
        ];
    }

    /**
     * Check Plugin against WP.org REST API for version and staleness.
     */
    private function check_plugin( $plugin, $stale_days ) {
        $slug            = $plugin['slug'];
        $current_version = $plugin['version'];
        
        $url = "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug={$slug}";
        $response = WP_CLI\Utils\http_request( 'GET', $url );
        
        $update_version = 'unknown';
        $status = 'unknown';
        $update = 'unknown';

        if ( $response->status_code === 200 ) {
            $data   = json_decode( $response->body, true );
            $update_version = $data['version'] ?? 'unknown';
            
            if ( isset($data['closed']) ){
                $status = 'closed';
                $update_version = '';
            }
            elseif ( version_compare( $current_version, $update_version, '<' ) ) {
                $status = 'outdated';
                $update = 'available';
            } elseif ( version_compare( $current_version, $update_version, '=' ) ) {
                $status = 'latest';
                $update = 'none';
                $update_version = '';
            } else {
                $status = 'invalid';
                $update = 'none';
            }

            if ( ! empty( $data['last_updated'] ) ) {
                $status = $this->append_stale_warning( $status, $data['last_updated'], $stale_days );
                
            }

        } elseif ( $response->status_code === 404 ) {
            $status = 'removed/not in repo';
        }

        return [
            'name'     => $slug,
            'type'     => 'plugin',
            'version' => $current_version,
            'update_version'   => $update_version,
            'status'   => $status,
        ];
    }

    /**
     * Check Theme against WP.org REST API for version and staleness.
     */
    private function check_theme( $theme, $stale_days ) {
        $slug            = $theme['slug'];
        $current_version = $theme['version'];
        
        $url = "https://api.wordpress.org/themes/info/1.2/?action=theme_information&slug={$slug}";
                $response = WP_CLI\Utils\http_request( 'GET', $url );
        
        $update_version = 'unknown';
        $status = 'unknown';
        $update = 'unknown';

        if ( $response->status_code === 200 ) {
            $data   = json_decode( $response->body, true );
            $update_version = $data['version'] ?? 'unknown';
            
            if ( version_compare( $current_version, $update_version, '<' ) ) {
                $status = 'outdated';
                $update = 'available';
            } elseif ( version_compare( $current_version, $update_version, '=' ) ) {
                $status = 'latest';
                $update = 'none';
                $update_version = '';
            } else {
                $status = 'invalid';
                $update = 'none';
            }

            if ( ! empty( $data['last_updated'] ) ) {
                $status = $this->append_stale_warning( $status, $data['last_updated'], $stale_days );
                
            }

        } elseif ( $response->status_code === 404 ) {
            $status = 'removed/not in repo';
        }

        return [
            'name'     => $slug,
            'type'     => 'theme',
            'version' => $current_version,
            'update_version'   => $update_version,
            'status'   => $status,
        ];
    }

    /**
     * Helper to calculate if a package is stale and append the warning to the status.
     */
    private function append_stale_warning( $current_status, $last_updated_string, $stale_days ) {
        $updated_timestamp = strtotime( $last_updated_string );
        
        if ( ! $updated_timestamp ) {
            return $current_status;
        }

        $days_since_update = floor( ( time() - $updated_timestamp ) / 86400 );

        if ( $days_since_update > $stale_days ) {
            return $current_status . ", staled)";
        }

        return $current_status;
    }

    /**
     * Evaluates if the current status string should trigger a pipeline failure (Exit Code 1).
     */
    private function evaluate_exit_code( $status_text, $current_exit_code, $is_strict ) {
        // If it's already failing, don't reset it
        if ( $current_exit_code === 1 ) {
            return 1;
        }

        // Critical failures (Always break the build)
        if ( strpos( $status_text, 'insecure' ) !== false ) {
            return 1;
        }
        if ( strpos( $status_text, 'removed/not in repo' ) !== false ) {
            return 1;
        }

        // Strict mode failures (Break the build if outdated or stale)
        if ( $is_strict ) {
            if ( strpos( $status_text, 'outdated' ) !== false ) {
                return 1;
            }
            if ( strpos( $status_text, 'staled' ) !== false ) {
                return 1;
            }
            if ( strpos( $status_text, 'unknown' ) !== false ) {
                return 1;
            }
        }

        return 0;
    }
}
