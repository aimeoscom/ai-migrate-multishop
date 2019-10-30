<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_manufacturers table
 */
class SupplierMigrate extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'MShopAddLocaleData' );
	}


	/**
	 * Returns the list of task names which depends on this task.
	 *
	 * @return string[] List of task names
	 */
	public function getPostDependencies()
	{
		return [];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop supplier base data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-supplier' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$conn->create( 'DELETE FROM "mshop_supplier"' )->execute()->finish();

		$select = 'SELECT * FROM "tx_multishop_manufacturers" LIMIT 1000 OFFSET :offset';
		$insert = '
			INSERT INTO "mshop_supplier"
			SET "siteid" = ?, "id" = ?, "code" = ?, "label" = ?, "status" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$siteId = 1;
		$start = 0;

		do
		{
			$count = 0;
			$sql = str_replace( ':offset', $start, $select );
			$result = $msconn->create( $sql )->execute();

			while( ( $row = $result->fetch() ) !== false )
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
				$count++;
			}

			$start += $count;
		}
		while( $count > 0 );

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-supplier' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
