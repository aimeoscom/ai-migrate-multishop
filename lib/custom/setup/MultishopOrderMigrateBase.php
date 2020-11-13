<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_orders table
 */
class MultishopOrderMigrateBase extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopMShopAddLocaleData'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop order base data', 0 );

		if( ( $langs = $this->additional->getConfig()->get( 'setup/ai-migrate-multishop/languages' ) ) === null )
		{
			throw new \Exception( '
				Configuration for required sys_language.id to two letter ISO language codes map
				is missing in "setup/ai-migrate-multishop/languages"
			' );
		}

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$conn->create( 'DELETE FROM "mshop_order_base"' )->execute()->finish();

		$select = '
			SELECT o.*, f."username"
			FROM "tx_multishop_orders" o
			LEFT JOIN "fe_users" f ON o."customer_id" = f."uid"
		';
		$insert = '
			INSERT INTO "mshop_order_base"
			SET "siteid" = ?, "id" = ?, "sitecode" = ?, "langid" = ?, "currencyid" = ?, "price" = ?, "costs" = ?,
			"rebate" = ?, "tax" = ?, "taxflag" = ?, "customerid" = ?, "comment" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$siteId = 1;
		$siteCode = 'default';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$result = $msconn->create( $select )->execute();

		while( $row = $result->fetch() )
		{
			if( !isset( $langs[$row['language_id']] ) )
			{
				$msg = 'Two letter ISO language code for sys_language ID "%1$s" is missing in "setup/ai-migrate-multishop/languages" configuration!';
				throw new \Exception( sprintf( $msg, $row['language_id'] ) );
			}

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $siteCode );
			$stmt->bind( 4, $langs[$row['language_id']] );
			$stmt->bind( 5, $row['store_currency'] ?: $row['customer_currency'] );
			$stmt->bind( 6, $row['grand_total'] - $row['payment_method_costs'] - $row['shipping_method_costs'] );
			$stmt->bind( 7, $row['payment_method_costs'] + $row['shipping_method_costs'] );
			$stmt->bind( 8, $row['discount'] );
			$stmt->bind( 9, $row['grand_total'] - $row['grand_total_excluding_vat'] );
			$stmt->bind( 10, $row['grand_total'] - $row['grand_total_excluding_vat'] < 0.01 ? 0 : 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 11, $row['customer_id'] );
			$stmt->bind( 12, $row['customer_comments'] );
			$stmt->bind( 13, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 14, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$stmt->bind( 15, $row['username'] ?: $row['ip_address'] );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-order' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
