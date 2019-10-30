<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the image data from tx_multishop_products table
 */
class ProductMigrateMedia extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'ProductMigrate' );
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
		$this->msg( 'Migrating product media data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-product' );
		$conn = $this->acquire( 'db-media' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_product_list" WHERE domain=\'media\'' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_media" WHERE domain=\'product\'' )->execute()->finish();

		$select = '
			SELECT *
			FROM "tx_multishop_products"
			LIMIT 1000 OFFSET :offset
		';
		$plinsert = '
			INSERT INTO "mshop_product_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'media\', "status" = 1
		';
		$insert = '
			INSERT INTO "mshop_media"
			SET "siteid" = ?, "label" = ?, "link" = ?, "mimetype" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'product\', "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );

		$adapter = $this->getSchema( 'db-media' )->getName();
		$siteId = 1;
		$start = 0;

		do
		{
			$count = 0;
			$sql = str_replace( ':offset', $start, $select );
			$result = $msconn->create( $sql )->execute();

			while( ( $row = $result->fetch() ) !== false )
			{
				foreach( ['products_image', 'products_image1', 'products_image2', 'products_image3', 'products_image4'] as $idx => $name )
				{
					if( $row[$name] )
					{
						$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
						$stmt->bind( 2, $row[$name] );
						$stmt->bind( 3, $row[$name] );
						$stmt->bind( 4, $this->getMimeType( $row[$name] ) );
						$stmt->bind( 5, date( 'Y-m-d H:i:s', $row['products_last_modified'] ?: $row['products_date_added'] ) );
						$stmt->bind( 6, date( 'Y-m-d H:i:s', $row['products_date_added'] ) );
						$stmt->bind( 7, 'ai-migrate-multishop' );

						$stmt->execute()->finish();
						$id = $this->getLastId( $conn, $adapter );

						$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
						$plstmt->bind( 2, $row['products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
						$plstmt->bind( 3, 'default|media|' . $id );
						$plstmt->bind( 4, $id );
						$plstmt->bind( 5, $idx, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
						$plstmt->bind( 6, date( 'Y-m-d H:i:s', $row['products_last_modified'] ?: $row['products_date_added'] ) );
						$plstmt->bind( 7, date( 'Y-m-d H:i:s', $row['products_date_added'] ) );
						$plstmt->bind( 8, 'ai-migrate-multishop' );

						$plstmt->execute()->finish();
					}
				}

				$count++;
			}

			$start += $count;
		}
		while( $count > 0 );

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
