<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the text data from tx_multishop_catalogs_description table
 */
class MultishopCatalogMigrateText extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopCatalogMigrate'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop catalog text data', 0 );

		if( ( $langs = $this->additional->getConfig()->get( 'setup/ai-migrate-multishop/languages' ) ) === null )
		{
			throw new \Exception( '
				Configuration for required sys_language.id to two letter ISO language codes map
				is missing in "setup/ai-migrate-multishop/languages"
			' );
		}

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-catalog' );
		$conn = $this->acquire( 'db-text' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_catalog_list" WHERE domain=\'text\'' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_text" WHERE domain=\'catalog\'' )->execute()->finish();

		$select = 'SELECT * FROM tx_multishop_categories_description';
		$plinsert = '
			INSERT INTO "mshop_catalog_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'text\', "status" = 1
		';
		$insert = '
			INSERT INTO "mshop_text"
			SET "siteid" = ?, "type" = ?, "langid" = ?, "label" = ?, "content" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"domain" = \'catalog\', "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );

		$map = [
			'name' => 'categories_name', 'short' => 'shortdescription', 'long' => 'content',
			'metatitle' => 'meta_title', 'meta-keywords' => 'keywords', 'meta-description' => 'meta_description',
		];

		$siteId = 1;


		$result = $msconn->create( $select )->execute();

		while( ( $row = $result->fetch() ) !== false )
		{
			foreach( $map as $type => $colname )
			{
				if( $row[$colname] != '' )
				{
					if( !isset( $langs[$row['language_id']] ) )
					{
						$msg = 'Two letter ISO language code for sys_language ID "%1$s" is missing in "setup/ai-migrate-multishop/languages" configuration!';
						throw new \Exception( sprintf( $msg, $row['language_id'] ) );
					}

					$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$stmt->bind( 2, $type );
					$stmt->bind( 3, $langs[$row['language_id']] );
					$stmt->bind( 4, mb_strcut( strip_tags( $row[$colname] ), 0, 100 ) );
					$stmt->bind( 5, $row[$colname] );
					$stmt->bind( 6, date( 'Y-m-d H:i:s' ) );
					$stmt->bind( 7, date( 'Y-m-d H:i:s' ) );
					$stmt->bind( 8, 'ai-migrate-multishop' );

					$stmt->execute()->finish();
					$id = $this->getLastId( $conn, 'db-text' );

					$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 2, $row['categories_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 3, 'default|text|' . $id );
					$plstmt->bind( 4, $id );
					$plstmt->bind( 5, 0, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 6, date( 'Y-m-d H:i:s' ) );
					$plstmt->bind( 7, date( 'Y-m-d H:i:s' ) );
					$plstmt->bind( 8, 'ai-migrate-multishop' );

					$plstmt->execute()->finish();
				}
			}
		}

		$conn->create( 'COMMIT' )->execute()->finish();
		$pconn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-text' );
		$this->release( $pconn, 'db-catalog' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
