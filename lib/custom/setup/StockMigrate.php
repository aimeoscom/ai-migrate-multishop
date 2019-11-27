<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the stock data from tx_multishop_products table
 */
class StockMigrate extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return array( 'MShopAddLocaleData', 'ProductMigrate' );
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
		$this->msg( 'Migrating Multishop stock data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-stock' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$conn->create( 'DELETE FROM "mshop_stock"' )->execute()->finish();

		$select = 'SELECT "products_id", "products_quantity" FROM "tx_multishop_products"';
		$insert = '
			INSERT INTO "mshop_stock"
			SET "siteid" = ?, "type" = ?, "productcode" = ?, "stocklevel" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$siteId = 1;

		$result = $msconn->create( $select )->execute();

		while( ( $row = $result->fetch() ) !== false )
		{
			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, 'default' );
			$stmt->bind( 3, $row['products_id'] );
			$stmt->bind( 4, $row['products_quantity'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 5, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 6, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 7, 'ai-migrate-multishop' );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-stock' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
