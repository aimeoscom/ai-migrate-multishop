<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the catalog catalog references from tx_multishop_products_to_categories table
 */
class CatalogMigrateProduct extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'CatalogMigrate', 'ProductMigrate' );
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
		$this->msg( 'Migrating catalog product references data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-catalog' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_catalog_list" WHERE domain=\'product\'' )->execute()->finish();

		$select = '
			SELECT categories_id, products_id, ANY_VALUE(sort_order) AS sort_order
			FROM "tx_multishop_products_to_categories"
			GROUP BY categories_id, products_id
			LIMIT 1000 OFFSET :offset
		';
		$plinsert = '
			INSERT INTO "mshop_catalog_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'product\', "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );

		$siteId = 1;
		$start = 0;

		do
		{
			$count = 0;
			$sql = str_replace( ':offset', $start, $select );
			$result = $msconn->create( $sql )->execute();

			while( ( $row = $result->fetch() ) !== false )
			{
				$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$plstmt->bind( 2, $row['categories_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$plstmt->bind( 3, 'default|product|' . $row['products_id'] );
				$plstmt->bind( 4, $row['products_id'] );
				$plstmt->bind( 5, $row['sort_order'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$plstmt->bind( 6, date( 'Y-m-d H:i:s' ) );
				$plstmt->bind( 7, date( 'Y-m-d H:i:s' ) );
				$plstmt->bind( 8, 'ai-migrate-multishop' );

				$plstmt->execute()->finish();
				$count++;
			}

			$start += $count;
		}
		while( $count > 0 );

		$pconn->create( 'COMMIT' )->execute()->finish();

		$this->release( $pconn, 'db-catalog' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
