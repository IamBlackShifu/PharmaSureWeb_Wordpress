<?php
/**
 * Autoloader for PharmaSure Core
 * 
 * @package PharmaSure_Core
 */

namespace PharmaSure\Core;

class Autoloader {
    public function __construct() {
        spl_autoload_register( [ $this, 'autoload' ] );
    }

    public function autoload( $class ) {
        if ( strpos( $class, 'PharmaSure\\' ) !== 0 ) {
            return;
        }

        $path = PHARMASURE_CORE_PATH . 'src/';
        $class = str_replace( 'PharmaSure\\', '', $class );
        $class = str_replace( '\\', '/', $class );
        
        $file = $path . $class . '.php';
        
        if ( file_exists( $file ) ) {
            require $file;
        }
    }
}
