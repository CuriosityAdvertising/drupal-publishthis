<?php
/**
 * This handles the input and response for the Publishing Endpoints from
 * the PublishThis platform
 * Current Actions
 * 1 - Verify
 * 2 - Publish
 * 3 - getCategories
 */


class Publishthis_Endpoint {
	private $obj_api;
	private $obj_publish;
	
	private $response_data;


	function __construct() {
		$this->obj_api = new Publishthis_API();
		$this->obj_publish = new Publishthis_Publish();

		$this->response_data=array();
		
	}

	/**
	 * Escape sprecial characters
	 */
	function escapeJsonString( $value ) { // list from www.json.org: (\b backspace, \f formfeed)
		$escapers = array( "\\", "/", "\"", "\n", "\r", "\t", "\x08", "\x0c" );
		$replacements = array( "\\\\", "\\/", "\\\"", "\\n", "\\r", "\\t", "\\f", "\\b" );
		$result = str_replace( $escapers, $replacements, $value );
		$escapers = array( '\":\"', '\",\"', '{\"', '\"}' );
		$replacements = array( '":"', '","', '{"', '"}' );
		$result = str_replace( $escapers, $replacements, $result );
		return $result;
	}

	/**
	 * Returns json response with failed status
	 */
	function sendFailure( $message ) {
		$obj = null;

		$obj->success = false;
		$obj->errorMessage = $this->escapeJsonString( $message );
		

		$this->sendJSON( $obj );
	}

	/**
	 * Returns json response with succeess status
	 */
	function sendSuccess( $message ) {
		$obj = null;

		$obj->success = true;
		$obj->errorMessage = null;
		
		foreach ($this->response_data as $key => $data)
		{
			$obj->$key = $data;
		}

		$this->sendJSON( $obj );
	}

	/*
	* Send object in JSON format
	*/
	private function sendJSON( $obj ) {
		header( 'Content-Type: application/json' );
		echo json_encode( $obj );
	}

	/**
	 * Verify endpoint action
	 */
	private function actionVerify() {
		//first check to make sure we have our api token
		$apiToken = $this->obj_api->_get_token( 'api_token' );

		if ( empty( $apiToken ) ) {

			$message = array(
				'message' => 'Verify Plugin Endpoint',
				'status' => 'error',
				'details' => 'Asked to verify our install at: '. date( "Y-m-d H:i:s" ) . ' failed because api token is not filled out' );
			$this->obj_api->_log_message( $message, "1" );

			$this->sendFailure( "No API Key Entered" );
			return;
		}

		//then, make a easy call to our api that should return our basic info.
		$apiResponse = $this->obj_api->get_client_info();

		if ( empty( $apiResponse ) ) {
			$message = array(
				'message' => 'Verify Plugin Endpoint',
				'status' => 'error',
				'details' => 'Asked to verify our install at: '. date( "Y-m-d H:i:s" ) . ' failed because api token is not valid' );
			$this->obj_api->_log_message( $message, "1" );

			$this->sendFailure( "API Key Entered is not Valid" );
			return;
		}

		//if we got here, then it is a valid api token, and the plugin is installed.

		$message = array(
			'message' => 'Verify Plugin Endpoint',
			'status' => 'info',
			'details' => 'Asked to verify our install at: '. date( "Y-m-d H:i:s" ) );
		$this->obj_api->_log_message( $message, "2" );

		$this->sendSuccess( "" );
	}

	/**
	 * Publish endpoint action
	 * we get the information and then publish the feed
	 * here is the info being passed right now
	 * action: "publish",
	 * feedId: 123,
	 * templateId: 456,
	 * clientId: 789,
	 * userId: 21,
	 * publishDate: Date
	 *
	 * @param integer $feedId
	 */
	private function actionPublish( $feedId ) {
		
		if ( empty( $feedId ) ) {
			$this->sendFailure( "Empty feed id" );
			return;
		}

		$arrFeeds = array();
		$arrFeeds[] = $feedId;

		//ok, now go try and publish the feed passed in

		try{
			$this->obj_publish->publish_specific_feeds( $arrFeeds );
		}catch( Exception $ex ) {
			//looks like there was an internal error in publish, we will need to send a failure.
			//no need to log here, as our internal methods have all ready logged it

			$this->sendFailure( $ex->getMessage() );
			return;
		}

		$this->sendSuccess( "published" );
		return;
	}	


	/**
	 * GetCategories endpoint action
	 */
	private function actionGetCategories( $parent_name ) {
	
		//$name = 'publish_this_categories';
		$myvoc = taxonomy_vocabulary_machine_name_load($parent_name);
		$tree = taxonomy_get_tree($myvoc->vid);
	

		foreach ($tree as $cat) {
			$mycat=array('id' => $cat->tid, 'name' => $cat->name);
			array_push ($this->response_data['categories'], $mycat);
		}
		
		return;
	}
	
	/**
	 * Process request main function
	 */
	function process_request() {
		global $pt_settings_value;

		try{
			$bodyContent = file_get_contents( 'php://input' );

			$this->obj_api->_log_message( array( 'message' => 'Endpoint Request', 'status' => 'info', 'details' => $bodyContent ), "2" );

			$arrEndPoint = json_decode( $bodyContent, true );
			$action = $arrEndPoint["action"];

			switch ( $action ) {
			case "verify":
				$this->actionVerify();
				break;

			case "publish":
				if( $pt_settings_value['curated_publish'] != 'publishthis_import_from_manager' ) {
					$this->sendFailure( "Publishing through CMS is disabled" );
					return;
				}
				$feedId = intval( $arrEndPoint["feedId"], 10 );
				$this->actionPublish( $feedId );
				break;

			case "getCategories":
				if( false ) {
					$this->sendFailure( "getCategories not supported" );
					return;
				}

				$this->response_data['categories']= array();
				$this->actionGetCategories('publish_this_categories');
				//$this->obj_publish ...  get publish get_publishing_actions(); foreach ( $this->publishing_actions as $action ) { $props = unserialize($action['value']);
				//$this->actionGetCategories($??['taxonomy_group']);

				$this->sendSuccess( "categories fetched" );
				break;

			default:
				$this->sendFailure( "Empty or bad request made to endpoint" );
				break;
			}

		} catch( Exception $ex ) {
			//we will log this to the pt logger, but we always need to send back a failure if this occurs

			$this->sendFailure( $ex->getMessage() );
		}
		
		return;
	}
}

?>
