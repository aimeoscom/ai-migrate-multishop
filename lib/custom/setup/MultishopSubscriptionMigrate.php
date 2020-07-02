<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the subscription data from tx_multishop_subscriptions table
 */
class MultishopSubscriptionMigrate extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopOrderMigrate', 'MultishopOrderMigrateProduct'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop subscription data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-order' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_subscription"' )->execute()->finish();

		$select = 'SELECT * FROM "tx_multishop_subscriptions"';
		$insert = '
			INSERT INTO "mshop_subscription"
			SET "id" = ?, "siteid" = ?, "baseid" = ?, "ordprodid" = ?, "productid" = ?,
				"next" = ?, "end" = ?, "interval" = ?, "reason" = ?, "period" = ?, "status" = ?,
				"ctime" = ?, "mtime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			$stmt->bind( 1, $row['subscriptions_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $row['orders_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 4, $row['orders_products_id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 5, $row['products_id'] );
			$stmt->bind( 6, date( 'Y-m-d H:i:s', $row['next_invoice'] ) );
			$stmt->bind( 7, $row['expiry_date'] != 2145916800 ? date( 'Y-m-d H:i:s', $row['expiry_date'] ) : null );
			$stmt->bind( 8, $this->period( $row ) );
			$stmt->bind( 9, $this->reason( $row ), \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 10, $row['times_cycled'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 11, $this->stat( $row ), \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 12, date( 'Y-m-d H:i:s', $row['crdate'] ) );
			$stmt->bind( 13, date( 'Y-m-d H:i:s', $row['last_invoiced'] ?: $row['crdate'] ) );
			$stmt->bind( 14, 'ai-multishop-migrate' );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-order' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}


	protected function period( array $row) : string
	{
		switch( $row['period_unit'] )
		{
			case 'mm': return 'P0Y' . $row['period'] . 'M0W0D';
		}

		throw new \RuntimeException( sprintf( 'Unknown subscription period "%1$s"', $value ) );
	}


	protected function reason( array $row ) : ?int
	{
		if( $row['expired'] ) {
			return \Aimeos\MShop\Subscription\Item\Iface::REASON_END;
		}

		if( $row['cancelled_date'] > time() ) {
			return \Aimeos\MShop\Subscription\Item\Iface::REASON_CANCEL;
		}

		return null;
	}


	protected function stat( array $row ) : int
	{
		return $row['deleted'] == 0 && $row['disable'] == 0 && $row['expired'] == 0;
	}
}
