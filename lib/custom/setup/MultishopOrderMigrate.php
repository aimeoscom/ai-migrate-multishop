<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_orders table
 */
class MultishopOrderMigrate extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopOrderMigrateBase'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop order data', 0 );

		if( ( $langs = $this->additional->getConfig()->get( 'setup/ai-migrate-multishop/languages' ) ) === null )
		{
			throw new \Exception( '
				Configuration for required sys_language.id to two letter ISO language codes map
				is missing in "setup/ai-migrate-multishop/languages"
			' );
		}

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_order"' )->execute()->finish();

		$select = '
			SELECT o.*, f."username"
			FROM "tx_multishop_orders" o
			LEFT JOIN "fe_users" f ON o."customer_id" = f."uid"
		';
		$insert = '
			INSERT INTO "mshop_order"
			SET "siteid" = ?, "id" = ?, "baseid" = ?, "type" = ?, "datepayment" = ?, "statuspayment" = ?,
				"datedelivery" = ?, "statusdelivery" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
				"cdate" = ?, "cmonth" = ?, "cweek" = ?, "cwday" = ?, "chour" = ?
		';
		$sinsert = '
			INSERT INTO "mshop_order_status"
			SET "siteid" = ?, "parentid" = ?, "type" = ?, "value" = ?, "ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$taxFlag = $this->additional->getConfig()->get( 'mshop/price/taxflag', 1 );
		$sstmt = $conn->create( $sinsert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteCode = 'default';
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			if( !isset( $langs[$row['language_id']] ) )
			{
				$msg = 'Two letter ISO language code for sys_language ID "%1$s" is missing in "setup/ai-migrate-multishop/languages" configuration!';
				throw new \Exception( sprintf( $msg, $row['language_id'] ) );
			}

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 4, $row['by_phone'] ? 'phone' : 'web' );
			$stmt->bind( 5, $row['orders_paid_timestamp'] ? date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ): null );
			$stmt->bind( 6, $this->statuspayment( $row ), \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 7, null ); // delivery date
			$stmt->bind( 8, $this->statusdelivery( $row ), \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 9, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 10, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$stmt->bind( 11, $row['username'] ?: $row['ip_address'] );
			$stmt->bind( 12, date( 'Y-m-d', $row['crdate'] ) );
			$stmt->bind( 13, date( 'Y-m', $row['crdate'] ) );
			$stmt->bind( 14, date( 'Y-W', $row['crdate'] ) );
			$stmt->bind( 15, date( 'w', $row['crdate'] ) );
			$stmt->bind( 16, date( 'H', $row['crdate'] ) );

			$stmt->execute()->finish();

			$sstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$sstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$sstmt->bind( 3, 'stock-update' );
			$sstmt->bind( 4, 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$sstmt->bind( 5, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$sstmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_last_modified'] ) );
			$sstmt->bind( 7, $row['username'] ?: $row['ip_address'] );

			$sstmt->execute()->finish();

			if( $row['paid'] > 0 )
			{
				$sstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 3, 'status-payment' );
				$sstmt->bind( 4, \Aimeos\MShop\Order\Item\Base::PAY_RECEIVED, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 5, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['crdate'] ) );
				$sstmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['orders_last_modified'] ) );
				$sstmt->bind( 7, $row['username'] ?: $row['ip_address'] );

				$sstmt->execute()->finish();

				$sstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 3, 'email-payment' );
				$sstmt->bind( 4, \Aimeos\MShop\Order\Item\Base::PAY_RECEIVED, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 5, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['crdate'] ) );
				$sstmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['orders_last_modified'] ) );
				$sstmt->bind( 7, $row['username'] ?: $row['ip_address'] );

				$sstmt->execute()->finish();
			}

			if( $row['bill'] > 0 )
			{
				$sstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 3, 'status-payment' );
				$sstmt->bind( 4, \Aimeos\MShop\Order\Item\Base::PAY_PENDING, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 5, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['crdate'] ) );
				$sstmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['orders_last_modified'] ) );
				$sstmt->bind( 7, $row['username'] ?: $row['ip_address'] );

				$sstmt->execute()->finish();

				$sstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 3, 'email-payment' );
				$sstmt->bind( 4, \Aimeos\MShop\Order\Item\Base::PAY_PENDING, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 5, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['crdate'] ) );
				$sstmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['orders_last_modified'] ) );
				$sstmt->bind( 7, $row['username'] ?: $row['ip_address'] );

				$sstmt->execute()->finish();
			}

			if( $row['deleted'] > 0 )
			{
				$sstmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 2, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 3, 'status-payment' );
				$sstmt->bind( 4, \Aimeos\MShop\Order\Item\Base::PAY_DELETED, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
				$sstmt->bind( 5, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['crdate'] ) );
				$sstmt->bind( 6, date( 'Y-m-d H:i:s', $row['orders_paid_timestamp'] ?: $row['orders_last_modified'] ) );
				$sstmt->bind( 7, $row['username'] ?: $row['ip_address'] );

				$sstmt->execute()->finish();
			}
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-order' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}


	protected function statusdelivery( array $row ) : int
	{
		switch( $row['status'] )
		{
			case 2: return \Aimeos\MShop\Order\Item\Base::STAT_PROGRESS;
			case 3: return \Aimeos\MShop\Order\Item\Base::STAT_DELETED;
			case 4: return \Aimeos\MShop\Order\Item\Base::STAT_DELIVERED;
			case 5: return \Aimeos\MShop\Order\Item\Base::STAT_DISPATCHED;
		}

		return \Aimeos\MShop\Order\Item\Base::STAT_UNFINISHED;
	}


	protected function statuspayment( array $row ) : int
	{
		if( $row['deleted'] ) {
			return \Aimeos\MShop\Order\Item\Base::PAY_DELETED;
		}

		if( !$row['paid'] && $row['bill'] ) {
			return \Aimeos\MShop\Order\Item\Base::PAY_PENDING;
		}

		if( $row['paid'] ) {
			return \Aimeos\MShop\Order\Item\Base::PAY_RECEIVED;
		}

		return \Aimeos\MShop\Order\Item\Base::PAY_UNFINISHED;
	}
}
