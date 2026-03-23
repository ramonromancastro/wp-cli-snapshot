<?php
/**
 * Plugin Name: WP-CLI Snapshot
 * Description: Declarative state management for WordPress. Export, validate, and apply environment states.
 * Version:     1.0.0
 * Author:      Ramón Román Castro
 * License:     MIT
 * Text Domain: wp-cli-snapshot
 */

// Evitar que el archivo se cargue fuera de WP-CLI
if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

/**
 * Autoload de Composer.
 * Nota: WP-CLI gestiona esto automáticamente cuando instalas el paquete,
 * pero es una red de seguridad si el archivo se carga manualmente.
 */
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use WPCliSnapshot\Command\ExportCommand;
use WPCliSnapshot\Command\ApplyCommand;
use WPCliSnapshot\Command\ValidateCommand;

/**
 * Registro de comandos.
 * * Al usar WP_CLI::add_command con una estructura de 'snapshot [verbo]', 
 * WP-CLI agrupa automáticamente estos bajo el comando principal 'snapshot'.
 */
WP_CLI::add_command( 'snapshot export', ExportCommand::class );
WP_CLI::add_command( 'snapshot apply', ApplyCommand::class );
WP_CLI::add_command( 'snapshot validate', ValidateCommand::class );
