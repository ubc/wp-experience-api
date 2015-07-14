<?php
/**
 * Think of this class as a "queue row object" class
 */
class WP_Experience_Queue_Object {
	const MYSQL_DATETIME_FORMAT = 'Y-m-d H:i:s';
	public $table_name;
	public $id = null;
	public $tries = 1;
	public $last_try_time = null;
	public $statement = '';
	public $lrs_info = '';
	public $created = null;

	/**
	 * Creates a WP_Experience_Queue_Object instance minimally requires table_name
	 */
	public function __construct( $table_name ) {
		if ( empty( $table_name ) ) {
			throw new Exception( 'Queue table name is empty!' );
		}
		$this->table_name = $table_name;
		$this->last_try_time = date( WP_Experience_Queue_Object::MYSQL_DATETIME_FORMAT );
	}

	/**
	 * Another way to instantiate queue object with statement and lrs_info
	 *
	 * @param  String $table_name the queue's table name
	 * @param  TinCan\Statement $statement  xAPI statement
	 * @param  Array $lrs_info LRS info so that we can know where to send statement to
	 * @return WP_Experience_Queue_Object
	 */
	public static function with_statement_lrs_info( $table_name, $statement, $lrs_info ) {
		try {
			$instance = new self( $table_name );
		} catch ( Exception $e ) {
			error_log( 'Exception thrown: ' . $e->getMessage() );
			return false;
		}

		//general checking of argument validity
		if ( empty( $statement ) || ! $statement instanceof TinCan\Statement ) {
			return false;
		}
		if ( empty( $lrs_info ) || ! isset( $lrs_info['endpoint'] ) || ! isset( $lrs_info['version'] ) || ! isset( $lrs_info['username'] ) || ! isset( $lrs_info['password'] ) ) {
			return false;
		}

		//ok, setup instance info so we can send it back!
		$instance->statement = $statement;
		$instance->lrs_info = array(
				'endpoint' => $lrs_info['endpoint'],
				'version' => $lrs_info['version'],
				'username' => $lrs_info['username'],
				'password' => $lrs_info['password'],
			);

		return $instance;
	}

	/**
	 * setter for lrs_info property
	 *
	 * @param String $endpoint LRS endpoint
	 * @param String $version  xAPI version
	 * @param String $username lrs basic auth username
	 * @param String $password lrs basic auth password
	 */
	public function set_lrs_info( $endpoint, $version, $username, $password ) {
		//super basic checks. can add more later.
		if ( empty( $endpoint ) || empty( $version ) || empty( $username ) || empty( $password ) ) {
			return false;
		}

		$this->lrs_info = array(
				'endpoint' => $endpoint,
				'version' => $version,
				'username' => $username,
				'password' => $password,
			);
	}

	/**
	 * Gets a single row from the top of the queue
	 *
	 * This function does a SELECT and then DELETE from that row.
	 *
	 * @return mixed if no rows, return false, else a queue object
	 */
	public static function get_row( $table_name ) {
		global $wpdb;

		//simple check to make sure that table_name is not emtpy
		if ( empty( $table_name ) ) {
			return false;
		}

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
		$deleted = $wpdb->delete( $table_name, array( 'id' => $row->id ), array( '%d' ) );

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
		$table_name = $this->table_name;

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
		$this->tries = $this->tries++;
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
		$table_name = $this->table_name;

		$statement = serialize( $this->statement );
		$lrs_info = serialize( $this->lrs_info );
		$ltt = $this->last_try_time;
		$tries = $this->tries;

		$sql = "INSERT INTO {$table_name}
						('tries', 'last_try_time', 'statement', 'lrs_info')
						VALUES ('%d', '%d', '%s', '%s')";
		if ( $prepared ) {
			return $wpdb->prepare( $sql,
				$tries,
				$ltt,
				$statement,
				$lrs_info
			);
		} else {
			return sprintf( $sql, $tries, $ltt, $statement, $currently_retrying );
		}
	}

	/**
	 * helper function to init WP_Experience_Queue_Object property values from stdObject
	 *
	 * @param  stdObject $data probably created from wpdb->get_row()
	 * @return mixed WP_Experience_Queue_Object if works, false otherwise
	 */
	private static function with_db_row_object( $data ) {
		$instance = new self();
		if ( is_object( $data ) ) {
				$instance->id = $data->id;
				$instance->tries = $data->tries;
				$instance->last_try_time = $data->last_try_time;
				$instance->statement = unserialize( $data->statement );
				$instance->lrs_info = unserialize( $data->lrs_info );
				$instance->created = $data->created;

				return $instance;
		}
		return false;
	}
}
