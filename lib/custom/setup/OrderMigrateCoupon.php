<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the coupon data from tx_multishop_orders table
 */
class OrderMigrateCoupon extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return array( 'OrderMigrate', 'OrderMigrateProduct' );
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
		$this->msg( 'Migrating Multishop order coupon data', 0 );

		$manager = \Aimeos\MShop::create( $this->additional, 'product' );

		try {
			$prodId = $manager->findItem( 'rebate' );
		} catch( \Aimeos\MShop\Exception $e ) {
			$prodId = $manager->saveItem( $manager->createItem()->setCode( 'rebate' )->setType( 'default' ) )->getId();
		}

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$select = '
			SELECT o.*, f."username"
			FROM "tx_multishop_orders" o
			LEFT JOIN "fe_users" f ON o."customer_id" = f."uid"
			WHERE o."coupon_code" <> \'\'
		';
		$insert = '
			INSERT INTO "mshop_order_coupon"
			SET "siteid" = ?, "orderid" = ?, "ordprodid" = ?, "code" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';
		$pinsert = '
			INSERT INTO "mshop_order_product"
			SET "siteid" = ?, "orderid" = ?, "type" = ?, "prodid" = ?, "prodcode" = ?, "name" = ?, "description" = ?,
				"quantity" = ?, "currencyid" = ?, "price" = ?, "tax" = ?, "taxrate" = ?, "taxflag" = ?,
				"pos" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$pstmt = $conn->create( $pinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$taxFlag = $this->additional->getConfig()->get( 'mshop/price/taxflag', 1 );
		$orderid = null;
		$siteId = 1;

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$result = $msconn->create( $select )->execute();

		while( ( $row = $result->fetch() ) !== false )
		{
			$pstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$pstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$pstmt->bind( 3, 'default' );
			$pstmt->bind( 4, $prodId );
			$pstmt->bind( 5, 'rebate' );
			$pstmt->bind( 6, 'Rebate' );
			$pstmt->bind( 7, '' );
			$pstmt->bind( 8, 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$pstmt->bind( 9, $row['store_currency'] ?: $row['customer_currency'] );
			$pstmt->bind( 10, - $row['coupon_discount_value'] );
			$pstmt->bind( 11, '0.0000' );
			$pstmt->bind( 12, '0.00' ); // tax rate
			$pstmt->bind( 13, $taxFlag, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$pstmt->bind( 14, 100, \Aimeos\MW\DB\Statement\Base::PARAM_INT ); // position
			$pstmt->bind( 15, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$pstmt->bind( 16, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$pstmt->bind( 17, $row['username'] ?: $row['ip_address'] );

			$pstmt->execute()->finish();

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $this->getLastId( $conn, 'db-order' ) );
			$stmt->bind( 4, $row['coupon_code'] );
			$stmt->bind( 5, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$stmt->bind( 7, $row['username'] ?: $row['ip_address'] );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-order' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
