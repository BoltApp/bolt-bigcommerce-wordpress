<?php

namespace BoltBigcommerce;

class BoltLogger {
	const LOGECHO = false;
	const IS_ENABLED = false;

	public static function write( $text ) {
		if ( ! self::IS_ENABLED ) {
			return;
		}
		$f = fopen( dirname( __FILE__ ) . "/../log.txt", "a" );
		fwrite( $f, date( "Y-m-d H:i:s" ) . " " . $text . "\r\n" );
		if ( self::LOGECHO ) {
			echo "<P>" . date( "Y-m-d H:i:s" ) . "<pre>{$text}</pre>\r\n";
		}
	}

}