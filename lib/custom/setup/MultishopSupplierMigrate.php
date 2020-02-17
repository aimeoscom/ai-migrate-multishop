<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_manufacturers table
 */
class MultishopSupplierMigrate extends \Aimeos\MW\Setup\Task\Base
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
		$this->msg( 'Migrating Multishop supplier data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-supplier' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_supplier"' )->execute()->finish();

		$select = 'SELECT * FROM "tx_multishop_manufacturers"';
		$insert = '
			INSERT INTO "mshop_supplier"
			SET "siteid" = ?, "id" = ?, "code" = ?, "label" = ?, "status" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['manufacturers_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $row['manufacturers_name'] );
			$stmt->bind( 4, $row['manufacturers_name'] );
			$stmt->bind( 5, $row['status'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 6, date( 'Y-m-d H:i:s', $row['last_modified'] ?: $row['date_added'] ) );
			$stmt->bind( 7, date( 'Y-m-d H:i:s', $row['date_added'] ) );
			$stmt->bind( 8, 'ai-migrate-multishop' );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-supplier' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
