<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the product attribute references from tx_multishop_products_attributes table
 */
class MultishopProductMigrateAttribute extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopProductMigrate', 'MultishopAttributeMigrate'];
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
		';
		$plinsert = '
			INSERT INTO "mshop_product_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?,
				"mtime" = ?, "ctime" = ?, "editor" = ?, "config" = ?,
				"type" = \'default\', "domain" = \'attribute\', "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$plstmt->bind( 2, $row['products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$plstmt->bind( 3, 'attribute|default|' . $row['options_values_id'] );
			$plstmt->bind( 4, $row['options_values_id'] );
			$plstmt->bind( 5, $row['sort_order_option_value'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$plstmt->bind( 6, date( 'Y-m-d H:i:s' ) );
			$plstmt->bind( 7, date( 'Y-m-d H:i:s' ) );
			$plstmt->bind( 8, 'ai-migrate-multishop' );
			$plstmt->bind( 9, '{}' );

			$plstmt->execute()->finish();
		}

		$pconn->create( 'COMMIT' )->execute()->finish();

		$this->release( $pconn, 'db-product' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
