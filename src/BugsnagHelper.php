<?php

require_once(plugin_dir_path( __FILE__ ) . "../lib/Bugsnag/Autoload.php");

/**
 * Class BugsnagHelper
 *
 * This class is a wrapper class around the Bugsnag client.  It is used to manage Bugsnag construction
 * and breadcrumb sent to Bugsnag
 */
class BugsnagHelper
{

	/**
	 * @var string  The Bugsnag notification API key for the Bolt Bigcommerce project
	 */
	private static $apiKey = "a3dac826b1032a57884c31ed25e1769b";

	/**
	 * @var Bugsnag_Client  The native Bugsnag client that this object wraps
	 */
	private static $bugsnag;

	/**
	 * @var array   The metadata array that is used to set breadcrumbs
	 */
	private static $metaData = array( "breadcrumbs_" => array() );

	/**
	 * @var bool  Flag for whether is plugin is set in sandbox or production mode
	 */
	public static $is_sandbox_mode = true;

	/**
	 * Breadcrumbs to be added to the Bugsnag notification
	 *
	 * @param array $metaData an array in the format of [key => value] for breadcrumb data
	 */
	public static function addBreadCrumbs( $metaData )
	{
		static::$metaData['breadcrumbs_'] = array_merge( $metaData, static::$metaData['breadcrumbs_'] );
	}

	/**
	 * Initialize bugsnag, including setting app and release stage
	 */
	public static function initBugsnag()
	{
		if ( !static::$bugsnag ) {
			$bugsnag = new Bugsnag_Client( static::$apiKey );

			$bugsnag->setErrorReportingLevel( E_ERROR );
			$bugsnag->setAppVersion( WC_BOLT_GATEWAY_VERSION );
			$bugsnag->setReleaseStage( static::$is_sandbox_mode ? 'development' : 'production' );
			$bugsnag->setBatchSending( false );
			$bugsnag->setBeforeNotifyFunction( array( 'BugsnagHelper', 'beforeNotifyFunction' ) );

			static::$bugsnag = $bugsnag;

			// Hook up automatic error handling
			set_error_handler( array( static::$bugsnag, "errorHandler" ) );
			set_exception_handler( array( static::$bugsnag, "exceptionHandler" ) );
		}
	}

	/**
	 * Returns the bugsnag client for direct manipulation
	 *
	 * @return Bugsnag_Client
	 */
	public static function getBugsnag()
	{
		static::initBugsnag();
		return static::$bugsnag;
	}

	/**
	 * Method for coercing the Bugsnag_Error object, just prior to it being sent to the Bugsnag
	 * server.  Here, we use it to set the WooCommerce version, plugin version, and Trace-Id if
	 * available
	 *
	 * @param Bugsnag_Error $error
	 */
	public static function beforeNotifyFunction( $error )
	{
		$meta_data = array(
			'Bolt-Plugin-Version' => WC_BOLT_GATEWAY_VERSION,
			'Store-URL' => get_site_url()
		);

		if ( $trace_id = @$_SERVER['HTTP_X-BOLT-TRACE-ID'] ) {
			$meta_data['Bolt-Trace-Id'] = $trace_id;
		}
		$error->setMetaData( $meta_data );

		if ( count( static::$metaData['breadcrumbs_'] ) ) {
			$error->setMetaData( static::$metaData );
		}
	}
}
