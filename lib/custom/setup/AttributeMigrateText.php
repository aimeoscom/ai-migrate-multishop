<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the text data from tx_multishop_products_options_values table
 */
class AttributeMigrateText extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies()
	{
		return array( 'AttributeMigrate' );
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
		$this->msg( 'Migrating Multishop attribute text data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$pconn = $this->acquire( 'db-attribute' );
		$conn = $this->acquire( 'db-text' );

		$pconn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$pconn->create( 'DELETE FROM "mshop_attribute_list" WHERE domain=\'text\'' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_text" WHERE domain=\'attribute\'' )->execute()->finish();

		$select = '
			SELECT pov.products_options_values_id, pov.products_options_values_name, l.language_isocode
			FROM tx_multishop_products_options_values pov
			JOIN sys_language l ON pov.language_id = l.uid order by products_options_values_id
			LIMIT 1000 OFFSET :offset
		';
		$plinsert = '
			INSERT INTO "mshop_attribute_list"
			SET "siteid" = ?, "parentid" = ?, "key" = ?, "refid" = ?, "pos" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'default\', "domain" = \'text\', "status" = 1
		';
		$insert = '
			INSERT INTO "mshop_text"
			SET "siteid" = ?, "langid" = ?, "label" = ?, "content" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?,
				"type" = \'name\', "domain" = \'attribute\', "status" = 1
		';

		$plstmt = $pconn->create( $plinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );

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
				if( ( $content = trim ($row['products_options_values_name'] ) ) != '' )
				{
					$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$stmt->bind( 2, $row['language_isocode'] ?? null );
					$stmt->bind( 3, mb_strcut( $content, 0, 100 ) );
					$stmt->bind( 4, $content );
					$stmt->bind( 5, date( 'Y-m-d H:i:s' ) );
					$stmt->bind( 6, date( 'Y-m-d H:i:s' ) );
					$stmt->bind( 7, 'ai-migrate-multishop' );

					$stmt->execute()->finish();
					$id = $this->getLastId( $conn, $adapter );

					$plstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 2, $row['products_options_values_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 3, 'default|text|' . $id );
					$plstmt->bind( 4, $id );
					$plstmt->bind( 5, 0, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
					$plstmt->bind( 6, date( 'Y-m-d H:i:s' ) );
					$plstmt->bind( 7, date( 'Y-m-d H:i:s' ) );
					$plstmt->bind( 8, 'ai-migrate-multishop' );

					$plstmt->execute()->finish();
				}

				$count++;
			}

			$start += $count;
		}
		while( $count > 0 );

		$conn->create( 'COMMIT' )->execute()->finish();
		$pconn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-text' );
		$this->release( $pconn, 'db-attribute' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
