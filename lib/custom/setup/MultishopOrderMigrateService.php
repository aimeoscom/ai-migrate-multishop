<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the service data from tx_multishop_orders table
 */
class MultishopOrderMigrateService extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopOrderMigrate'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop order service data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$select = '
			SELECT o.*, f."username"
			FROM "tx_multishop_orders" o
			LEFT JOIN "fe_users" f ON o."customer_id" = f."uid"
		';
		$insert = '
			INSERT INTO "mshop_order_service"
			SET "siteid" = ?, "orderid" = ?, "type" = ?, "code" = ?, "name" = ?,
				"currencyid" = ?, "price" = ?, "costs" = ?, "rebate" = ?, "tax" = ?, "taxrate" = ?, "taxflag" = ?,
				"ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$taxFlag = $this->additional->getConfig()->get( 'mshop/price/taxflag', 1 );
		$siteId = 1;

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$result = $msconn->create( $select )->execute();

		while( ( $row = $result->fetch() ) !== false )
		{
			if( ( $taxes = unserialize( $row['orders_tax_data'] ) ) === false ) {
				$taxes = [];
			}

			$discount = $row['discount'] > 0 ? $row['discount'] - $row['coupon_discount_value'] : '0.00';

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, 'payment' );
			$stmt->bind( 4, $row['payment_method'] );
			$stmt->bind( 5, $row['payment_method_label'] );
			$stmt->bind( 6, $row['store_currency'] ?: $row['customer_currency'] );
			$stmt->bind( 7, -$discount );
			$stmt->bind( 8, $row['payment_method_costs'] );
			$stmt->bind( 9, $discount );
			$stmt->bind( 10, $taxes['payment_tax'] ?? '0.0000' );
			$stmt->bind( 11, json_encode( ['' => $taxes['payment_total_tax_rate'] ?? '0.00'] ) );
			$stmt->bind( 12, $taxFlag, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 13, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 14, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$stmt->bind( 15, $row['username'] ?: $row['ip_address'] );

			$stmt->execute()->finish();

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, 'delivery' );
			$stmt->bind( 4, $row['shipping_method'] );
			$stmt->bind( 5, $row['shipping_method_label'] );
			$stmt->bind( 6, $row['store_currency'] ?: $row['customer_currency'] );
			$stmt->bind( 7, '0.00' );
			$stmt->bind( 8, $row['shipping_method_costs'] );
			$stmt->bind( 9, '0.00' );
			$stmt->bind( 10, $taxes['shipping_tax'] ?? '0.0000' );
			$stmt->bind( 11, json_encode( ['' => $taxes['shipping_total_tax_rate'] ?? '0.00'] ) );
			$stmt->bind( 12, $taxFlag, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
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
