<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_orders table
 */
class OrderMigrate extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return array( 'MShopAddLocaleData' );
	}


	/**
	 * Returns the list of task names which depends on this task.
	 *
	 * @return string[] List of task names
	 */
	public function getPostDependencies() : array
	{
		return [];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop order data', 0 );

		if( ( $langs = $this->additional->getConfig()->get( 'setup/ai-migrate-multishop/languages' ) ) === null )
		{
			throw new \Exception( '
				Configuration for required sys_language.id to two letter ISO language codes map
				is missing in "setup/ai-migrate-multishop/languages"
			' );
		}

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$conn->create( 'DELETE FROM "mshop_order"' )->execute()->finish();

		$select = '
			SELECT o.*, f."username"
			FROM "tx_multishop_orders" o
			LEFT JOIN "fe_users" f ON o."customer_id" = f."uid"
		';
		$insert = '
			INSERT INTO "mshop_order"
			SET "siteid" = ?, "id" = ?, "sitecode" = ?, "type" = ?, "datepayment" = ?, "statuspayment" = ?, "langid" = ?,
				"currencyid" = ?, "price" = ?, "costs" = ?, "rebate" = ?, "tax" = ?, "taxflag" = ?, "customerid" = ?, "comment" = ?,
				"cdate" = ?, "cmonth" = ?, "cweek" = ?, "cwday" = ?, "chour" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$taxFlag = $this->additional->getConfig()->get( 'mshop/price/taxflag', 1 );
		$siteCode = 'default';
		$siteId = 1;

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$result = $msconn->create( $select )->execute();

		while( ( $row = $result->fetch() ) !== false )
		{
			if( !isset( $langs[$row['language_id']] ) )
			{
				$msg = 'Two letter ISO language code for sys_language ID "%1$s" is missing in "setup/ai-migrate-multishop/languages" configuration!';
				throw new \Exception( sprintf( $msg, $row['language_id'] ) );
			}

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $siteCode );
			$stmt->bind( 4, $row['by_phone'] ? 'phone' : 'web' );
			$stmt->bind( 5, $row['orders_paid_timestamp'] ? date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ): null );
			$stmt->bind( 6, $row['paid'] ? \Aimeos\MShop\Order\Item\Base::PAY_RECEIVED : -1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 7, $langs[$row['language_id']] );
			$stmt->bind( 8, $row['store_currency'] ?: $row['customer_currency'] );
			$stmt->bind( 9, $row['grand_total'] - $row['payment_method_costs'] - $row['shipping_method_costs'] );
			$stmt->bind( 10, $row['payment_method_costs'] + $row['shipping_method_costs'] );
			$stmt->bind( 11, $row['discount'] );
			$stmt->bind( 12, $row['grand_total'] - $row['grand_total_excluding_vat'] );
			$stmt->bind( 13, $taxFlag, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 14, $row['customer_id'] );
			$stmt->bind( 15, $row['customer_comments'] );
			$stmt->bind( 16, date( 'Y-m-d', $row['crdate'] ) );
			$stmt->bind( 17, date( 'Y-m', $row['crdate'] ) );
			$stmt->bind( 18, date( 'Y-W', $row['crdate'] ) );
			$stmt->bind( 19, date( 'w', $row['crdate'] ) );
			$stmt->bind( 20, date( 'H', $row['crdate'] ) );
			$stmt->bind( 21, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 22, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$stmt->bind( 23, $row['username'] ?: $row['ip_address'] );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-order' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
