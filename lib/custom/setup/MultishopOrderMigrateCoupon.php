<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the coupon data from tx_multishop_orders table
 */
class MultishopOrderMigrateCoupon extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopOrderMigrateBase', 'MultishopOrderMigrate', 'MultishopOrderMigrateProduct', 'MultishopStockMigrate'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop order coupon data', 0 );

		$manager = \Aimeos\MShop::create( $this->additional, 'product' );
		$stockManager = \Aimeos\MShop::create( $this->additional, 'stock' );

		try {
			$prodId = $manager->findItem( 'rebate' )->getId();
		} catch( \Aimeos\MShop\Exception $e ) {
			$prodId = $manager->saveItem( $manager->createItem()->setCode( 'rebate' )->setType( 'default' )->setLabel( 'Rebate' ) )->getId();
		}

		try {
			$stockManager->findItem( 'rebate', [], 'product', 'default' );
		} catch( \Aimeos\MShop\Exception $e ) {
			$stockManager->saveItem( $stockManager->createItem()->setProductCode( 'rebate' )->setType( 'default' ), false );
		}

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$select = '
			SELECT
				o."orders_id", o."store_currency", o."customer_currency", o."coupon_discount_value",
				o."coupon_code", o."crdate", o."orders_last_modified", o."ip_address", f."username"
			FROM "tx_multishop_orders" o
			LEFT JOIN "fe_users" f ON o."customer_id" = f."uid"
			WHERE o."coupon_code" <> \'\' AND discount > 0
			GROUP BY
				o."orders_id", o."store_currency", o."customer_currency", o."coupon_discount_value",
				o."coupon_code", o."crdate", o."orders_last_modified", o."ip_address", f."username"
		';
		$opselect = '
			SELECT SUM(price) AS pprice, SUM(tax) AS ptax
			FROM "mshop_order_base_product"
			WHERE "baseid" = ?
		';
		$insert = '
			INSERT INTO "mshop_order_base_coupon"
			SET "siteid" = ?, "baseid" = ?, "ordprodid" = ?, "code" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';
		$pinsert = '
			INSERT INTO "mshop_order_base_product"
			SET "siteid" = ?, "baseid" = ?, "type" = ?, "prodid" = ?, "prodcode" = ?, "name" = ?, "description" = ?,
				"quantity" = ?, "currencyid" = ?, "price" = ?, "rebate" = ?, "tax" = ?, "taxrate" = ?, "taxflag" = ?,
				"pos" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';
		$sinsert = '
			INSERT INTO "mshop_order_status"
			SET "siteid" = ?, "parentid" = ?, "type" = ?, "value" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$opstmt = $conn->create( $opselect, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$sstmt = $conn->create( $sinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$pstmt = $conn->create( $pinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );

		$taxFlag = $this->additional->getConfig()->get( 'mshop/price/taxflag', 1 );
		$siteId = 1;

		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$result = $msconn->create( $select )->execute();

		while( $row = $result->fetch() )
		{
			$tax = $taxrate = 0;
			$prow = $opstmt->bind( 1, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT )->execute()->fetch();

			if( $prow['pprice'] )
			{
				if( $row['coupon_discount_value'] - $prow['pprice'] >= 0 ) {
					$tax = $prow['ptax'];
				} else {
					$tax = $prow['ptax'] / $prow['pprice'] * $row['coupon_discount_value'];
				}

				if( $taxFlag ) {
					$taxrate = $prow['ptax'] / ( $prow['pprice'] - $prow['ptax'] ) * 100;
				} else {
					$taxrate = $prow['ptax'] / $prow['pprice'] * 100;
				}
			}

			$pstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$pstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$pstmt->bind( 3, 'default' );
			$pstmt->bind( 4, $prodId );
			$pstmt->bind( 5, 'rebate' );
			$pstmt->bind( 6, 'Rebate' );
			$pstmt->bind( 7, '' ); //description
			$pstmt->bind( 8, 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$pstmt->bind( 9, $row['store_currency'] ?: $row['customer_currency'] );
			$pstmt->bind( 10, -$row['coupon_discount_value'] );
			$pstmt->bind( 11, $row['coupon_discount_value'] );
			$pstmt->bind( 12, -$tax );
			$pstmt->bind( 13, json_encode( ['' => (string) number_format( $taxrate, 2)] ) );
			$pstmt->bind( 14, $taxFlag, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$pstmt->bind( 15, 100, \Aimeos\MW\DB\Statement\Base::PARAM_INT ); // position
			$pstmt->bind( 16, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$pstmt->bind( 17, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$pstmt->bind( 18, $row['username'] ?: $row['ip_address'] );

			$pstmt->execute()->finish();

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $this->getLastId( $conn, 'db-order' ) );
			$stmt->bind( 4, $row['coupon_code'] );
			$stmt->bind( 5, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$stmt->bind( 7, $row['username'] ?: $row['ip_address'] );

			$stmt->execute()->finish();

			$sstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$sstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$sstmt->bind( 3, 'coupon-update' );
			$sstmt->bind( 4, 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$sstmt->bind( 5, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$sstmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$sstmt->bind( 7, $row['username'] ?: $row['ip_address'] );

			$sstmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-order' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
