<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the image data from tx_multishop_manufacturers table
 */
class MultishopSupplierMigrateMedia extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopSupplierMigrate'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop supplier media data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-supplier' );
		$conn = $this->acquire( 'db-media' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_supplier_list" WHERE domain=\'media\'' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_media" WHERE domain=\'supplier\'' )->execute()->finish();

		$select = 'SELECT * FROM "tx_multishop_manufacturers"';
		$plinsert = '
			INSERT INTO "mshop_supplier_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?,
				"mtime" = ?, "ctime" = ?, "editor" = ?, "config" = ?,
				"type" = \'default\', "domain" = \'media\', "status" = 1
		';
		$insert = '
			INSERT INTO "mshop_media"
			SET "siteid" = ?, "label" = ?, "link" = ?, "mimetype" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'supplier\', "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			if( $row['manufacturers_image'] )
			{
				$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$stmt->bind( 2, $row['manufacturers_image'] );
				$stmt->bind( 3, 'manufacturers/original/' . substr( $row['manufacturers_image'], 0, 3 ) . '/' . $row['manufacturers_image'] );
				$stmt->bind( 4, $this->getMimeType( $row['manufacturers_image'] ) );
				$stmt->bind( 5, date( 'Y-m-d H:i:s', $row['last_modified'] ?: $row['date_added'] ) );
				$stmt->bind( 6, date( 'Y-m-d H:i:s', $row['date_added'] ) );
				$stmt->bind( 7, 'ai-migrate-multishop' );

				$stmt->execute()->finish();
				$id = $this->getLastId( $conn, 'db-media' );

				$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$plstmt->bind( 2, $row['manufacturers_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$plstmt->bind( 3, 'default|media|' . $id );
				$plstmt->bind( 4, $id );
				$plstmt->bind( 5, 0, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$plstmt->bind( 6, date( 'Y-m-d H:i:s', $row['last_modified'] ?: $row['date_added'] ) );
				$plstmt->bind( 7, date( 'Y-m-d H:i:s', $row['date_added'] ) );
				$plstmt->bind( 8, 'ai-migrate-multishop' );
				$plstmt->bind( 9, '{}' );

				$plstmt->execute()->finish();
			}
		}

		$conn->create( 'COMMIT' )->execute()->finish();
		$pconn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-media' );
		$this->release( $pconn, 'db-supplier' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}


	protected function getMimeType( $path )
	{
		switch( pathinfo( $path, PATHINFO_EXTENSION ) )
		{
			case 'gif':
				return 'image/gif';
			case 'jpg':
			case 'jpeg':
				return 'image/jpeg';
			case 'png':
				return 'image/png';
			case 'tif':
			case 'tiff':
				return 'image/tiff';
		}

		return '';
	}
}
