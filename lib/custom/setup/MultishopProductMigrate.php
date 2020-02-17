<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_products table
 */
class MultishopProductMigrate extends \Aimeos\MW\Setup\Task\Base
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
		$this->msg( 'Migrating Multishop product data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-product' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_product"' )->execute()->finish();

		$select = '
			SELECT p.*, pd."products_name"
			FROM "tx_multishop_products" p
			LEFT JOIN "tx_multishop_products_description" pd ON p."products_id" = pd."products_id" AND pd."language_id" = 0
		';
		$insert = '
			INSERT INTO "mshop_product"
			SET "siteid" = ?, "id" = ?, "type" = ?, "code" = ?, "label" = ?, "start" = ?, "end" = ?,
				"status" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $row['event_starttime'] ? 'event' : 'default' );
			$stmt->bind( 4, $row['sku_code'] ?: $row['products_id'] );
			$stmt->bind( 5, $row['products_name'] ?: '' );
			$stmt->bind( 6, $row['event_starttime'] ? date( 'Y-m-d H:i:s', $row['event_starttime'] ) : null );
			$stmt->bind( 7, $row['event_endtime'] ? date( 'Y-m-d H:i:s', $row['event_endtime'] ) : null );
			$stmt->bind( 8, $row['products_status'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 9, date( 'Y-m-d H:i:s', $row['products_last_modified'] ?: $row['products_date_added'] ) );
			$stmt->bind( 10, date( 'Y-m-d H:i:s', $row['products_date_added'] ) );
			$stmt->bind( 11, 'ai-migrate-multishop' );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-product' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
