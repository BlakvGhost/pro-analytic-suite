<?php
/**
 * WordPress user export for BigQuery sync.
 *
 * @package Analytic_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads WordPress users and their profile meta for export.
 */
class Analytic_Suite_User_Repository {

	/**
	 * Returns paginated user rows enriched with profile meta.
	 *
	 * @param array $filters { updated_after: string, per_page: int, page: int }
	 * @return array { total: int, rows: array }
	 */
	public function get_export_rows( $filters ) {
		$per_page = min( 500, max( 1, (int) ( $filters['per_page'] ?? 200 ) ) );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );

		$args = array(
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'registered',
			'order'   => 'ASC',
			'fields'  => 'all',
		);

		if ( ! empty( $filters['updated_after'] ) ) {
			$args['date_query'] = array(
				array(
					'column'    => 'user_registered',
					'after'     => $filters['updated_after'],
					'inclusive' => false,
				),
			);
		}

		$users = get_users( $args );

		$count_args                 = $args;
		$count_args['number']       = -1;
		$count_args['count_total']  = true;
		unset( $count_args['offset'], $count_args['fields'] );
		$count_args['fields'] = 'ids';

		$total = count( get_users( $count_args ) );

		$rows = array();

		foreach ( $users as $user ) {
			$rows[] = array(
				'user_id'           => $user->ID,
				'user_email'        => $user->user_email,
				'user_display_name' => $user->display_name,
				'registered_at'     => $user->user_registered
					? gmdate( 'c', strtotime( $user->user_registered ) )
					: null,
				'experience'        => get_user_meta( $user->ID, 'field_experience', true ) ?: null,
				'gender'            => get_user_meta( $user->ID, 'genders', true ) ?: null,
				'disability'        => get_user_meta( $user->ID, 'handicap', true ) ?: null,
				'last_login'        => get_user_meta( $user->ID, 'last_login', true ) ?: null,
			);
		}

		return array( 'total' => $total, 'rows' => $rows );
	}
}
