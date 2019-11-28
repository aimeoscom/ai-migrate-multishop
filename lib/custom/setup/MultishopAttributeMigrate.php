<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the tx_multishop_products_options_values table
 */
class MultishopAttributeMigrate extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopAttributeMigrateType'];
	}


	/**
	 * Returns the list of task names which depends on this task.
	 *
	 * @return string[] List of task names
	 */
	public function getPostDependencies() : array
	{
		return ['MShopAddAttributeDataDefault'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop attribute data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-attribute' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$conn->create( 'DELETE FROM "mshop_attribute"' )->execute()->finish();

		$select = '
			SELECT  pov.products_options_values_id, pov.products_options_values_name, po.products_options_name, po.sort_order
			FROM tx_multishop_products_options_values pov
			LEFT JOIN tx_multishop_products_options_values_to_products_options povpo ON povpo.products_options_values_id = pov.products_options_values_id
			LEFT JOIN tx_multishop_products_options po ON po.products_options_id = povpo.products_options_id AND po.language_id = 0
			WHERE pov.language_id = 0
		';
		$insert = '
			INSERT INTO "mshop_attribute"
			SET "siteid" = ?, "id" = ?, "key" = ?, "type" = ?, "code" = ?, "label" = ?, "pos" = ?,
				"mtime" = ?, "ctime" = ?, "editor" = ?, "domain" = \'product\', "status" = 1
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$siteId = 1;

		$result = $msconn->create( $select )->execute();

		while( ( $row = $result->fetch() ) !== false )
		{
			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['products_options_values_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, md5( $row['products_options_values_name'] ) );
			$stmt->bind( 4, $row['products_options_name'] ?? '' );
			$stmt->bind( 5, $row['products_options_values_name'] );
			$stmt->bind( 6, $row['products_options_values_name'] );
			$stmt->bind( 7, $row['sort_order'] ?? 0 );
			$stmt->bind( 8, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 9, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 10, 'ai-migrate-multishop' );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-attribute' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
