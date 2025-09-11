<?php

namespace membercore\cli\commands;

class Transaction {



	protected $faker;

	public function __construct() {
		$this->faker = \Faker\Factory::create();
	}

	/**
	 * Prints a greeting.
	 *
	 * ## OPTIONS
	 */
	public function expire( $args, $assoc_args ) {
		global $wpdb;
		$meco_db = \MecoDb::fetch();

		foreach ( $args as $id ) {
			$txn = new \MecoTransaction( $id );
			if ( $txn->id === 0 ) { continue;
			}

			$txn->expires_at = \MecoUtils::ts_to_mysql_date( time() - \MecoUtils::days( 1 ) );
			$txn->store();

			\MecoEvent::record( 'transaction-expired', $txn );

			if ( $txn->subscription() ) {
				\MecoEvent::record( 'recurring-transaction-expired', $txn );
			} else {
				 \MecoEvent::record( 'non-recurring-transaction-expired', $txn );
			}

			\MecoHooks::do_action( 'meco-txn-expired', $txn, $txn->sub_status ); // DEPRECATED
			\MecoHooks::do_action( 'meco-transaction-expired', $txn, $txn->sub_status );
		}

	}
}
