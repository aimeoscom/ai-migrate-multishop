<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the address data from tx_multishop_orders table
 */
class MultishopOrderMigrateAddress extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopOrderMigrateBase'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop order address data', 0 );

		if( ( $langs = $this->additional->getConfig()->get( 'setup/ai-migrate-multishop/languages' ) ) === null )
		{
			throw new \Exception( '
				Configuration for required sys_language.id to two letter ISO language codes map
				is missing in "setup/ai-migrate-multishop/languages"
			' );
		}

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$select = '
			SELECT o.*, f."username", bc."cn_iso_2" AS billing_cc, dc."cn_iso_2" AS delivery_cc
			FROM "tx_multishop_orders" o
			LEFT JOIN "fe_users" f ON o."customer_id" = f."uid"
			LEFT JOIN "static_countries" bc ON o."billing_country" = bc."cn_short_en"
			LEFT JOIN "static_countries" dc ON o."delivery_country" = dc."cn_short_en"
		';
		$insert = '
			INSERT INTO "mshop_order_base_address"
			SET "siteid" = ?, "baseid" = ?, "addrid" = ?, "type" = ?, "salutation" = ?, "company" = ?, "vatid" = ?,
				"firstname" = ?, "lastname" = ?, "address1" = ?, "address2" = ?, "address3" = ?, "postal" = ?, "city" = ?,
				"state" = ?, "langid" = ?, "countryid" = ?, "telephone" = ?, "telefax" = ?, "email" = ?,
				"ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$result = $msconn->create($select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			if( !isset( $langs[$row['language_id']] ) )
			{
				$msg = 'Two letter ISO language code for sys_language ID "%1$s" is missing in "setup/ai-migrate-multishop/languages" configuration!';
				throw new \Exception( sprintf( $msg, $row['language_id'] ) );
			}

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, (string) $row['billing_address_number'] );
			$stmt->bind( 4, 'payment' );
			$stmt->bind( 5, $this->salutation( $row['billing_gender'] ) );
			$stmt->bind( 6, (string) $row['billing_company'] );
			$stmt->bind( 7, (string) $row['billing_vat_id'] );
			$stmt->bind( 8, (string) $row['billing_first_name'] . ( $row['billing_middle_name'] ? ' ' . $row['billing_middle_name'] : '' ) );
			$stmt->bind( 9, (string) $row['billing_last_name'] );
			$stmt->bind( 10, (string) $row['billing_address'] );
			$stmt->bind( 11, (string) $row['billing_building'] );
			$stmt->bind( 12, (string) $row['billing_room'] );
			$stmt->bind( 13, (string) $row['billing_zip'] );
			$stmt->bind( 14, (string) $row['billing_city'] );
			$stmt->bind( 15, (string) $row['billing_region'] );
			$stmt->bind( 16, $langs[$row['language_id']] );
			$stmt->bind( 17, (string) $row['billing_cc'] );
			$stmt->bind( 18, $this->telephone( $row['billing_telephone'], $row['billing_mobile'] ) );
			$stmt->bind( 19, $this->telephone( $row['billing_fax'] ) );
			$stmt->bind( 20, (string) $row['billing_email'] );
			$stmt->bind( 21, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 22, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$stmt->bind( 23, (string) $row['username'] ?: $row['ip_address'] );

			$stmt->execute()->finish();

			if( $row['billing_company'] != $row['delivery_company']
				|| $row['billing_vat_id'] != $row['delivery_vat_id']
				|| $row['billing_first_name'] != $row['delivery_first_name']
				|| $row['billing_last_name'] != $row['delivery_last_name']
				|| $row['billing_address'] != $row['delivery_address']
				|| $row['billing_zip'] != $row['delivery_zip']
				|| $row['billing_city'] != $row['delivery_city']
				|| $row['billing_email'] != $row['delivery_email'] )
			{
				$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$stmt->bind( 3, (string) $row['delivery_address_number'] );
				$stmt->bind( 4, 'delivery' );
				$stmt->bind( 5, $this->salutation( $row['delivery_gender'] ) );
				$stmt->bind( 6, (string) $row['delivery_company'] );
				$stmt->bind( 7, (string) $row['delivery_vat_id'] );
				$stmt->bind( 8, (string) $row['delivery_first_name'] . ( $row['delivery_middle_name'] ? ' ' . $row['delivery_middle_name'] : '' ) );
				$stmt->bind( 9, (string) $row['delivery_last_name'] );
				$stmt->bind( 10, (string) $row['delivery_address'] );
				$stmt->bind( 11, (string) $row['delivery_building'] );
				$stmt->bind( 12, (string) $row['delivery_room'] );
				$stmt->bind( 13, (string) $row['delivery_zip'] );
				$stmt->bind( 14, (string) $row['delivery_city'] );
				$stmt->bind( 15, (string) $row['delivery_region'] );
				$stmt->bind( 16, (string) $langs[$row['language_id']] );
				$stmt->bind( 17, (string) $row['delivery_cc'] );
				$stmt->bind( 18, $this->telephone( $row['delivery_telephone'], $row['delivery_mobile'] ) );
				$stmt->bind( 19, $this->telephone( $row['delivery_fax'] ) );
				$stmt->bind( 20, (string) $row['delivery_email'] );
				$stmt->bind( 21, date( 'Y-m-d H:i:s', $row['crdate'] ) );
				$stmt->bind( 22, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
				$stmt->bind( 23, (string) $row['username'] ?: $row['ip_address'] );

				$stmt->execute()->finish();
			}
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-order' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}


	protected function salutation( string $value )
	{
		switch( $value )
		{
			case '0':
			case 'm':
				return 'mr';
			case '1':
			case 'f':
				return 'mrs';
		}

		return '';
	}


	protected function telephone( string $first, string $second = null ) : string
	{
		if( ( $result = preg_replace( '/[^0-9]/', '', $first ) ) != '' ) {
			return (string) $result;
		}

		if( $second !== null ) {
			return (string) preg_replace( '/[^0-9]/', '', $second );
		}

		return '';
	}
}
