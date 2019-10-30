<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the product attribute references from tx_multishop_products_attributes table
 */
class ProductMigrateAttribute extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'ProductMigrate', 'AttributeMigrate' );
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
		$this->msg( 'Migrating Multishop product attribute references data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-product' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_product_list" WHERE domain=\'attribute\'' )->execute()->finish();

		$select = '
			SELECT products_id, options_values_id, sort_order_option_value
			FROM "tx_multishop_products_attributes"
			LIMIT 1000 OFFSET :offset
		';
		$plinsert = '
			INSERT INTO "mshop_product_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'attribute\', "status" = 1
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
				$plstmt->bind( 2, $row['products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$plstmt->bind( 3, 'default|attribute|' . $row['options_values_id'] );
				$plstmt->bind( 4, $row['options_values_id'] );
				$plstmt->bind( 5, $row['sort_order_option_value'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
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

		$this->release( $pconn, 'db-product' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
