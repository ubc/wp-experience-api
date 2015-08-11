<?php
/**
 * Think of this class as a "queue row object" class
 *
 * NOTES:
 * - this class might have been better as an inner class of WP_Experience_API, but since php doesn't do inner classes, we split it off.
 * - this class is intimately linked with WP_Experience_API...
 * - need to think through and clean up which class has what responsibility, etc....
 */
class WP_Experience_Queue_Object {
	const MYSQL_DATETIME_FORMAT = 'Y-m-d H:i:s';
	const LRS_ENDPOINT = 'endpoint';
	const LRS_VERSION = 'version';
	const LRS_USERNAME = 'username';
	const LRS_PASSWORD = 'password';
	public $table_name;
	public $id = null;
	public $tries = 1;
	public $last_try_time = null;
	public $statement = null;
	public $lrs_info = -1;
	public $created = null;

	/**
	 * Creates a WP_Experience_Queue_Object instance minimally requires table_name
	 */
	public function __construct() {
		$this->table_name = self::get_queue_table_name();
		$this->last_try_time = date( WP_Experience_Queue_Object::MYSQL_DATETIME_FORMAT );
	}

	public static function get_queue_table_name() {
		global $wpdb;

		//hardcoded tablename for security......  :-(
		$table_name = $wpdb->base_prefix . esc_sql( WP_XAPI_TABLE_NAME );

		return $table_name;
	}

	/**
	 * Another way to instantiate queue object with statement and lrs_info
	 *
	 * @param  String $table_name the queue's table name
	 * @param  TinCan\Statement $statement  xAPI statement
	 * @param  Array $lrs_info LRS info so that we can know where to send statement to
	 * @return WP_Experience_Queue_Object
	 */
	public static function with_statement_lrs_info( $statement, $lrs_info ) {
		try {
			$instance = new self();
		} catch ( Exception $e ) {
			error_log( 'Exception thrown: ' . $e->getMessage() );
			return false;
		}

		//general checking of argument validity
		if ( empty( $statement ) || ! $statement instanceof TinCan\Statement ) {
			return false;
		}
		if ( (int) $lrs_info !== $lrs_info ) {
			return false;
		}

		//ok, setup instance info so we can send it back!
		$instance->statement = $statement;
		$instance->lrs_info = $lrs_info;

		return $instance;
	}

	/**
	 * Gets a single row from the top of the queue
	 *
	 * This function does a SELECT and then DELETE from that row.
	 *
	 * @return mixed if no rows, return false, else a queue object
	 */
	public static function get_row() {
		global $wpdb;
		$table_name = self::get_queue_table_name();

		//query to get row!
		$query = "SELECT * FROM {$table_name} ORDER BY id ASC limit 1";
		$row = $wpdb->get_row( $query );

		//check to see it returned something!
		if ( empty( $row ) ) {
			return false;
		}

		//setup a queue object using the row data
		$instance = self::with_db_row_object( $row );

		//now delete the row we pulled
		if ( false !== $instance ) {
			$deleted = $wpdb->delete( $table_name, array( 'id' => $row->id ), array( '%d' ) );
		}

		if ( empty( $deleted ) ) {
			error_log( 'Something is wrong.  Why can we not delete the row after we pulled it?' );
		}

		return $instance;
	}

	/**
	 * saves the queue object into queue!
	 *
	 * @return Boolean true if works, false otherwise
	 */
	public function save_row() {
		global $wpdb;

		//get sql statement for inserting into queue
		$sql = $this->generate_insert_sql();

		//save to queue!
		return (bool) $wpdb->query( $sql );
	}

	/**
	 * Changes the appropriate fields so that another attempt to send is checked.
	 *
	 * @return void returns nothing
	 */
	public function tried_sending_again() {
		$this->tries = $this->tries + 1;
		$this->last_try_time = date( WP_Experience_Queue_Object::MYSQL_DATETIME_FORMAT );
	}

	/**
	 * Helper function to generate a SQL statement for inserting
	 *
	 * @param  Boolean $prepared if true, returns wpdb prepared statment, else string
	 * @return mixed returns false if error, string otherwise
	 */
	private function generate_insert_sql( $prepared = true ) {
		global $wpdb;
		$table_name = self::get_queue_table_name();

		$statement = serialize( $this->statement->asVersion( $this->lrs_info[ WP_Experience_Queue_Object::LRS_VERSION ] ) );
		$lrs_info = $this->lrs_info;
		$ltt = $this->last_try_time;
		$tries = $this->tries;

		$sql = "INSERT INTO {$table_name} (tries, last_try_time, statement, lrs_info)
					VALUES ('%d', '%s', '%s', '%d')";

		if ( $prepared ) {
			return $wpdb->prepare( $sql,
				$tries,
				$ltt,
				$statement,
				$lrs_info
			);
		} else {
			return sprintf( $sql, $tries, $ltt, $statement, $lrs_info );
		}
	}

	/**
	 * helper function to init WP_Experience_Queue_Object property values from stdObject
	 *
	 * @param  stdObject $data probably created from wpdb->get_row()
	 * @return mixed WP_Experience_Queue_Object if works, false otherwise
	 */
	public static function with_db_row_object( $data ) {
		$instance = new self();
		if ( is_object( $data ) ) {
				$instance->id = $data->id;
				$instance->tries = $data->tries;
				$instance->last_try_time = $data->last_try_time;
				$instance->statement = new TinCan\Statement( unserialize( $data->statement ) );
				$instance->lrs_info = $data->lrs_info;
				$instance->created = $data->created;

				return $instance;
		}
		return false;
	}
}
