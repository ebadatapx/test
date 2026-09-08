<?php
/**
 * Model class for managing Feed Sources database operations.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

namespace RSSFeedManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class SourceModel
 *
 * Performs CRUD operations on the `rss_sources` table.
 */
class SourceModel {

	/**
	 * Get the table name with WordPress prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'rss_sources';
	}

	/**
	 * Insert a new feed source.
	 *
	 * @param array $data Array of data containing feed_name, feed_url, and status.
	 * @return int|false Inserted ID or false on failure.
	 */
	public function insert( $data ) {
		global $wpdb;

		$table = self::get_table_name();
		$now   = current_time( 'mysql' );

		$insert_data = [
			'feed_name'   => sanitize_text_field( $data['feed_name'] ),
			'feed_url'    => esc_url_raw( $data['feed_url'] ),
			'status'      => isset( $data['status'] ) && 'inactive' === $data['status'] ? 'inactive' : 'active',
			'category_id' => isset( $data['category_id'] ) ? absint( $data['category_id'] ) : 0,
			'last_sync'   => null,
			'created_at'  => $now,
			'updated_at'  => $now,
		];

		$formats = [ '%s', '%s', '%s', '%d', null, '%s', '%s' ];

		$result = $wpdb->insert( $table, $insert_data, $formats );

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Update a feed source.
	 *
	 * @param int   $id   Source ID.
	 * @param array $data Data to update.
	 * @return bool True on success, false on failure.
	 */
	public function update( $id, $data ) {
		global $wpdb;

		$table = self::get_table_name();
		$now   = current_time( 'mysql' );

		$update_data = [];
		$formats     = [];

		if ( isset( $data['feed_name'] ) ) {
			$update_data['feed_name'] = sanitize_text_field( $data['feed_name'] );
			$formats[]                = '%s';
		}

		if ( isset( $data['feed_url'] ) ) {
			$update_data['feed_url'] = esc_url_raw( $data['feed_url'] );
			$formats[]               = '%s';
		}

		if ( isset( $data['status'] ) ) {
			$update_data['status'] = in_array( $data['status'], [ 'active', 'inactive' ], true ) ? $data['status'] : 'active';
			$formats[]             = '%s';
		}

		if ( isset( $data['category_id'] ) ) {
			$update_data['category_id'] = absint( $data['category_id'] );
			$formats[]                  = '%d';
		}

		if ( isset( $data['last_sync'] ) ) {
			$update_data['last_sync'] = sanitize_text_field( $data['last_sync'] );
			$formats[]                = '%s';
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		$update_data['updated_at'] = $now;
		$formats[]                 = '%s';

		$result = $wpdb->update(
			$table,
			$update_data,
			[ 'id' => absint( $id ) ],
			$formats,
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Delete a feed source.
	 *
	 * @param int $id Source ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $id ) {
		global $wpdb;

		$table  = self::get_table_name();
		$result = $wpdb->delete( $table, [ 'id' => absint( $id ) ], [ '%d' ] );

		return false !== $result;
	}

	/**
	 * Get a feed source by ID.
	 *
	 * @param int $id Source ID.
	 * @return object|null Database row object or null if not found.
	 */
	public function get_by_id( $id ) {
		global $wpdb;

		$table = self::get_table_name();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) )
		);
	}

	/**
	 * Get all feed sources.
	 *
	 * @param array $args Optional query args (status, order, etc.).
	 * @return array Array of database objects.
	 */
	public function get_all( $args = [] ) {
		global $wpdb;

		$table = self::get_table_name();
		$where = ' WHERE 1=1';
		$binds = [];

		if ( isset( $args['status'] ) && in_array( $args['status'], [ 'active', 'inactive' ], true ) ) {
			$where  .= ' AND status = %s';
			$binds[] = $args['status'];
		}

		$orderby = 'id';
		if ( isset( $args['orderby'] ) && in_array( $args['orderby'], [ 'id', 'feed_name', 'status', 'last_sync' ], true ) ) {
			$orderby = $args['orderby'];
		}

		$order = 'DESC';
		if ( isset( $args['order'] ) && in_array( strtoupper( $args['order'] ), [ 'ASC', 'DESC' ], true ) ) {
			$order = strtoupper( $args['order'] );
		}

		$query = "SELECT * FROM {$table}{$where} ORDER BY {$orderby} {$order}";

		if ( ! empty( $binds ) ) {
			return $wpdb->get_results( $wpdb->prepare( $query, $binds ) );
		}

		return $wpdb->get_results( $query );
	}

	/**
	 * Resolve the category a sync log row belongs to.
	 *
	 * Prefers the snapshot stored on the log row, falling back to the source's current
	 * category for rows written before that column existed. Resolving via source ID
	 * rather than feed name matters because two sources may share the same name.
	 *
	 * @param object $log Sync log row.
	 * @return array|null [ 'id' => int, 'name' => string ], or null when uncategorised.
	 */
	public static function resolve_log_category( $log ) {
		$category_id = isset( $log->category_id ) ? (int) $log->category_id : 0;

		if ( ! $category_id && ! empty( $log->source_id ) ) {
			$model  = new self();
			$source = $model->get_by_id( $log->source_id );
			if ( $source && ! empty( $source->category_id ) ) {
				$category_id = (int) $source->category_id;
			}
		}

		if ( ! $category_id ) {
			return null;
		}

		$name = get_cat_name( $category_id );

		if ( ! $name ) {
			return null; // Category was deleted after the sync ran.
		}

		return [
			'id'   => $category_id,
			'name' => $name,
		];
	}

	/**
	 * Get total imported posts count for a specific feed source.
	 *
	 * @param int $source_id Feed source ID.
	 * @return int Post count.
	 */
	public function get_imported_posts_count( $source_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) 
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type = 'post' 
				 AND pm.meta_key = '_source_feed' 
				 AND pm.meta_value = %d",
				absint( $source_id )
			)
		);
	}
}
