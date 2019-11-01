<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the text data from tx_multishop_products_description table
 */
class ProductMigrateText extends \Aimeos\MW\Setup\Task\Base
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
		$this->msg( 'Migrating Multishop product text data', 0 );

		if( ( $langs = $this->additional->getConfig()->get( 'setup/ai-migrate-multishop/languages' ) ) === null )
		{
			throw new \Exception( '
				Configuration for required sys_language.id to two letter ISO language codes map
				is missing in "setup/ai-migrate-multishop/languages"
			' );
		}

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-product' );
		$conn = $this->acquire( 'db-text' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_product_list" WHERE domain=\'text\'' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_text" WHERE domain=\'product\'' )->execute()->finish();

		$select = 'SELECT * FROM "tx_multishop_products_description" LIMIT 1000 OFFSET :offset';
		$plinsert = '
			INSERT INTO "mshop_product_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'text\', "status" = 1
		';
		$insert = '
			INSERT INTO "mshop_text"
			SET "siteid" = ?, "type" = ?, "langid" = ?, "label" = ?, "content" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"domain" = \'product\', "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );

		$map = [
			'name' => 'products_name', 'short' => 'products_shortdescription', 'long' => 'products_description',
			'metatitle' => 'products_meta_title', 'meta-keywords' => 'products_meta_keywords',
			'meta-description' => 'products_meta_description',
		];

		$defText = '<p><br dir="ltr" spellcheck="false" id="tinymce" class="mceContentBody "></p>';
		$adapter = $this->getSchema( 'db-text' )->getName();
		$siteId = 1;
		$start = 0;

		do
		{
			$count = 0;
			$sql = str_replace( ':offset', $start, $select );
			$result = $msconn->create( $sql )->execute();

			while( ( $row = $result->fetch() ) !== false )
			{
				foreach( $map as $type => $colname )
				{
					if( $row[$colname] && $row[$colname] !== $defText )
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
						$id = $this->getLastId( $conn, $adapter );

						$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
						$plstmt->bind( 2, $row['products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
						$plstmt->bind( 3, 'default|text|' . $id );
						$plstmt->bind( 4, $id );
						$plstmt->bind( 5, 0, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
						$plstmt->bind( 6, date( 'Y-m-d H:i:s' ) );
						$plstmt->bind( 7, date( 'Y-m-d H:i:s' ) );
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

		$this->release( $conn, 'db-text' );
		$this->release( $pconn, 'db-product' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
