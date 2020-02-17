<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2019
 */


namespace Aimeos\MW\Setup\Task;


/**
 * Migrates the base data from tx_multishop_coupons table
 */
class MultishopCouponMigrateCode extends \Aimeos\MW\Setup\Task\Base
{
	/**
	 * Returns the list of task names which this task depends on.
	 *
	 * @return string[] List of task names
	 */
	public function getPreDependencies() : array
	{
		return ['MultishopCouponMigrate'];
	}


	/**
	 * Migrate database schema
	 */
	public function migrate()
	{
		$this->msg( 'Migrating Multishop coupon code data', 0 );

		$msconn = $this->acquire( 'db-multishop' );
		$conn = $this->acquire( 'db-coupon' );

		$map = $this->getCouponMap( $conn );

		$conn->create( 'START TRANSACTION' )->execute()->finish();
		$conn->create( 'DELETE FROM "mshop_coupon_code"' )->execute()->finish();

		$select = 'SELECT * FROM tx_multishop_coupons WHERE code <> \'\'';
		$insert = '
			INSERT INTO "mshop_coupon_code"
			SET "id" = ?, "parentid" = ?, "siteid" = ?, "code" = ?, "count" = ?,
				"start" = ?, "end" = ?, "mtime" = ?, "ctime" = ?, "editor" = ?
		';

		$stmt = $conn->create( $insert, \Aimeos\MW\DB\Connection\Base::TYPE_PREP );
		$result = $msconn->create( $select )->execute();
		$siteId = 1;

		while( $row = $result->fetch() )
		{
			if( !isset( $map[$row['status'] . '-' . $row['discount_type'] . '-' . $row['discount']] ) ) {
				continue;
			}

			$parentId = $map[$row['status'] . '-' . $row['discount_type'] . '-' . $row['discount']];

			$stmt->bind( 1, $row['id'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 2, $parentId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 3, $siteId, \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 4, $row['code'] );
			$stmt->bind( 5, $row['max_usage'] - $row['times_used'], \Aimeos\MW\DB\Statement\Base::PARAM_INT );
			$stmt->bind( 6, $row['startdate'] ? date( 'Y-m-d H:i:s', $row['startdate'] ) : null );
			$stmt->bind( 7, $row['enddate'] ? date( 'Y-m-d H:i:s', $row['enddate'] ) : null );
			$stmt->bind( 8, date( 'Y-m-d H:i:s' ) );
			$stmt->bind( 9, date( 'Y-m-d H:i:s', $row['crdate'] ?: time() ) );
			$stmt->bind( 10, 'ai-migrate-multishop' );

			$stmt->execute()->finish();
		}

		$conn->create( 'COMMIT' )->execute()->finish();

		$this->release( $conn, 'db-coupon' );
		$this->release( $msconn, 'db-multishop' );

		$this->status( 'done' );
	}


	protected function getCouponMap( \Aimeos\MW\DB\Connection\Iface $conn )
	{
		$map = [];
		$stmt = $conn->create( 'SELECT "id", "label", "status" FROM "mshop_coupon"' )->execute();

		while( ( $row = $stmt->fetch() ) !== false ) {
			$map[$row['status'] . '-' . $row['label']] = $row['id'];
		}

		return $map;
	}
}
