<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the price data from tx_multishop_products table
 */
class MultishopProductMigratePrice extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopProductMigrate'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop product price data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-product' );
		$conn = $this->acquire( 'db-price' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_product_list" WHERE domain=\'price\'' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_price" WHERE domain=\'product\'' )->execute()->finish();

		$select = '
			SELECT p.products_id, p.products_price, t."rate"
			FROM "tx_multishop_products" p
			JOIN "tx_multishop_taxes" t ON p."tax_id" = t."tax_id"
		';
		$plinsert = '
			INSERT INTO "mshop_product_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?,
				"mtime" = ?, "ctime" = ?, "editor" = ?, "config" = ?,
				"type" = \'default\', "domain" = \'price\', "status" = 1
		';
		$insert = '
			INSERT INTO "mshop_price"
			SET "siteid" = ?, "label" = ?, "currencyid" = ?, "value" = ?, "taxrate" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'product\', "quantity" = 1, "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, number_format( $row['products_price'], 2, '.', '' ) . ' - ' . $row['rate'] );
			$stmt->bind( 3, 'EUR' );
			$stmt->bind( 4, number_format( $row['products_price'], 2, '.', '' ) );
			$stmt->bind( 5, $row['rate'] );
			$stmt->bind( 6, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 7, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 8, 'ai-migrate-multishop' );

			$stmt->execute()->finish();
			$id = $this->getLastId( $conn, 'db-price' );

			$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$plstmt->bind( 2, $row['products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$plstmt->bind( 3, 'price|default|' . $id );
			$plstmt->bind( 4, $id );
			$plstmt->bind( 5, 0, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$plstmt->bind( 6, date( 'Y-m-d H:i:s' ) );
			$plstmt->bind( 7, date( 'Y-m-d H:i:s' ) );
			$plstmt->bind( 8, 'ai-migrate-multishop' );
			$plstmt->bind( 9, '{}' );

			$plstmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();
		$pconn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-price' );
		$this->release( $pconn, 'db-product' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
