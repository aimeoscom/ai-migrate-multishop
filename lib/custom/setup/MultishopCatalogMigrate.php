<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_categories table
 */
class MultishopCatalogMigrate extends \Aimeos\MW\Setup\Task\Base
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
		$this->msg( 'Migrating Multishop catalog data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-catalog' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_catalog"' )->execute()->finish();


		$select = '
			SELECT c.categories_id, c.parent_id, c.sort_order, c.date_added, c.last_modified, c.status, cd.categories_name
			FROM tx_multishop_categories c
			LEFT JOIN tx_multishop_categories_description cd ON c.categories_id=cd.categories_id AND cd.language_id = 0
			ORDER BY c.parent_id, c.sort_order
		';
		$insert = '
			INSERT INTO "mshop_catalog"
			SET "siteid" = ?, "id" = ?, "code" = ?, "label" = ?, "status" = ?,
				"mtime" = ?, "ctime" = ?, "editor" = ?, "parentid" = ?,
				"level" = ?, "nleft" = ?, "nright" = ?, "config" = ?
		';
		$update = '
			UPDATE "mshop_catalog" SET "parentid" = ? WHERE "id" <> ? AND "siteid" = ? AND "parentid" = 0
		';

		$map = [];


		$result = $msconn->create( $select )->execute();

		while( $row = $result->fetch() )
		{
			$map[$row['categories_id']] = $row;

			if( isset( $map[$row['parent_id']] ) ) {
				$map[$row['parent_id']]['children'][] = $row['categories_id'];
			}
		}


		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$siteId = 1; $nleft = 2; $nright = 3;

		while( ( $id = key( $map ) ) !== null )
		{
			$map = $this->saveNode( $stmt, $map, $siteId, $id, 1, $nleft, $nright );
			$nleft = $nright + 1; $nright = $nleft + 1;
		}

		$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 2, null, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 3, 'root' );
		$stmt->bind( 4, 'Root' );
		$stmt->bind( 5, 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 6, date( 'Y-m-d H:i:s' ) );
		$stmt->bind( 7, date( 'Y-m-d H:i:s' ) );
		$stmt->bind( 8, 'ai-migrate-multishop' );
		$stmt->bind( 9, 0, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 10, 0, \Aimeos\MW\DB\Statement\Base::PARAM_INT);
		$stmt->bind( 11, 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 12, $nright - 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 13, '{}' );

		$stmt->execute()->finish();
		$rootId = $this->getLastId( $conn, 'db-catalog' );

		$stmt = $conn->create( $update, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt->bind( 1, $rootId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 2, $rootId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 3, $siteId );
		$stmt->execute()->finish();

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-catalog' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}


	protected function saveNode( \Aimeos\MW\DB\Statement\Iface $stmt, array $map,
		string $siteId, string $id, int $level, int $nleft, int &$nright )
	{
		if( isset( $map[$id]['children'] ) )
		{
			foreach( (array) $map[$id]['children'] as $childId ) {
				++$nright;
				$map = $this->saveNode( $stmt, $map, $siteId, $childId, $level + 1, $nleft + 1, $nright );
				++$nright;
			}
		}

		$row = $map[$id];

		$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 2, $row['categories_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 3, $row['categories_id'] );
		$stmt->bind( 4, $row['categories_name'] );
		$stmt->bind( 5, $row['status'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 6, date( 'Y-m-d H:i:s', $row['date_added'] ) );
		$stmt->bind( 7, date( 'Y-m-d H:i:s', $row['last_modified'] ) );
		$stmt->bind( 8, 'ai-migrate-multishop' );
		$stmt->bind( 9, $row['parent_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 10, $level, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 11, $nleft, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 12, $nright, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
		$stmt->bind( 13, '{}' );

		$stmt->execute()->finish();

		unset( $map[$id] );
		return $map;
	}
}
