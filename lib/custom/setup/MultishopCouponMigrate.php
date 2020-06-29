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
			SELECT c.discount, c.discount_type, GROUP_CONCAT(ctp.products_id) AS prodids
			FROM tx_multishop_coupons c
			LEFT JOIN tx_multishop_coupon_codes_to_products ctp ON c.id=ctp.coupons_id
			WHERE c.status = 1 AND c.enddate > UNIX_TIMESTAMP() AND c.times_used <> max_usage
			GROUP BY c.id
		';
		$insert = '
			INSERT INTO "mshop_coupon"
			SET "siteid" = ?, "label" = ?, "provider" = ?, "config" = ?, "status" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			if( $row['discount'] == 0 ){
				continue;
			}

			switch( $row['discount_type'] )
			{
				case 'price':
					$provider = 'FixedRebate';
					$config = ['fixedrebate.productcode' => 'rebate', 'fixedrebate.rebate' => ['EUR' => $row['discount']]];
					break;
				case 'percentage':
					$provider = 'PercentRebate';
					$config = ['percentrebate.productcode' => 'rebate', 'percentrebate.rebate' => $row['discount']];
					break;
				default:
					continue 2;
			}

			if( $row['prodids'] )
			{
				$provider .= ',Required';
				$config['required.only'] = 1;
				$config['required.productcode'] = $row['prodids'];
			}

			$stmt->bind( 1, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $row['discount_type'] . '-' . $row['discount'] . ':' . $row['prodids'] );
			$stmt->bind( 3, $provider );
			$stmt->bind( 4, json_encode( $config ) );
			$stmt->bind( 5, 1, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
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
