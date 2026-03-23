<?php
/**
 * Plugin Name: WP-CLI Snapshot
 * Description: Declarative state management for WordPress.
 */

if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

// Composer's autoloader handles the class loading
use WPCliSnapshot\Command\ExportCommand;
use WPCliSnapshot\Command\ApplyCommand;
use WPCliSnapshot\Command\ValidateCommand;

WP_CLI::add_command( 'snapshot export', ExportCommand::class );
WP_CLI::add_command( 'snapshot apply', ApplyCommand::class );
WP_CLI::add_command( 'snapshot validate', ValidateCommand::class );