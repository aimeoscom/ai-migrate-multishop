<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the product data from tx_multishop_orders_products table
 */
class OrderMigrateProduct extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return array( 'OrderMigrate' );
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
		$this->msg( 'Migrating Multishop order product data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$select = '
			SELECT p.*, o."store_currency", o."customer_currency", o."crdate", o."orders_last_modified", o."ip_address", f."username"
			FROM "tx_multishop_orders_products" p
			JOIN "tx_multishop_orders" o ON o."orders_id" = p."orders_id"
			LEFT JOIN "fe_users" f ON o."customer_id" = f."uid"
			ORDER BY p.orders_id
		';
		$insert = '
			INSERT INTO "mshop_order_product"
			SET "id" = ?, "siteid" = ?, "orderid" = ?, "type" = ?, "prodid" = ?, "prodcode" = ?, "name" = ?, "description" = ?,
				"quantity" = ?, "currencyid" = ?, "price" = ?, "tax" = ?, "taxrate" = ?, "taxflag" = ?,
				"pos" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$taxFlag = $this->additional->getConfig()->get( 'mshop/price/taxflag', 1 );
		$orderid = null;
		$siteId = 1;

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$result = $msconn->create( $select )->execute();

		while( ( $row = $result->fetch() ) !== false )
		{
			if( ( $taxes = unserialize( $row['products_tax_data'] ) ) === false ) {
				$taxes = [];
			}

			if( $row['orders_id'] !== $orderid ) {
				$orderid = $row['orders_id'];
				$pos = 0;
			}

			$stmt->bind( 1, $row['orders_products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 4, 'default' );
			$stmt->bind( 5, $row['products_id'] );
			$stmt->bind( 6, $row['sku_code'] ?: $row['products_id'] );
			$stmt->bind( 7, $row['products_name'] );
			$stmt->bind( 8, (string) $row['products_description'] );
			$stmt->bind( 9, (int) $row['qty'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 10, $row['store_currency'] ?: $row['customer_currency'] );
			$stmt->bind( 11, $row['final_price'] );
			$stmt->bind( 12, $taxes['total_tax'] ?? '0.0000' );
			$stmt->bind( 13, $row['products_tax'] ); // tax rate
			$stmt->bind( 14, $taxFlag, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 15, $pos++, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 16, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 17, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$stmt->bind( 18, $row['username'] ?: $row['ip_address'] );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-order' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
