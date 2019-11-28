<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_coupons table
 */
class MultishopCouponMigrate extends \Aimeos\MW\Setup\Task\Base
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
		$this->msg( 'Migrating Multishop coupon data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-coupon' );

		$conn->create( 'START TRANSACTION' )->execute()->finish();

		$conn->create( 'DELETE FROM "mshop_coupon"' )->execute()->finish();

		$select = '
			SELECT discount, status, discount_type
			FROM tx_multishop_coupons
			GROUP BY discount,status,discount_type
		';
		$insert = '
			INSERT INTO "mshop_coupon"
			SET "siteid" = ?, "label" = ?, "provider" = ?, "config" = ?, "status" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$siteId = 1;

		$result = $msconn->create( $select )->execute();

		while( ( $row = $result->fetch() ) !== false )
		{
			switch( $row['discount_type'] )
			{
				case 'price':
					$provider = 'FixedRebate';
					$config = ['fixedrebate.productcode' => 'rebate', 'fixedrebate.rebate' => $row['discount']];
					break;
				case 'percentage':
					$provider = 'PercentRebate';
					$config = ['percentrebate.productcode' => 'rebate', 'percentrebate.rebate' => $row['discount']];
					break;
				default:
					$provider = '';
					$config = [];
			}

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['discount_type'] . '-' . $row['discount'] );
			$stmt->bind( 3, $provider );
			$stmt->bind( 4, json_encode( $config ) );
			$stmt->bind( 5, $row['status'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 6, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 7, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 8, 'ai-migrate-multishop' );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-coupon' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}
}
