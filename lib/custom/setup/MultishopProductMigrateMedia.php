<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the image data from tx_multishop_products table
 */
class MultishopProductMigrateMedia extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopProductMigrate'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop product media data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-product' );
		$conn = $this->acquire( 'db-media' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_product_list" WHERE domain=\'media\'' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_media" WHERE domain=\'product\'' )->execute()->finish();

		$select = 'SELECT * FROM "tx_multishop_products"';
		$plinsert = '
			INSERT INTO "mshop_product_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?,
				"mtime" = ?, "ctime" = ?, "editor" = ?, "config" = ?,
				"type" = \'default\', "domain" = \'media\', "status" = 1
		';
		$insert = '
			INSERT INTO "mshop_media"
			SET "siteid" = ?, "label" = ?, "link" = ?, "preview" = ?, "mimetype" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'product\', "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			foreach( ['products_image', 'products_image1', 'products_image2', 'products_image3', 'products_image4'] as $idx => $name )
			{
				if( $row[$name] )
				{
					$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$stmt->bind( 2, $row[$name] );
					$stmt->bind( 3, 'products/original/' . rtrim( substr( $row[$name], 0, 3 ), '-' ) . '/' . $row[$name] );
					$stmt->bind( 4, '{}' ); // previews
					$stmt->bind( 5, $this->getMimeType( $row[$name] ) );
					$stmt->bind( 6, date( 'Y-m-d H:i:s', $row['products_last_modified'] ?: $row['products_date_added'] ) );
					$stmt->bind( 7, date( 'Y-m-d H:i:s', $row['products_date_added'] ) );
					$stmt->bind( 8, 'ai-migrate-multishop' );

					$stmt->execute()->finish();
					$id = $this->getLastId( $conn, 'db-media' );

					$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 2, $row['products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 3, 'media|default|' . $id );
					$plstmt->bind( 4, $id );
					$plstmt->bind( 5, $idx, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 6, date( 'Y-m-d H:i:s', $row['products_last_modified'] ?: $row['products_date_added'] ) );
					$plstmt->bind( 7, date( 'Y-m-d H:i:s', $row['products_date_added'] ) );
					$plstmt->bind( 8, 'ai-migrate-multishop' );
					$plstmt->bind( 9, '{}' );

					$plstmt->execute()->finish();
				}
			}
		}

		$conn->create( 'COMMIT' )->execute()->finish();
		$pconn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-media' );
		$this->release( $pconn, 'db-product' );
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
