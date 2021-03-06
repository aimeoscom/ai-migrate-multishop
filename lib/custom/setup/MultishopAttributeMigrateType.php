<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the tx_multishop_products_options table
 */
class MultishopAttributeMigrateType extends \Aimeos\MW\Setup\Task\Base
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
	 * Returns the list of task names which depends on this task.
	 *
	 * @return string[] List of task names
	 */
	public function getPostDependencies() : array
	{
		return ['MShopAddTypeData'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop attribute type data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-attribute' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$conn->create( 'DELETE FROM "mshop_attribute_type"' )->execute()->finish();

		$select = '
			SELECT products_options_id, products_options_name
			FROM tx_multishop_products_options
			WHERE language_id = 0
		';
		$insert = '
			INSERT INTO "mshop_attribute_type"
			SET "siteid" = ?, "id" = ?, "code" = ?, "label" = ?, "mtime" = ?, "ctime" = ?,
				"editor" = ?, "domain" = \'product\', "status" = 1
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['products_options_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $row['products_options_name'] );
			$stmt->bind( 4, $row['products_options_name'] );
			$stmt->bind( 5, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 6, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 7, 'ai-migrate-multishop' );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-attribute' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
